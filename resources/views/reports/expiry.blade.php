@extends('layouts.app')

@section('title', 'Expiring Stock')

@php
    $product = term('product');
    $products = term('product', true);
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));
@endphp

@section('content')
    <x-page-header title="Expiring stock"
                   :subtitle="$relevant ? 'What has gone off, and what is about to' : null" />

    @if (! $relevant)
        {{--
        | Not a 404. The business type decides whether shelf life is a thing this
        | shop tracks, and that setting can be changed back - meanwhile the link
        | may well still be in somebody's bookmarks.
        --}}
        @include('reports.partials.not-relevant', [
            'message' => sector('label') . ' does not track batch numbers or expiry dates, so there is nothing to report here. Switch batch and expiry tracking on in Settings if this shop needs it.',
        ])
    @else
        @include('reports.partials.filter-bar', [
            'fields' => [
                [
                    'name' => 'days',
                    'label' => 'Look ahead',
                    'type' => 'number',
                    'value' => $days,
                    'min' => 1,
                    'max' => 365,
                    'width' => 'sm:min-w-32',
                ],
            ],
            'note' => 'Days to look ahead. Only stock still on the shelves is listed: an expired line with nothing left has already been sold or written off.',
        ])

        @php
            $expired = $buckets['expired'];
            $soon = $buckets['week'];
        @endphp

        <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-stat label="Already expired" :value="number_format($expired['items'])" icon="x-circle"
                    :delta="$expired['value'] > 0 ? $amount($expired['value']) . ' at risk' : null"
                    :trend="$expired['items'] > 0 ? 'up' : null" invert
                    :hint="number_format($expired['units']) . ' units'" />
            <x-stat label="Within 7 days" :value="number_format($soon['items'])" icon="alert-triangle"
                    :hint="$soon['value'] > 0 ? $amount($soon['value']) . ' at risk' : 'nothing imminent'" />
            <x-stat label="Within {{ $days }} days" :value="number_format($totals['items'])" icon="calendar"
                    :hint="number_format($totals['units']) . ' units'" />
            <x-stat label="Value at risk" :value="$amount($totals['value'])" icon="coins"
                    hint="at what the stock cost" />
        </div>

        @if ($rows->isEmpty())
            <x-card class="mt-4">
                <x-empty icon="check-circle" title="Nothing expiring in this window"
                         :message="'No stocked ' . Str::lower($products) . ' go off within ' . $days . ' days. Widen the window to look further ahead.'" />
            </x-card>
        @else
            <x-card title="Value at risk by urgency"
                    subtitle="Ordered bands: how soon it goes off, and what it cost"
                    class="mt-4">
                <div class="h-64">
                    <canvas id="expiry-buckets" role="img"
                            aria-label="Value of stock at risk, split by how soon it expires"></canvas>
                </div>

                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                    @foreach ($buckets as $bucket)
                        {{ $bucket['label'] }}
                        <span class="font-semibold">{{ $amount($bucket['value']) }}</span>
                        <span class="text-gray-500 dark:text-gray-400">({{ number_format($bucket['items']) }})</span>@if (!$loop->last) &middot; @endif
                    @endforeach
                </p>
            </x-card>

            <x-card :padding="false" class="mt-4">
                <x-table>
                    <x-slot:head>
                        <tr>
                            <th>{{ $product }}</th>
                            <th>Batch</th>
                            <th>Expires</th>
                            <th class="text-right">Days left</th>
                            <th class="text-right">In stock</th>
                            <th class="text-right">Value at risk</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($rows as $item)
                        @php $left = (int) today()->diffInDays($item->expiry_date, false); @endphp
                        <tr>
                            <td>
                                <span class="font-medium">{{ $item->name }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">
                                    {{ $item->sku ? $item->sku . ' · ' : '' }}{{ $item->category->name ?? 'Uncategorised' }}
                                </span>
                            </td>
                            <td class="text-gray-500 dark:text-gray-400">{{ $item->batch_no ?: '-' }}</td>
                            <td class="whitespace-nowrap">{{ $item->expiry_date->format('j M Y') }}</td>
                            <td class="text-right tabular-nums">
                                @if ($left < 0)
                                    <x-badge variant="red" size="sm">{{ abs($left) }} days ago</x-badge>
                                @elseif ($left <= 7)
                                    <x-badge variant="amber" size="sm">{{ $left }}</x-badge>
                                @else
                                    {{ $left }}
                                @endif
                            </td>
                            <td class="text-right tabular-nums">{{ number_format($item->stock_quantity) }}</td>
                            <td class="text-right tabular-nums">{{ $amount($item->stock_value) }}</td>
                        </tr>
                    @endforeach

                    <x-slot:foot>
                        <tr>
                            <td colspan="4">{{ number_format($totals['items']) }} {{ Str::lower($products) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($totals['units']) }}</td>
                            <td class="text-right tabular-nums">{{ $amount($totals['value']) }}</td>
                        </tr>
                    </x-slot:foot>
                </x-table>
            </x-card>
        @endif
    @endif
@endsection

@if ($relevant && $rows->isNotEmpty())
    @push('scripts')
        @include('reports.partials.charts')

        <script>
            spChart('expiry-buckets', (t) => ({
                type: 'bar',
                data: {
                    labels: @json(array_values(array_column($buckets, 'label'))),
                    datasets: [{
                        ...spBar(t, 'Value at risk', @json(array_values(array_column($buckets, 'value'))), t.series[0]),
                        // Ordered urgency bands, so an ordinal ramp rather than
                        // four unrelated hues. Darkest is soonest.
                        backgroundColor: [...t.ordinal].reverse(),
                    }],
                },
                options: spAxes(t),
            }));
        </script>
    @endpush
@endif
