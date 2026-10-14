@extends('layouts.app')

@section('title', 'Takings by ' . term('cashier'))

@php
    /*
    | Staff performance, and the discount column that is the real reason this
    | report exists: a cashier knocking something off every sale is invisible
    | everywhere else in the app.
    */
    $cashier = term('cashier');
    $cashiers = term('cashier', true);
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));
@endphp

@section('content')
    <x-page-header :title="'Takings by ' . Str::lower($cashier)"
                   subtitle="Who took what, and what they gave away" />

    @include('reports.partials.filter-bar', [
        'startDate' => $startDate,
        'endDate' => $endDate,
        'note' => 'Takings are net of refunds given back on that person\'s own sales. "Refunds processed" counts the refunds they put through, whoever made the original sale.',
    ])

    <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat label="Takings" :value="$amount($totals['takings'])" icon="coins" hint="net of refunds" />
        <x-stat label="{{ term('sale', true) }}" :value="number_format($totals['transactions'])" icon="receipt"
                :hint="$totals['voids'] > 0 ? number_format($totals['voids']) . ' voided' : null" />
        <x-stat label="Average {{ Str::lower(term('sale')) }}" :value="$amount($totals['basket'])" icon="cart" />
        <x-stat label="Discounts given" :value="$amount($totals['discounts'])" icon="sliders"
                :hint="$totals['gross'] > 0 ? 'across ' . number_format($rows->count()) . ' ' . Str::lower($cashiers) : null" />
    </div>

    @if ($rows->isEmpty())
        <x-card class="mt-4">
            <x-empty icon="users" :title="'Nothing rung up in this period'"
                     message="Choose a wider date range, or come back once the till has taken something." />
        </x-card>
    @else
        <x-card :title="'Takings per ' . Str::lower($cashier)" class="mt-4">
            <div style="height: {{ max(200, $rows->count() * 44 + 60) }}px">
                <canvas id="cashier-takings" role="img"
                        aria-label="Takings per {{ Str::lower($cashier) }} for the selected period"></canvas>
            </div>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                The same figures are in the table below.
            </p>
        </x-card>

        <x-card :padding="false" class="mt-4">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>{{ $cashier }}</th>
                        <th class="text-right">Takings</th>
                        <th class="text-right">{{ term('sale', true) }}</th>
                        <th class="text-right">Average</th>
                        <th class="text-right">Discounts</th>
                        <th class="text-right">Discount rate</th>
                        <th class="text-right">Voids</th>
                        <th class="text-right">Refunds processed</th>
                    </tr>
                </x-slot:head>

                @foreach ($rows as $row)
                    <tr>
                        <td>
                            <span class="font-medium">{{ $row['name'] }}</span>
                            @if ($row['role'])
                                <span class="block text-xs capitalize text-gray-500 dark:text-gray-400">{{ $row['role'] }}</span>
                            @endif
                        </td>
                        <td class="text-right tabular-nums">
                            {{ $amount($row['takings']) }}
                            @if ($row['refunded'] > 0)
                                <span class="block text-xs text-gray-500 dark:text-gray-400">
                                    after {{ $amount($row['refunded']) }} back
                                </span>
                            @endif
                        </td>
                        <td class="text-right tabular-nums">{{ number_format($row['transactions']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($row['basket']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($row['discounts']) }}</td>
                        <td class="text-right tabular-nums">
                            {{-- Flagged, not judged: a high rate may be a sale, or may be a habit. --}}
                            @if ($row['discount_rate'] >= 10)
                                <x-badge variant="amber" size="sm">{{ $row['discount_rate'] }}%</x-badge>
                            @else
                                {{ $row['discount_rate'] }}%
                            @endif
                        </td>
                        <td class="text-right tabular-nums">
                            @if ($row['voids'] > 0)
                                <x-badge variant="red" size="sm">{{ number_format($row['voids']) }}</x-badge>
                            @else
                                <span class="text-gray-400">0</span>
                            @endif
                        </td>
                        <td class="text-right tabular-nums">
                            {{ number_format($row['refunds_count']) }}
                            @if ($row['refunds_value'] > 0)
                                <span class="block text-xs text-gray-500 dark:text-gray-400">
                                    {{ $amount($row['refunds_value']) }}
                                </span>
                            @endif
                        </td>
                    </tr>
                @endforeach

                <x-slot:foot>
                    <tr>
                        <td>{{ number_format($rows->count()) }} {{ Str::lower(Str::plural($cashier, $rows->count())) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($totals['takings']) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($totals['transactions']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($totals['basket']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($totals['discounts']) }}</td>
                        <td></td>
                        <td class="text-right tabular-nums">{{ number_format($totals['voids']) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($totals['refunds_count']) }}</td>
                    </tr>
                </x-slot:foot>
            </x-table>
        </x-card>
    @endif
@endsection

@push('scripts')
    @include('reports.partials.charts')

    <script>
        // Nominal categories: one series, one hue, length carries the value. A
        // colour ramp here would double-encode the bar length and say nothing new.
        spChart('cashier-takings', (t) => ({
            type: 'bar',
            data: {
                labels: @json($rows->pluck('name')->all()),
                datasets: [spBar(t, 'Takings', @json($rows->pluck('takings')->all()), t.series[0])],
            },
            options: spAxes(t, { horizontal: true }),
        }));
    </script>
@endpush
