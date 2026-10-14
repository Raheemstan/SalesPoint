@extends('layouts.app')

@section('title', 'Margin by ' . term('category'))

@php
    $category = term('category');
    $categories = term('category', true);
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));

    // The chart shows the fifteen biggest; the table shows everything.
    $charted = $rows->take(15);
@endphp

@section('content')
    <x-page-header :title="'Margin by ' . Str::lower($category)"
                   subtitle="Where the money is actually earned" />

    @include('reports.partials.filter-bar', [
        'startDate' => $startDate,
        'endDate' => $endDate,
        'note' => 'Revenue excludes sales tax and is net of refunds. Cost is what the goods cost at the moment they were sold, not what they cost today.',
    ])

    <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat label="Revenue" :value="$amount($totals['revenue'])" icon="coins" hint="excluding tax" />
        <x-stat label="Cost of goods sold" :value="$amount($totals['cogs'])" icon="truck" />
        <x-stat label="Gross profit" :value="$amount($totals['gross_profit'])" icon="chart-line" />
        <x-stat label="Margin" :value="$totals['margin'] . '%'" icon="scale"
                :hint="$totals['revenue'] > 0 ? 'of revenue' : 'no revenue in this period'" />
    </div>

    @if ($rows->isEmpty())
        <x-card class="mt-4">
            <x-empty icon="folder" title="Nothing sold in this period"
                     :message="'Choose a wider date range, or come back once there are sales to split by ' . Str::lower($category) . '.'" />
        </x-card>
    @else
        <x-card :title="'Cost and profit per ' . Str::lower($category)"
                subtitle="Each bar is that revenue, split into what the goods cost and what was earned on them"
                class="mt-4">
            <div style="height: {{ max(220, $charted->count() * 44 + 70) }}px">
                <canvas id="category-split" role="img"
                        aria-label="Cost of goods sold and gross profit per {{ Str::lower($category) }}"></canvas>
            </div>
            @if ($rows->count() > $charted->count())
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                    The {{ $charted->count() }} largest by revenue; all {{ number_format($rows->count()) }}
                    are in the table below.
                </p>
            @endif
        </x-card>

        <x-card :padding="false" class="mt-4">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>{{ $category }}</th>
                        <th class="text-right">{{ term('product', true) }}</th>
                        <th class="text-right">Quantity</th>
                        <th class="text-right">Revenue</th>
                        <th class="text-right">Cost</th>
                        <th class="text-right">Gross profit</th>
                        <th class="text-right">Margin</th>
                    </tr>
                </x-slot:head>

                @foreach ($rows as $row)
                    <tr>
                        <td class="font-medium">{{ $row['category'] }}</td>
                        <td class="text-right tabular-nums">{{ number_format($row['products']) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($row['quantity']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($row['revenue']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($row['cogs']) }}</td>
                        <td class="text-right tabular-nums {{ $row['gross_profit'] < 0 ? 'text-red-700 dark:text-red-400' : '' }}">
                            {{ $amount($row['gross_profit']) }}
                        </td>
                        <td class="text-right tabular-nums">
                            {{-- Selling below cost is the one thing on this page worth shouting about. --}}
                            @if ($row['margin'] < 0)
                                <x-badge variant="red" size="sm">{{ $row['margin'] }}%</x-badge>
                            @else
                                {{ $row['margin'] }}%
                            @endif
                        </td>
                    </tr>
                @endforeach

                <x-slot:foot>
                    <tr>
                        <td>{{ number_format($rows->count()) }} {{ Str::lower(Str::plural($category, $rows->count())) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($rows->sum('products')) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($totals['quantity']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($totals['revenue']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($totals['cogs']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($totals['gross_profit']) }}</td>
                        <td class="text-right tabular-nums">{{ $totals['margin'] }}%</td>
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
        | Part-to-whole: cost + profit is the revenue, so a stack says both things
        | at once on one axis. Two series, so the legend is always on.
        */
        spChart('category-split', (t) => ({
            type: 'bar',
            data: {
                labels: @json($charted->pluck('category')->all()),
                datasets: [
                    spBar(t, 'Cost of goods sold', @json($charted->pluck('cogs')->all()), t.series[0], { stacked: true }),
                    spBar(t, 'Gross profit', @json($charted->pluck('gross_profit')->all()), t.series[2], { stacked: true }),
                ],
            },
            options: spAxes(t, { horizontal: true, stacked: true, legend: true }),
        }));
    </script>
@endpush
