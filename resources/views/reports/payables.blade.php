@extends('layouts.app')

@section('title', 'Payables')

@php
    /*
    | The mirror of receivables: what the shop owes.
    |
    | One honest caveat runs through this page. The balance comes from
    | SupplierLedgerService, which derives it from five kinds of row and is the
    | only thing allowed to decide it. The ageing cannot be a split of that
    | balance, because supplier payments are made against the account as a whole
    | rather than against an invoice - so the bands show the unpaid part of each
    | delivery by its own age, as a guide to the oldest exposure. The page says
    | so rather than implying a precision it does not have.
    */
    $supplier = term('supplier');
    $suppliers = term('supplier', true);
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));

    $owing = $rows->where('balance', '>', 0);
    $inCredit = $rows->where('balance', '<', 0);
    $unsettled = round($totals['current'] + $totals['1_30'] + $totals['31_60'] + $totals['over_60'], 2);
@endphp

@section('content')
    <x-page-header title="Payables"
                   :subtitle="'What the shop owes ' . Str::lower($suppliers) . ', as at ' . now()->format('j M Y')" />

    @include('reports.partials.filter-bar', [
        'note' => 'Only received deliveries count as a debt. An order that has not arrived is shown separately: it is money coming, not money owed.',
    ])

    <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat label="Owed in total" :value="$amount($totals['balance'])" icon="coins"
                :hint="number_format($owing->count()) . ' ' . Str::lower(Str::plural($supplier, $owing->count())) . ' to pay'" />
        <x-stat label="On order" :value="$amount($totals['on_order'])" icon="truck" hint="not delivered yet" />
        <x-stat label="Unpaid on delivery" :value="$amount($unsettled)" icon="receipt" hint="before account payments" />
        <x-stat label="Oldest exposure" :value="$amount($totals['over_60'])" icon="alert-triangle"
                hint="deliveries over 60 days old" :invert="true"
                :trend="$totals['over_60'] > 0 ? 'up' : null" />
    </div>

    @if ($rows->isEmpty())
        <x-card class="mt-4">
            <x-empty icon="building" title="Nothing owed to anyone"
                     :message="'Received deliveries that are not fully paid for, and orders still to arrive, appear here.'">
                <x-slot:action>
                    <a href="{{ route('suppliers.index') }}"
                       class="inline-flex min-h-touch items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                        {{ $suppliers }}
                    </a>
                </x-slot:action>
            </x-empty>
        </x-card>
    @else
        @if ($unsettled > 0)
            <x-card title="How old the unpaid deliveries are"
                    subtitle="By invoice date, before any payment made against the account as a whole"
                    class="mt-4">
                <div class="h-64">
                    <canvas id="payables-ageing" role="img"
                            aria-label="Unpaid deliveries split into age bands by invoice date"></canvas>
                </div>

                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                    @foreach ($buckets as $key => $label)
                        {{ $label }} <span class="font-semibold">{{ $amount($totals[$key]) }}</span>@if (!$loop->last) &middot; @endif
                    @endforeach
                </p>
            </x-card>
        @endif

        <x-card :padding="false" class="mt-4">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>{{ $supplier }}</th>
                        <th class="text-right">Balance</th>
                        <th class="text-right">On order</th>
                        @foreach ($buckets as $label)
                            <th class="text-right">{{ $label }}</th>
                        @endforeach
                    </tr>
                </x-slot:head>

                @foreach ($rows as $row)
                    <tr>
                        <td>
                            <a href="{{ route('suppliers.show', $row['supplier']) }}"
                               class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                {{ $row['supplier']->name }}
                            </a>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                {{ $row['supplier']->phone ?: 'no phone number' }}
                            </span>
                        </td>
                        <td class="text-right font-semibold tabular-nums">
                            @if ($row['balance'] < 0)
                                {{-- Paid up front, or returned more than was owed. --}}
                                <span class="text-green-700 dark:text-green-400">
                                    {{ $amount(abs($row['balance'])) }} in credit
                                </span>
                            @else
                                {{ $amount($row['balance']) }}
                            @endif
                        </td>
                        <td class="text-right tabular-nums text-gray-500 dark:text-gray-400">
                            {{ $row['on_order'] > 0 ? $amount($row['on_order']) : '-' }}
                        </td>
                        @foreach (array_keys($buckets) as $key)
                            <td class="text-right tabular-nums {{ $key === 'over_60' && $row['buckets'][$key] > 0 ? 'text-red-700 dark:text-red-400' : '' }}">
                                {{ $row['buckets'][$key] > 0 ? $amount($row['buckets'][$key]) : '-' }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach

                <x-slot:foot>
                    <tr>
                        <td>{{ number_format($rows->count()) }} {{ Str::lower(Str::plural($supplier, $rows->count())) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($totals['balance']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($totals['on_order']) }}</td>
                        @foreach (array_keys($buckets) as $key)
                            <td class="text-right tabular-nums">{{ $amount($totals[$key]) }}</td>
                        @endforeach
                    </tr>
                </x-slot:foot>
            </x-table>

            @if ($inCredit->isNotEmpty())
                <x-slot:footer>
                    {{ $inCredit->count() }}
                    {{ Str::lower(Str::plural($supplier, $inCredit->count())) }}
                    {{ $inCredit->count() === 1 ? 'is' : 'are' }} in credit - paid up front, or credited
                    back more than was owed.
                </x-slot:footer>
            @endif
        </x-card>
    @endif
@endsection

@push('scripts')
    @include('reports.partials.charts')

    <script>
        spChart('payables-ageing', (t) => ({
            type: 'bar',
            data: {
                labels: @json(array_values($buckets)),
                datasets: [{
                    ...spBar(t, 'Unpaid on delivery', @json(array_map(fn($key) => $totals[$key], array_keys($buckets))), t.series[0]),
                    // Ordered bands: one hue, light to dark.
                    backgroundColor: t.ordinal,
                }],
            },
            options: spAxes(t),
        }));
    </script>
@endpush
