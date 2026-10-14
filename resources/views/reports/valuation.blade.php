@extends('layouts.app')

@section('title', 'Stock Valuation')

@php
    /*
    | What is on the shelves right now, at what it cost and at what it would
    | fetch. There is deliberately no date range: stock on hand is a figure
    | about this moment, and the app keeps no movement history complete enough
    | to replay the shelves as they were in March.
    */
    $product = term('product');
    $products = term('product', true);
    $category = term('category');
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));

    $charted = $relevant ? $rows->take(12) : collect();
@endphp

@section('content')
    <x-page-header title="Stock valuation"
                   :subtitle="$relevant ? 'What is on the shelves now, at ' . now()->format('j M Y, H:i') : null" />

    @if (! $relevant)
        @include('reports.partials.not-relevant', [
            'message' => sector('label') . ' keeps no stock, so there is nothing to value. Switch stock tracking on in Settings if this shop needs it.',
        ])
    @else
    @include('reports.partials.filter-bar', [
        'fields' => [
            [
                'name' => 'category_id',
                'label' => $category,
                'options' => $categories->pluck('name', 'id')->all(),
                'value' => $categoryId,
                'placeholder' => 'All',
            ],
            ['name' => 'search', 'label' => 'Search', 'value' => $search, 'placeholder' => 'Name or SKU'],
        ],
        'note' => 'Stocked items only: a service has no stock, so valuing it would invent an asset the shop does not own. The filter narrows the detail list; the summary always covers the whole shop.',
    ])

    <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat label="Value at cost" :value="$amount($totals['cost'])" icon="coins"
                :hint="number_format($totals['units']) . ' units on the shelves'" />
        <x-stat label="Value at retail" :value="$amount($totals['retail'])" icon="scale" hint="at today's prices" />
        <x-stat label="Potential profit" :value="$amount($totals['potential_profit'])" icon="chart-line"
                hint="if every unit sold" />
        <x-stat label="Margin" :value="$totals['margin'] . '%'" icon="trend-up"
                :hint="$totals['retail'] > 0 ? 'of retail value' : 'nothing in stock'" />
    </div>

    @if ($rows->isEmpty())
        <x-card class="mt-4">
            <x-empty icon="box" title="No stocked {{ Str::lower($products) }} yet"
                     message="Add something that is counted in and out of stock and its value appears here.">
                <x-slot:action>
                    <a href="{{ route('products.index') }}"
                       class="inline-flex min-h-touch items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                        {{ $products }}
                    </a>
                </x-slot:action>
            </x-empty>
        </x-card>
    @else
        <x-card :title="'Value per ' . Str::lower($category)"
                subtitle="Each bar is the retail value, split into what the stock cost and what it would earn"
                class="mt-4">
            <div style="height: {{ max(220, $charted->count() * 44 + 70) }}px">
                <canvas id="valuation-split" role="img"
                        aria-label="Stock value at cost and potential profit, per {{ Str::lower($category) }}"></canvas>
            </div>
        </x-card>

        <x-card :title="'Summary by ' . Str::lower($category)" :padding="false" class="mt-4">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>{{ $category }}</th>
                        <th class="text-right">{{ $products }}</th>
                        <th class="text-right">Units</th>
                        <th class="text-right">At cost</th>
                        <th class="text-right">At retail</th>
                        <th class="text-right">Potential profit</th>
                        <th class="text-right">Margin</th>
                    </tr>
                </x-slot:head>

                @foreach ($rows as $row)
                    <tr>
                        <td class="font-medium">{{ $row['category'] }}</td>
                        <td class="text-right tabular-nums">{{ number_format($row['products']) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($row['units']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($row['cost']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($row['retail']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($row['potential_profit']) }}</td>
                        <td class="text-right tabular-nums">{{ $row['margin'] }}%</td>
                    </tr>
                @endforeach

                <x-slot:foot>
                    <tr>
                        <td>Whole shop</td>
                        <td class="text-right tabular-nums">{{ number_format($totals['products']) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($totals['units']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($totals['cost']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($totals['retail']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($totals['potential_profit']) }}</td>
                        <td class="text-right tabular-nums">{{ $totals['margin'] }}%</td>
                    </tr>
                </x-slot:foot>
            </x-table>
        </x-card>

        {{-- The list you print to walk the shelves with. --}}
        <x-card :title="$products" subtitle="Highest value at cost first" :padding="false" class="mt-4">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>{{ $product }}</th>
                        <th>{{ $category }}</th>
                        <th class="text-right">In stock</th>
                        <th class="text-right">Unit cost</th>
                        <th class="text-right">At cost</th>
                        <th class="text-right">At retail</th>
                    </tr>
                </x-slot:head>

                @forelse ($detail as $item)
                    @php
                        $atCost = (float) $item->cost_price * (int) $item->stock_quantity;
                        $atRetail = (float) $item->sale_price * (int) $item->stock_quantity;
                    @endphp
                    <tr>
                        <td>
                            <span class="font-medium">{{ $item->name }}</span>
                            @if ($item->sku)
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $item->sku }}</span>
                            @endif
                        </td>
                        <td class="text-gray-500 dark:text-gray-400">{{ $item->category->name ?? 'Uncategorised' }}</td>
                        <td class="text-right tabular-nums">
                            @if ((int) $item->stock_quantity <= 0)
                                <x-badge variant="red" size="sm">{{ number_format($item->stock_quantity) }}</x-badge>
                            @else
                                {{ number_format($item->stock_quantity) }}
                            @endif
                        </td>
                        <td class="text-right tabular-nums">{{ $amount($item->cost_price) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($atCost) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($atRetail) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-empty icon="search" title="Nothing matches that filter"
                                     message="Clear the filter to see everything in stock." />
                        </td>
                    </tr>
                @endforelse
            </x-table>

            @if ($detail->hasPages())
                <x-slot:footer>{{ $detail->links() }}</x-slot:footer>
            @endif
        </x-card>
    @endif
    @endif
@endsection

@if ($relevant && $rows->isNotEmpty())
    @push('scripts')
        @include('reports.partials.charts')

        <script>
            // Part-to-whole again: cost + potential profit is the retail value.
            spChart('valuation-split', (t) => ({
                type: 'bar',
                data: {
                    labels: @json($charted->pluck('category')->all()),
                    datasets: [
                        spBar(t, 'At cost', @json($charted->pluck('cost')->all()), t.series[0], { stacked: true }),
                        spBar(t, 'Potential profit', @json($charted->pluck('potential_profit')->all()), t.series[2], { stacked: true }),
                    ],
                },
                options: spAxes(t, { horizontal: true, stacked: true, legend: true }),
            }));
        </script>
    @endpush
@endif
