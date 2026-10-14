@extends('layouts.app')

@section('title', 'Profit & Loss')

@php
    /*
    | The arithmetic on this page is the controller's and is deliberately left
    | alone: revenue excludes tax and is net of refunds, cost of goods sold comes
    | from the cost snapshotted at the moment of sale, and stock bought is cash
    | out rather than a cost until the goods are sold. Only the presentation has
    | changed.
    |
    | Two charts rather than one with five series. Net profit is the number the
    | page exists for, so it gets its own plot with a zero line; revenue, cost
    | and expenses are the three things that produce it and share the second.
    | Never both on one pair of axes - two y-scales on one plot invent a
    | relationship that is not in the data.
    */
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));

    $days = count($labels);
    $grossByDay = array_map(fn($i) => round($salesData[$i] - $cogsData[$i], 2), array_keys($labels));

    // The running transaction list, newest first, as one ordered stream.
    $ledger = collect()
        ->concat($salesList->map(fn($sale) => [
            'type' => term('sale'), 'variant' => 'green', 'reference' => $sale->invoice_number,
            'date' => $sale->created_at->toDateString(), 'amount' => (float) $sale->grand_total,
            'note' => $sale->note ?? null,
        ]))
        ->concat($refundList->map(fn($refund) => [
            'type' => $refund->is_void ? 'Void' : 'Refund', 'variant' => $refund->is_void ? 'red' : 'orange',
            'reference' => $refund->sale->invoice_number ?? ('#' . $refund->sale_id),
            'date' => $refund->created_at->toDateString(), 'amount' => -(float) $refund->total_amount,
            'note' => $refund->reason,
        ]))
        ->concat($purchaseList->map(fn($purchase) => [
            'type' => term('purchase'), 'variant' => 'amber', 'reference' => $purchase->invoice_number,
            'date' => \Carbon\Carbon::parse($purchase->purchase_date)->toDateString(),
            'amount' => (float) $purchase->total_amount, 'note' => $purchase->note ?? null,
        ]))
        ->concat($expenseList->map(fn($expense) => [
            'type' => 'Expense', 'variant' => 'blue', 'reference' => '#' . $expense->id,
            'date' => \Carbon\Carbon::parse($expense->expense_date)->toDateString(),
            'amount' => (float) $expense->amount, 'note' => $expense->description ?: $expense->name,
        ]))
        ->sortByDesc('date')
        ->values();
@endphp

