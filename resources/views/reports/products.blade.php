@extends('layouts.app')

@section('title', 'Margin by ' . term('product'))

@php
    $product = term('product');
    $products = term('product', true);
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));

    /*
    | A sortable column heading: clicking the one already sorted flips the
    | direction. Built as links rather than a form so the sort survives in a
    | bookmark and in the browser's history.
    */
    $query = fn(string $column) => request()->fullUrlWithQuery([
        'sort' => $column,
        'dir' => $sort === $column && $dir === 'desc' ? 'asc' : 'desc',
        'page' => null,
    ]);

    $charted = $rows->take(15);

    // Sorting by name is for reading the table, not for a bar chart: fall back to
    // revenue so the chart still plots a number.
    $chartKey = $sort === 'name' ? 'revenue' : $sort;
    $chartMoney = !in_array($chartKey, ['quantity', 'margin'], true);
@endphp

@section('content')
    <x-page-header :title="'Margin by ' . Str::lower($product)"
                   :subtitle="'What each ' . Str::lower($product) . ' actually earns'" />

    @include('reports.partials.filter-bar', [
        'startDate' => $startDate,
        'endDate' => $endDate,
        'fields' => [
            ['name' => 'sort', 'label' => 'Sort by', 'options' => $sorts, 'value' => $sort],
        ],
        'note' => 'Gross profit uses the cost recorded at the moment of each sale, so re-pricing something today does not rewrite what it earned last month.',
    ])

    <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat label="Revenue" :value="$amount($totals['revenue'])" icon="coins" hint="excluding tax" />
        <x-stat label="Quantity sold" :value="number_format($totals['quantity'])" icon="box"
                :hint="'across ' . number_format($totals['rows']) . ' ' . Str::lower($products)" />
        <x-stat label="Gross profit" :value="$amount($totals['gross_profit'])" icon="chart-line" />
        <x-stat label="Margin" :value="$totals['margin'] . '%'" icon="scale"
                :hint="$totals['revenue'] > 0 ? 'of revenue' : 'no revenue in this period'" />
    </div>

    @if ($rows->isEmpty())
        <x-card class="mt-4">
            <x-empty icon="box" title="Nothing sold in this period"
                     message="Choose a wider date range, or come back once the till has rung something up." />
        </x-card>
    @else
        <x-card :title="'Top ' . Str::lower($products) . ' by ' . Str::lower($sorts[$chartKey])" class="mt-4">
            <div style="height: {{ max(220, $charted->count() * 40 + 70) }}px">
                <canvas id="product-ranking" role="img"
                        aria-label="The {{ $charted->count() }} leading {{ Str::lower($products) }} by {{ Str::lower($sorts[$chartKey]) }}"></canvas>
            </div>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                The {{ $charted->count() }} leading {{ Str::lower($products) }};
                all {{ number_format($rows->count()) }} are in the table below.
            </p>
        </x-card>

        <x-card :padding="false" class="mt-4">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th><a href="{{ $query('name') }}" class="hover:underline">{{ $product }}</a></th>
                        <th>{{ term('category') }}</th>
                        <th class="text-right"><a href="{{ $query('quantity') }}" class="hover:underline">Quantity</a></th>
                        <th class="text-right"><a href="{{ $query('revenue') }}" class="hover:underline">Revenue</a></th>
                        <th class="text-right"><a href="{{ $query('cogs') }}" class="hover:underline">Cost</a></th>
                        <th class="text-right"><a href="{{ $query('gross_profit') }}" class="hover:underline">Gross profit</a></th>
                        <th class="text-right"><a href="{{ $query('margin') }}" class="hover:underline">Margin</a></th>
                    </tr>
                </x-slot:head>

                @foreach ($rows as $row)
                    <tr>
                        <td class="font-medium">{{ $row['name'] }}</td>
                        <td class="text-gray-500 dark:text-gray-400">
                            {{ $row['sku'] ? $row['sku'] . ' · ' : '' }}{{ $row['category'] }}
                        </td>
                        <td class="text-right tabular-nums">{{ number_format($row['quantity']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($row['revenue']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($row['cogs']) }}</td>
                        <td class="text-right tabular-nums {{ $row['gross_profit'] < 0 ? 'text-red-700 dark:text-red-400' : '' }}">
                            {{ $amount($row['gross_profit']) }}
                        </td>
                        <td class="text-right tabular-nums">
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
                        <td colspan="2">{{ number_format($rows->count()) }} {{ Str::lower($products) }}</td>
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
        spChart('product-ranking', (t) => ({
            type: 'bar',
            data: {
                labels: @json($charted->pluck('name')->all()),
                datasets: [spBar(t, @json($sorts[$chartKey]), @json($charted->pluck($chartKey)->all()), t.series[0])],
            },
            // Quantity and margin are not money; revenue, cost and profit are.
            options: spAxes(t, { horizontal: true, money: @json($chartMoney) }),
        }));
    </script>
@endpush
