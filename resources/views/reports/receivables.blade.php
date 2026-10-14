@extends('layouts.app')

@section('title', 'Receivables')

@php
    /*
    | Who owes the shop money, and how long they have owed it.
    |
    | No date range: a debt is a figure about now, not about a period. The window
    | that matters is the ageing - and a debt is aged from its due date, or from
    | the invoice date when no terms were agreed, because an invoice with no
    | terms was payable on the day and should not sit in "not yet due" forever.
    */
    $customer = term('customer');
    $customers = term('customer', true);
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));

    $overdueTotal = round($totals['1_30'] + $totals['31_60'] + $totals['over_60'], 2);
@endphp

@section('content')
    <x-page-header title="Receivables"
                   :subtitle="'What ' . Str::lower($customers) . ' owe, as at ' . now()->format('j M Y')" />

    @include('reports.partials.filter-bar', [
        'fields' => [
            [
                'name' => 'overdue_only',
                'label' => 'Show',
                'options' => ['0' => 'Everyone who owes', '1' => 'Past due only'],
                'value' => $overdueOnly ? '1' : '0',
            ],
        ],
        'note' => 'Aged from the agreed due date, or from the invoice date where none was set.',
    ])

    <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat label="Owed in total" :value="$amount($totals['owed'])" icon="coins"
                :hint="number_format($rows->count()) . ' ' . Str::lower(Str::plural($customer, $rows->count()))" />
        <x-stat label="Not yet due" :value="$amount($totals['current'])" icon="clock" />
        <x-stat label="Past due" :value="$amount($overdueTotal)" icon="alert-triangle"
                :delta="$totals['owed'] > 0 ? round($overdueTotal / $totals['owed'] * 100) . '% of the book' : null"
                :trend="$overdueTotal > 0 ? 'up' : null" invert />
        <x-stat label="Over 60 days" :value="$amount($totals['over_60'])" icon="trend-down"
                hint="the hardest to collect" />
    </div>

    @if ($rows->isEmpty())
        <x-card class="mt-4">
            <x-empty icon="check-circle"
                     :title="$overdueOnly ? 'Nothing is past due' : 'Nobody owes anything'"
                     :message="$overdueOnly
                        ? 'Every outstanding invoice is still within its terms.'
                        : 'Sales taken on credit, and what is left to collect on them, appear here.'">
                <x-slot:action>
                    <a href="{{ route('customers.index') }}"
                       class="inline-flex min-h-touch items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                        {{ $customers }}
                    </a>
                </x-slot:action>
            </x-empty>
        </x-card>
    @else
        <x-card title="How old the debt is" subtitle="Ordered bands: the further right, the harder to collect"
                class="mt-4">
            <div class="h-64">
                <canvas id="ageing" role="img"
                        aria-label="Outstanding balances split into age bands"></canvas>
            </div>

            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                @foreach ($buckets as $key => $label)
                    {{ $label }} <span class="font-semibold">{{ $amount($totals[$key]) }}</span>@if (!$loop->last) &middot; @endif
                @endforeach
            </p>
        </x-card>

        <x-card :padding="false" class="mt-4">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>{{ $customer }}</th>
                        <th class="text-right">Owed</th>
                        @foreach ($buckets as $label)
                            <th class="text-right">{{ $label }}</th>
                        @endforeach
                        <th class="text-right">Credit limit</th>
                    </tr>
                </x-slot:head>

                @foreach ($rows as $row)
                    <tr>
                        <td>
                            <a href="{{ route('customers.show', $row['customer']) }}"
                               class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                {{ $row['customer']->name }}
                            </a>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                {{ $row['customer']->phone ?: 'no phone number' }}
                                @if ($row['overdue'])
                                    &middot; <span class="font-medium text-red-700 dark:text-red-400">past due</span>
                                @endif
                            </span>
                        </td>
                        <td class="text-right font-semibold tabular-nums">{{ $amount($row['owed']) }}</td>
                        @foreach (array_keys($buckets) as $key)
                            <td class="text-right tabular-nums {{ $key === 'over_60' && $row['buckets'][$key] > 0 ? 'text-red-700 dark:text-red-400' : '' }}">
                                {{ $row['buckets'][$key] > 0 ? $amount($row['buckets'][$key]) : '-' }}
                            </td>
                        @endforeach
                        <td class="text-right tabular-nums text-gray-500 dark:text-gray-400">
                            {{-- 0 means "no limit": a shop need not set one. --}}
                            {{ (float) $row['customer']->credit_limit > 0 ? $amount($row['customer']->credit_limit) : 'none' }}
                        </td>
                    </tr>
                @endforeach

                <x-slot:foot>
                    <tr>
                        <td>{{ number_format($rows->count()) }} {{ Str::lower(Str::plural($customer, $rows->count())) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($rows->sum('owed')) }}</td>
                        @foreach (array_keys($buckets) as $key)
                            <td class="text-right tabular-nums">
                                {{ $amount($rows->sum(fn($row) => $row['buckets'][$key])) }}
                            </td>
                        @endforeach
                        <td></td>
                    </tr>
                </x-slot:foot>
            </x-table>
        </x-card>
    @endif
@endsection

@push('scripts')
    @include('reports.partials.charts')

    <script>
        /*
        | Ordered categories, so an ordinal ramp: one hue, light to dark, with
        | "the darker the older" doing the talking. A categorical palette here
        | would say the bands are unrelated, which is the opposite of true.
        */
        spChart('ageing', (t) => ({
            type: 'bar',
            data: {
                labels: @json(array_values($buckets)),
                datasets: [{
                    ...spBar(t, 'Owed', @json(array_map(fn($key) => $totals[$key], array_keys($buckets))), t.series[0]),
                    backgroundColor: t.ordinal,
                }],
            },
            options: spAxes(t),
        }));
    </script>
@endpush