@section('content')
    <x-page-header title="Profit &amp; loss"
                   :subtitle="\Carbon\Carbon::parse($from)->format('j M Y') . ' to ' . \Carbon\Carbon::parse($to)->format('j M Y')" />

    @include('reports.partials.filter-bar', [
        'startDate' => $from,
        'endDate' => $to,
        'startKey' => 'from',
        'endKey' => 'to',
        'note' => 'Revenue excludes sales tax, which belongs to the tax authority, and is net of refunds. Cost of goods sold is what the goods cost when they were sold, so re-pricing something today cannot rewrite last month.',
    ])

    <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-3">
        <x-stat label="Revenue" :value="$amount($sales)" icon="coins" hint="excluding tax, after refunds" />
        <x-stat label="Cost of goods sold" :value="$amount($cogs)" icon="truck" hint="at the cost when sold" />
        <x-stat label="Expenses" :value="$amount($expenses)" icon="receipt" />
        <x-stat label="Gross profit" :value="$amount($grossProfit)" icon="chart-line"
                :hint="$sales > 0 ? $margin . '% margin' : 'no revenue in this period'" />
        <x-stat label="Net profit" :value="$amount($netProfit)" icon="scale"
                :delta="$netProfit < 0 ? 'a loss' : null" :trend="$netProfit < 0 ? 'down' : null"
                hint="gross profit less expenses" />
        <x-stat label="Average a day" :value="$amount($days > 0 ? $netProfit / $days : 0)" icon="calendar"
                :hint="$days . ' ' . Str::plural('day', $days) . ' in range'" />
    </div>

    {{-- For information: none of these are part of profit --}}
    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-card title="Stock purchased" subtitle="Cash out, not a cost until sold">
            <p class="text-xl font-semibold tabular-nums">{{ $amount($purchases) }}</p>
        </x-card>
        <x-card title="Tax collected" subtitle="Held for the tax authority">
            <p class="text-xl font-semibold tabular-nums">{{ $amount($taxCollected) }}</p>
        </x-card>
        <x-card title="Refunds paid out" subtitle="Already deducted from revenue">
            <p class="text-xl font-semibold tabular-nums">{{ $amount($refundsTotal) }}</p>
        </x-card>
    </div>

    <x-card title="Net profit per day" subtitle="What was left after cost of goods and expenses" class="mt-4">
        @if ($sales == 0 && $cogs == 0 && $expenses == 0)
            <x-empty icon="chart-line" title="Nothing happened in this period"
                     message="Choose a wider date range. Sales, costs and expenses all appear here once there are any." />
        @else
            <div class="h-72">
                <canvas id="pl-net" role="img" aria-label="Net profit per day over the selected period"></canvas>
            </div>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                <span class="font-semibold">{{ $amount($netProfit) }}</span> over {{ $days }}
                {{ Str::plural('day', $days) }}.
                @php $losses = count(array_filter($netProfitData, fn($v) => $v < 0)); @endphp
                @if ($losses > 0)
                    {{ $losses }} {{ Str::plural('day', $losses) }} made a loss.
                @endif
            </p>
        @endif
    </x-card>

    @if ($sales != 0 || $cogs != 0 || $expenses != 0)
        <x-card title="What produced it" subtitle="Revenue, cost of goods sold and expenses, per day" class="mt-4">
            <div class="h-72">
                <canvas id="pl-parts" role="img"
                        aria-label="Revenue, cost of goods sold and expenses per day over the selected period"></canvas>
            </div>
        </x-card>

        {{-- The charts' figures, exactly: the page is still usable with no canvas. --}}
        <x-card title="Day by day" :padding="false" class="mt-4">
            <div class="max-h-96 overflow-y-auto">
                <x-table :dense="true">
                    <x-slot:head>
                        <tr>
                            <th>Date</th>
                            <th class="text-right">Revenue</th>
                            <th class="text-right">Cost of goods</th>
                            <th class="text-right">Gross profit</th>
                            <th class="text-right">Expenses</th>
                            <th class="text-right">Net profit</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($labels as $i => $date)
                        <tr>
                            <td class="whitespace-nowrap">{{ \Carbon\Carbon::parse($date)->format('D j M Y') }}</td>
                            <td class="text-right tabular-nums">{{ $amount($salesData[$i]) }}</td>
                            <td class="text-right tabular-nums">{{ $amount($cogsData[$i]) }}</td>
                            <td class="text-right tabular-nums">{{ $amount($grossByDay[$i]) }}</td>
                            <td class="text-right tabular-nums">{{ $amount($expensesData[$i]) }}</td>
                            <td class="text-right tabular-nums {{ $netProfitData[$i] < 0 ? 'text-red-700 dark:text-red-400' : '' }}">
                                {{ $amount($netProfitData[$i]) }}
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:foot>
                        <tr>
                            <td>Total</td>
                            <td class="text-right tabular-nums">{{ $amount($sales) }}</td>
                            <td class="text-right tabular-nums">{{ $amount($cogs) }}</td>
                            <td class="text-right tabular-nums">{{ $amount($grossProfit) }}</td>
                            <td class="text-right tabular-nums">{{ $amount($expenses) }}</td>
                            <td class="text-right tabular-nums">{{ $amount($netProfit) }}</td>
                        </tr>
                    </x-slot:foot>
                </x-table>
            </div>
        </x-card>
    @endif

    <x-card title="Every transaction" :subtitle="number_format($ledger->count()) . ' in this period'"
            :padding="false" class="mt-4">
        <div class="max-h-[32rem] overflow-y-auto">
            <x-table :dense="true">
                <x-slot:head>
                    <tr>
                        <th>Type</th>
                        <th>Reference</th>
                        <th>Date</th>
                        <th class="text-right">Amount</th>
                        <th>Note</th>
                    </tr>
                </x-slot:head>

                @forelse ($ledger as $entry)
                    <tr>
                        <td><x-badge :variant="$entry['variant']" size="sm">{{ $entry['type'] }}</x-badge></td>
                        <td class="font-medium">{{ $entry['reference'] }}</td>
                        <td class="whitespace-nowrap text-gray-500 dark:text-gray-400">
                            {{ \Carbon\Carbon::parse($entry['date'])->format('j M Y') }}
                        </td>
                        <td class="text-right tabular-nums {{ $entry['amount'] < 0 ? 'text-red-700 dark:text-red-400' : '' }}">
                            {{ $amount($entry['amount']) }}
                        </td>
                        <td class="text-gray-500 dark:text-gray-400">{{ $entry['note'] ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <x-empty icon="inbox" title="No transactions in this period"
                                     message="Sales, refunds, purchases and expenses all appear in this list." />
                        </td>
                    </tr>
                @endforelse
            </x-table>
        </div>
    </x-card>
@endsection

@push('scripts')
    @include('reports.partials.charts')

    <script>
        @php $prettyLabels = array_map(fn($d) => \Carbon\Carbon::parse($d)->format('j M'), $labels); @endphp

        // The one number that matters, on its own: emphasis, not one line of five.
        spChart('pl-net', (t) => ({
            type: 'line',
            data: {
                labels: @json($prettyLabels),
                datasets: [spLine(t, 'Net profit', @json($netProfitData), t.series[0], { fill: true })],
            },
            // beginAtZero off: a loss has to be allowed to go below the line.
            options: spAxes(t, { zero: false }),
        }));

        // Three series, which is the band that stays distinguishable for everyone.
        spChart('pl-parts', (t) => ({
            type: 'line',
            data: {
                labels: @json($prettyLabels),
                datasets: [
                    spLine(t, 'Revenue', @json($salesData), t.series[0]),
                    spLine(t, 'Cost of goods sold', @json($cogsData), t.series[1]),
                    spLine(t, 'Expenses', @json($expensesData), t.series[2]),
                ],
            },
            options: spAxes(t, { legend: true }),
        }));
    </script>
@endpush
