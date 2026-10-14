@extends('layouts.app')

@section('title', 'Stock Movement')

@php
    /*
    | Every real stock movement, not just sales: purchases in, sales out, refunds
    | back, returns to the supplier, stock-take corrections and hand-entered
    | adjustments. The "cause" filter keys off the opening of the reason each
    | writer records, which is what makes "everything written off as damage" a
    | question this page can answer.
    */
    $product = term('product');
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));

    $typeBadge = ['IN' => 'green', 'OUT' => 'red', 'ADJUST' => 'amber'];

    $unitsIn = $relevant ? array_sum($flow['in']) : 0;
    $unitsOut = $relevant ? abs(array_sum($flow['out'])) : 0;
@endphp

@section('content')
    <x-page-header title="Stock movement"
                   :subtitle="$relevant
                        ? \Carbon\Carbon::parse($startDate)->format('j M Y') . ' to ' . \Carbon\Carbon::parse($endDate)->format('j M Y')
                        : null" />

    @if (! $relevant)
        @include('reports.partials.not-relevant', [
            'message' => sector('label') . ' counts nothing in or out of stock, so there is no movement to report. Switch stock tracking on in Settings if this shop needs it.',
        ])
    @else
    @include('reports.partials.filter-bar', [
        'startDate' => $startDate,
        'endDate' => $endDate,
        'fields' => [
            [
                'name' => 'product_id',
                'label' => $product,
                'options' => $products->pluck('name', 'id')->all(),
                'value' => $productId,
                'placeholder' => 'All',
                'width' => 'sm:min-w-56',
            ],
            [
                'name' => 'type',
                'label' => 'Direction',
                'options' => $types,
                'value' => $type,
                'placeholder' => 'Either way',
            ],
            [
                'name' => 'source',
                'label' => 'Cause',
                'options' => $sources,
                'value' => $source,
                'placeholder' => 'Any cause',
                'width' => 'sm:min-w-52',
            ],
        ],
    ])

    <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat label="Units in" :value="number_format($unitsIn)" icon="trend-up" />
        <x-stat label="Units out" :value="number_format($unitsOut)" icon="trend-down" />
        <x-stat label="Net change" :value="number_format($unitsIn - $unitsOut)" icon="refresh"
                :hint="$unitsIn - $unitsOut >= 0 ? 'stock grew' : 'stock fell'" />
        <x-stat label="Movements" :value="number_format($movements->total())" icon="sliders" />
    </div>

    @if ($unitsIn > 0 || $unitsOut > 0)
        <x-card title="Units in and out, per day"
                subtitle="In above the line, out below it" class="mt-4">
            <div class="h-72">
                <canvas id="movement-flow" role="img"
                        aria-label="Units of stock moving in and out per day over the selected period"></canvas>
            </div>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                {{ number_format($unitsIn) }} units in, {{ number_format($unitsOut) }} out,
                a net change of {{ number_format($unitsIn - $unitsOut) }}. Every movement is listed below.
            </p>
        </x-card>
    @endif

    <x-card :padding="false" class="mt-4">
        <x-table>
            <x-slot:head>
                <tr>
                    <th>Date</th>
                    <th>{{ $product }}</th>
                    <th class="text-right">Quantity</th>
                    <th>Direction</th>
                    <th>Reason</th>
                    <th>By</th>
                </tr>
            </x-slot:head>

            @forelse ($movements as $log)
                <tr>
                    <td class="whitespace-nowrap">{{ $log->created_at->format('d M Y H:i') }}</td>
                    <td>
                        <span class="font-medium">
                            {{ $log->product->name ?? 'Deleted ' . Str::lower($product) }}
                        </span>
                        @if ($log->product?->sku)
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $log->product->sku }}</span>
                        @endif
                    </td>
                    <td class="text-right font-semibold tabular-nums">
                        @if ($log->type === 'OUT')
                            <span class="text-red-700 dark:text-red-400">-{{ number_format(abs($log->quantity)) }}</span>
                        @elseif ($log->type === 'IN')
                            <span class="text-green-700 dark:text-green-400">+{{ number_format($log->quantity) }}</span>
                        @else
                            {{ $log->quantity > 0 ? '+' : '' }}{{ number_format($log->quantity) }}
                        @endif
                    </td>
                    <td>
                        <x-badge :variant="$typeBadge[$log->type] ?? 'gray'" size="sm">{{ $log->type }}</x-badge>
                    </td>
                    <td class="text-gray-600 dark:text-gray-300">{{ $log->reason }}</td>
                    <td class="text-gray-500 dark:text-gray-400">{{ $log->user->name ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-empty icon="refresh" title="No stock movement in this period"
                                 message="Choose a wider date range, or clear the filters above." />
                    </td>
                </tr>
            @endforelse
        </x-table>

        @if ($movements->hasPages())
            <x-slot:footer>{{ $movements->links() }}</x-slot:footer>
        @endif
    </x-card>
    @endif
@endsection

@if ($relevant && ($unitsIn > 0 || $unitsOut > 0))
    @push('scripts')
        @include('reports.partials.charts')

        <script>
            /*
            | Genuinely diverging: in and out are opposite directions about one
            | baseline, so the two warm/cool poles say which way the stock went
            | without the reader having to consult a legend first. (The legend is
            | there anyway - two series.)
            */
            spChart('movement-flow', (t) => ({
                type: 'bar',
                data: {
                    labels: @json(array_map(fn($d) => \Carbon\Carbon::parse($d)->format('j M'), $flow['labels'])),
                    datasets: [
                        spBar(t, 'Units in', @json($flow['in']), t.up),
                        spBar(t, 'Units out', @json($flow['out']), t.down),
                    ],
                },
                options: spAxes(t, { money: false, stacked: true, legend: true, zero: false }),
            }));
        </script>
    @endpush
@endif
