@extends('layouts.app')

@section('title', term('sale', true) . ' Report')

@php
    $sales = $sales ?? collect();
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));

    /*
    | The labels are the raw statuses made readable, and deliberately word for
    | word what the status column says ("Partially refunded", not a shortening):
    | other screens and tests read this page for them.
    */
    $statusBadge = [
        \App\Models\Sale::STATUS_COMPLETED => ['green', 'Completed'],
        \App\Models\Sale::STATUS_PARTIAL => ['amber', 'Partially refunded'],
        \App\Models\Sale::STATUS_REFUNDED => ['orange', 'Refunded'],
        \App\Models\Sale::STATUS_VOIDED => ['red', 'Voided'],
    ];

    $exportQuery = [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'payment_method' => request('payment_method'),
    ];
@endphp

@section('content')
    <x-page-header :title="term('sale', true) . ' report'"
                   :subtitle="\Carbon\Carbon::parse($startDate)->format('j M Y') . ' to ' . \Carbon\Carbon::parse($endDate)->format('j M Y')">
        <x-slot:actions>
            <a href="{{ route('reports.sales.report.pdf', $exportQuery) }}"
               class="inline-flex min-h-touch items-center gap-1.5 rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                <x-icon name="download" size="4" />
                PDF
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('reports.partials.filter-bar', [
        'startDate' => $startDate,
        'endDate' => $endDate,
        'fields' => [
            [
                'name' => 'payment_method',
                'label' => 'Payment method',
                'options' => ['cash' => 'Cash', 'pos' => 'POS', 'transfer' => 'Transfer'],
                'value' => request('payment_method'),
                'placeholder' => 'All',
            ],
        ],
        'csvRoute' => route('reports.sales.report.csv', $exportQuery),
        'note' => 'Net sales are what was taken less anything handed back in the same period. A voided sale is reversed in full, so it is not counted as a transaction.',
    ])

    <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <x-stat label="Taken" :value="$amount($summary['total_sales'])" icon="coins" hint="including tax" />
        <x-stat label="Refunded" :value="$amount($summary['total_refunds'])" icon="undo"
                :trend="$summary['total_refunds'] > 0 ? 'up' : null" invert />
        <x-stat label="Net sales" :value="$amount($summary['net_sales'])" icon="chart-line" />
        <x-stat label="Tax collected" :value="$amount($summary['total_tax'])" icon="receipt"
                hint="held for the tax authority" />
        <x-stat label="{{ term('sale', true) }}" :value="number_format($summary['total_transactions'])" icon="cart"
                :hint="$summary['total_discount'] > 0 ? $amount($summary['total_discount']) . ' discounted' : null" />
    </div>

    @if (array_sum($trend['values']) != 0 || $sales->isNotEmpty())
        <x-card title="Takings per day" :subtitle="count($trend['labels']) . ' days'" class="mt-4">
            <div class="h-72">
                <canvas id="sales-by-day" role="img"
                        aria-label="Takings per day over the selected period"></canvas>
            </div>
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                {{ $amount(array_sum($trend['values'])) }} over
                {{ count($trend['labels']) }} days, an average of
                {{ $amount(count($trend['labels']) > 0 ? array_sum($trend['values']) / count($trend['labels']) : 0) }}
                a day. Every sale is listed below.
            </p>
        </x-card>
    @endif

    <x-card :padding="false" class="mt-4">
        <x-table>
            <x-slot:head>
                <tr>
                    <th>Date</th>
                    <th>Invoice</th>
                    <th>Items</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Tax</th>
                    <th class="text-right">Discount</th>
                    <th class="text-right">Refunded</th>
                    <th>Status</th>
                </tr>
            </x-slot:head>

            @forelse ($sales as $sale)
                <tr>
                    <td class="whitespace-nowrap">{{ $sale->created_at->format('d M Y') }}</td>
                    <td>
                        <a href="{{ route('sales.show', $sale) }}"
                           class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                            {{ $sale->invoice_number }}
                        </a>
                        <span class="block text-xs capitalize text-gray-500 dark:text-gray-400">
                            {{ $sale->payment_method }}
                        </span>
                    </td>
                    <td>
                        <ul class="space-y-0.5">
                            @foreach ($sale->items as $item)
                                <li class="text-xs">
                                    {{ $item->product->name ?? 'Deleted ' . Str::lower(term('product')) }}
                                    <span class="text-gray-500 dark:text-gray-400">&times;{{ $item->quantity }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </td>
                    <td class="text-right tabular-nums">{{ $amount($sale->grand_total) }}</td>
                    <td class="text-right tabular-nums">{{ $amount($sale->tax_amount) }}</td>
                    <td class="text-right tabular-nums">{{ $amount($sale->discount_amount) }}</td>
                    <td class="text-right tabular-nums">
                        {{ (float) $sale->refunded_amount > 0 ? $amount($sale->refunded_amount) : '-' }}
                    </td>
                    <td>
                        @php [$variant, $label] = $statusBadge[$sale->status] ?? ['gray', $sale->status]; @endphp
                        <x-badge :variant="$variant" size="sm">{{ $label }}</x-badge>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-empty icon="receipt" title="No {{ Str::lower(term('sale', true)) }} in this period"
                                 message="Choose a wider date range, or clear the payment-method filter." />
                    </td>
                </tr>
            @endforelse

            @if ($sales->isNotEmpty())
                <x-slot:foot>
                    <tr>
                        <td colspan="3">{{ number_format($sales->count()) }} {{ Str::lower(Str::plural(term('sale'), $sales->count())) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($summary['total_sales']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($summary['total_tax']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($summary['total_discount']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($summary['total_refunds']) }}</td>
                        <td></td>
                    </tr>
                </x-slot:foot>
            @endif
        </x-table>
    </x-card>
@endsection

@push('scripts')
    @include('reports.partials.charts')

    <script>
        spChart('sales-by-day', (t) => ({
            type: 'line',
            data: {
                labels: @json(array_map(fn($d) => \Carbon\Carbon::parse($d)->format('j M'), $trend['labels'])),
                datasets: [spLine(t, 'Takings', @json($trend['values']), t.series[0], { fill: true })],
            },
            options: spAxes(t),
        }));
    </script>
@endpush
