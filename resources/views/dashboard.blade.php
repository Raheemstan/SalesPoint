@extends('layouts.app')

@section('title', 'Dashboard')

@php
    /*
    |--------------------------------------------------------------------------
    | The dashboard
    |--------------------------------------------------------------------------
    |
    | Two screens in one file, chosen by $role in ReportController@dashboard:
    |
    |   manager   the whole shop - takings, profit, drawers, debt, stock
    |   cashier   their own takings, their own drawer, their own sales
    |
    | A cashier never sees margin, gross profit, another person's takings or the
    | shop's debt. That is a deliberate product decision as much as a permissions
    | one: a figure they cannot act on is noise on the one screen they live in.
    |
    | Every panel has an empty state, and every average is guarded upstream, so
    | a shop's first morning shows an invitation rather than a stack of zeros and
    | a division by zero.
    */

    $cashierTerm = term('cashier');
    $productTerm = term('product', true);
    $customerTerm = term('customer', true);

    // money() puts the symbol first, so a bare negative would read "<symbol>-100".
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));

    /*
    | A percentage change, or null when there is nothing to compare against.
    | Yesterday being zero is not a 100% rise, it is an undefined one, and
    | printing "+100%" on a shop's second day would be a lie.
    */
    $change = function ($now, $before) {
        if ($before === null || abs((float) $before) < 0.005) {
            return null;
        }

        return round((((float) $now - (float) $before) / abs((float) $before)) * 100, 1);
    };

    // Named so as not to shadow $trend, which is the chart's data.
    $deltaOf = fn($percent) => $percent === null ? null : ($percent > 0 ? '+' : '') . $percent . '%';
    $trendOf = fn($percent) => $percent === null ? null : ($percent > 0 ? 'up' : ($percent < 0 ? 'down' : 'flat'));

    $statusBadge = [
        \App\Models\Sale::STATUS_COMPLETED => ['green', 'Completed'],
        \App\Models\Sale::STATUS_PARTIAL => ['amber', 'Partially refunded'],
        \App\Models\Sale::STATUS_REFUNDED => ['orange', 'Refunded'],
        \App\Models\Sale::STATUS_VOIDED => ['red', 'Voided'],
    ];

    /*
    | Does this trade count anything in and out of stock? A salon or a repair
    | shop sells labour, so a "low stock" tile would link to a screen the nav
    | deliberately hides and point at a number that can never move.
    */
    $tracksStock = feature('stock');
@endphp

@section('content')
    <x-page-header :title="setting('store_name') ?: 'Dashboard'"
                   :subtitle="now()->format('l, j F Y')">
        <x-slot:actions>
            <a href="{{ route('pos.index') }}"
               class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                <x-icon name="cart" size="4" />
                New {{ Str::lower(term('sale')) }}
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- One period selector, above everything it scopes. --}}
    <form method="GET" class="mb-5 flex flex-wrap items-center gap-2">
        <span class="text-sm text-gray-500 dark:text-gray-400">Showing</span>
        @foreach ($periods as $value => $label)
            <a href="{{ route('dashboard', ['days' => $value]) }}"
               @if ($days === $value) aria-current="true" @endif
               class="inline-flex min-h-touch items-center rounded-md px-3 py-1.5 text-sm font-medium transition
                      {{ $days === $value
                            ? 'bg-primary-600 text-white'
                            : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700' }}">
                {{ $label }}
            </a>
        @endforeach
    </form>

    @if ($role === 'cashier')
        {{--
        |----------------------------------------------------------------------
        | Cashier
        |----------------------------------------------------------------------
        --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-stat label="My takings today" :value="$amount($today['takings'])" icon="coins"
                    hint="after anything given back" />
            <x-stat label="My {{ Str::lower(term('sale', true)) }} today" :value="number_format($today['transactions'])"
                    icon="receipt" />
            <x-stat label="My average {{ Str::lower(term('sale')) }}" :value="$amount($today['basket'])" icon="cart"
                    :hint="$today['transactions'] > 0 ? null : 'nothing rung up yet'" />
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
            {{-- The drawer: the thing a cashier is actually looking for. --}}
            <x-card title="My shift" class="lg:col-span-1">
                <x-slot:actions>
                    <a href="{{ route('shifts.index') }}"
                       class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">Open shifts</a>
                </x-slot:actions>

                @if ($shift && $shiftSummary)
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Cash expected in the drawer
                    </p>
                    <p class="mt-1 text-3xl font-semibold text-gray-900 dark:text-gray-100">
                        {{ $amount($shiftSummary['methods']['cash']['expected']) }}
                    </p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        float {{ money($shiftSummary['methods']['cash']['opening_float']) }}
                        + sales {{ money($shiftSummary['methods']['cash']['sales']) }}
                        &minus; refunds {{ money($shiftSummary['methods']['cash']['refunds']) }}
                    </p>

                    <dl class="mt-4 space-y-1.5 border-t border-gray-200 pt-3 text-sm dark:border-gray-700">
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500 dark:text-gray-400">Open since</dt>
                            <dd class="tabular-nums">{{ $shift->opened_at->format('d M, H:i') }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500 dark:text-gray-400">{{ term('sale', true) }} this shift</dt>
                            <dd class="tabular-nums">{{ number_format($shiftSummary['sales_count']) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500 dark:text-gray-400">Net of refunds</dt>
                            <dd class="tabular-nums">{{ $amount($shiftSummary['net']) }}</dd>
                        </div>
                    </dl>

                    <a href="{{ route('shifts.show', $shift) }}"
                       class="mt-4 inline-flex min-h-touch items-center gap-1 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                        Close or print my Z report
                        <x-icon name="chevron-right" size="4" />
                    </a>
                @else
                    <x-empty icon="clock" title="No shift open"
                             :message="$shiftRequired
                                ? 'This shop needs a shift open before anything can be sold.'
                                : 'Open one to have your drawer counted at the end of the day.'">
                        <x-slot:action>
                            <a href="{{ route('shifts.index') }}"
                               class="inline-flex min-h-touch items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                                Open a shift
                            </a>
                        </x-slot:action>
                    </x-empty>
                @endif
            </x-card>

            {{-- My takings, day by day --}}
            <x-card title="My takings" :subtitle="$rangeLabel" class="lg:col-span-2">
                @if (array_sum($trend['values']) == 0)
                    <x-empty icon="chart-line" title="Nothing yet"
                             message="Your takings will appear here as you ring sales up." />
                @else
                    <div class="h-64">
                        <canvas id="cashier-trend" role="img"
                                aria-label="My takings per day for {{ $rangeLabel }}"></canvas>
                    </div>

                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        {{ $amount(array_sum($trend['values'])) }} over {{ count($trend['labels']) }} days.
                    </p>
                @endif
            </x-card>
        </div>

        <x-card title="My {{ Str::lower(term('sale', true)) }} today" :padding="false" class="mt-4">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>Time</th>
                        <th>Invoice</th>
                        <th>{{ term('customer') }}</th>
                        <th class="text-right">Total</th>
                        <th>Status</th>
                    </tr>
                </x-slot:head>

                @forelse ($recent as $sale)
                    <tr>
                        <td class="whitespace-nowrap tabular-nums">{{ $sale->created_at->format('H:i') }}</td>
                        <td>
                            <a href="{{ route('sales.show', $sale) }}"
                               class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                {{ $sale->invoice_number }}
                            </a>
                        </td>
                        <td class="text-gray-500 dark:text-gray-400">{{ $sale->customer->name ?? 'Walk-in' }}</td>
                        <td class="text-right tabular-nums">{{ $amount($sale->grand_total) }}</td>
                        <td>
                            @php [$variant, $label] = $statusBadge[$sale->status] ?? ['gray', $sale->status]; @endphp
                            <x-badge :variant="$variant" size="sm">{{ $label }}</x-badge>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <x-empty icon="receipt" title="No sales yet today"
                                     message="Everything you ring up today shows here.">
                                <x-slot:action>
                                    <a href="{{ route('pos.index') }}"
                                       class="inline-flex min-h-touch items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                                        Open the till
                                    </a>
                                </x-slot:action>
                            </x-empty>
                        </td>
                    </tr>
                @endforelse
            </x-table>
        </x-card>

    @else
        {{--
        |----------------------------------------------------------------------
        | Manager / admin
        |----------------------------------------------------------------------
        --}}
        @php
            $takingsChange = $change($today['takings'], $yesterday['takings']);
            $basketChange = $change($today['basket'], $yesterday['basket']);
            $drawerCash = $openShifts->sum(fn($row) => $row['summary']['methods']['cash']['expected']);
        @endphp

        {{-- Every tile links to the report that explains it. --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-stat label="Takings today" :value="$amount($today['takings'])" icon="coins"
                    :delta="$deltaOf($takingsChange)" :trend="$trendOf($takingsChange)"
                    :hint="$takingsChange === null ? 'net of refunds' : 'vs yesterday'"
                    :href="route('reports.daily')" />

            <x-stat label="{{ term('sale', true) }} today" :value="number_format($today['transactions'])" icon="receipt"
                    :hint="$today['transactions'] === 0 ? 'nothing rung up yet' : 'voided sales not counted'"
                    :href="route('reports.sales')" />

            <x-stat label="Average {{ Str::lower(term('sale')) }}" :value="$amount($today['basket'])" icon="cart"
                    :delta="$deltaOf($basketChange)" :trend="$trendOf($basketChange)"
                    :href="route('reports.sales')" />

            <x-stat label="Gross profit today" :value="$amount($profit['gross_profit'])" icon="chart-line"
                    :hint="$profit['revenue'] > 0 ? $profit['margin'] . '% margin' : 'no revenue yet'"
                    :href="route('reports.profit_loss')" />

            <x-stat label="Cash in open drawers" :value="$amount($drawerCash)" icon="clock"
                    :hint="$openShifts->isEmpty()
                        ? 'no shift open'
                        : $openShifts->count() . ' ' . Str::plural('shift', $openShifts->count()) . ' open'"
                    :href="route('shifts.index')" />

            <x-stat label="Owed by {{ Str::lower($customerTerm) }}" :value="$amount($owed['owed'])" icon="users"
                    :delta="$owed['overdue'] > 0 ? $amount($owed['overdue']) . ' overdue' : null"
                    :trend="$owed['overdue'] > 0 ? 'up' : null" invert
                    :hint="$owed['owed'] > 0 ? 'across ' . $owed['customers'] . ' ' . Str::lower($customerTerm) : 'nothing outstanding'"
                    :href="route('reports.receivables')" />

            {{--
            | Stock tiles only for a trade that keeps stock. A salon or a repair
            | shop sells labour: there is nothing to run low, nothing to reorder
            | and nothing to value, and the sidebar hides those screens for the
            | same reason.
            --}}
            @if ($tracksStock)
                <x-stat label="Low stock" :value="number_format($lowStockCount)" icon="alert-triangle"
                        :hint="$lowStockCount === 0 ? 'nothing to reorder' : 'at or below reorder level'"
                        :href="route('reorder.index')" />

                @if (feature('expiry'))
                    <x-stat label="Expiring soon" :value="number_format($expiringCount)" icon="alert-triangle"
                            hint="within 30 days" :href="route('reports.expiry')" />
                @else
                    <x-stat label="Stock at cost" :value="$amount($stockAtCost)" icon="scale"
                            hint="on the shelves now" :href="route('reports.valuation')" />
                @endif
            @else
                <x-stat label="Takings this period" :value="$amount($periodTotal)" icon="chart-line"
                        :hint="Str::lower($periods[$days])" :href="route('reports.sales')" />

                <x-stat label="Gross profit this period" :value="$amount($periodProfit['gross_profit'])" icon="coins"
                        :hint="$periodProfit['revenue'] > 0 ? $periodProfit['margin'] . '% margin' : 'no revenue yet'"
                        :href="route('reports.profit_loss')" />
            @endif
        </div>

        {{-- The trend, with the period before it for context --}}
        <x-card title="Takings" :subtitle="$rangeLabel . ' · dashed line is ' . $previousLabel"
                class="mt-4">
            <x-slot:actions>
                <a href="{{ route('reports.sales') }}"
                   class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">Sales report</a>
            </x-slot:actions>

            @if ($periodTotal == 0 && $previousTotal == 0)
                <x-empty icon="chart-line" title="No sales in this period"
                         message="The trend appears here once the till has taken something.">
                    <x-slot:action>
                        <a href="{{ route('pos.index') }}"
                           class="inline-flex min-h-touch items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                            Open the till
                        </a>
                    </x-slot:action>
                </x-empty>
            @else
                <div class="h-72">
                    <canvas id="sales-trend" role="img"
                            aria-label="Takings per day for {{ $rangeLabel }}, against the previous period"></canvas>
                </div>

                {{-- The same figures without the canvas: the summary line, then the detail. --}}
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                    <span class="font-semibold">{{ $amount($periodTotal) }}</span> this period
                    against <span class="font-semibold">{{ $amount($previousTotal) }}</span> the period before
                    @php $periodChange = $change($periodTotal, $previousTotal); @endphp
                    @if ($periodChange !== null)
                        <span class="{{ $periodChange >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                            ({{ $deltaOf($periodChange) }})
                        </span>
                    @endif
                    @if (max($trend['values']) > 0)
                        @php $peakDay = $trend['labels'][array_search(max($trend['values']), $trend['values'], true)]; @endphp
                        &middot; busiest day {{ \Carbon\Carbon::parse($peakDay)->format('j M') }}
                        at {{ $amount(max($trend['values'])) }}.
                    @endif
                </p>

                <details class="mt-3 text-sm">
                    <summary class="cursor-pointer text-primary-600 hover:underline dark:text-primary-400">
                        Show the daily figures as a table
                    </summary>
                    <div class="mt-3 max-h-72 overflow-y-auto">
                        <x-table :dense="true">
                            <x-slot:head>
                                <tr>
                                    <th>Date</th>
                                    <th class="text-right">Takings</th>
                                </tr>
                            </x-slot:head>
                            @foreach ($trend['labels'] as $i => $date)
                                <tr>
                                    <td class="whitespace-nowrap">{{ \Carbon\Carbon::parse($date)->format('D j M Y') }}</td>
                                    <td class="text-right tabular-nums">{{ $amount($trend['values'][$i]) }}</td>
                                </tr>
                            @endforeach
                        </x-table>
                    </div>
                </details>
            @endif
        </x-card>

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-5">
            {{-- Today by tender --}}
            <x-card title="Taken today by method" subtitle="How the money came in" class="lg:col-span-2">
                @php $takenTotal = array_sum(array_column($methods, 'taken')); @endphp

                @if ($takenTotal <= 0)
                    <x-empty icon="coins" title="Nothing taken yet today"
                             message="Cash, card and transfer appear here as sales are rung up." />
                @else
                    <div class="h-56">
                        <canvas id="method-split" role="img"
                                aria-label="Money taken today, split by payment method"></canvas>
                    </div>

                    <x-table :dense="true" class="mt-4">
                        <x-slot:head>
                            <tr>
                                <th>Method</th>
                                <th class="text-right">Taken</th>
                                <th class="text-right">Share</th>
                                <th class="text-right">Refunded</th>
                            </tr>
                        </x-slot:head>
                        @foreach ($methods as $row)
                            <tr>
                                <td class="whitespace-nowrap">{{ $row['label'] }}</td>
                                <td class="text-right tabular-nums">{{ $amount($row['taken']) }}</td>
                                <td class="text-right tabular-nums text-gray-500 dark:text-gray-400">
                                    {{ $takenTotal > 0 ? round($row['taken'] / $takenTotal * 100) : 0 }}%
                                </td>
                                <td class="text-right tabular-nums">{{ $amount($row['refunds']) }}</td>
                            </tr>
                        @endforeach
                        <x-slot:foot>
                            <tr>
                                <td>Total</td>
                                <td class="text-right tabular-nums">{{ $amount($takenTotal) }}</td>
                                <td></td>
                                <td class="text-right tabular-nums">
                                    {{ $amount(array_sum(array_column($methods, 'refunds'))) }}
                                </td>
                            </tr>
                        </x-slot:foot>
                    </x-table>
                @endif
            </x-card>

            {{-- Top sellers. The bar behind each row is the chart: one hue, length
                 carries the value, and the figure is right there to be read. --}}
            <x-card title="Top {{ Str::lower($productTerm) }}" :subtitle="$rangeLabel" class="lg:col-span-3">
                <x-slot:actions>
                    <a href="{{ route('reports.products') }}"
                       class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                        Margin by {{ Str::lower(term('product')) }}
                    </a>
                </x-slot:actions>

                @if ($topQuantity->isEmpty())
                    <x-empty icon="box" title="Nothing sold in this period"
                             message="Your best sellers are listed here once there are sales to rank." />
                @else
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        @foreach ([
                            ['By quantity', $topQuantity, 'quantity', false],
                            ['By gross profit', $topProfit, 'gross_profit', true],
                        ] as [$heading, $rows, $key, $isMoney])
                            <div>
                                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    {{ $heading }}
                                </h3>

                                @php $peak = max(1, (float) $rows->max($key)); @endphp

                                <ol class="space-y-1.5">
                                    @forelse ($rows as $row)
                                        @php $width = max(2, round(max(0, (float) $row[$key]) / $peak * 100)); @endphp
                                        <li class="relative overflow-hidden rounded-md">
                                            <span class="absolute inset-y-0 left-0 bg-primary-100 dark:bg-primary-900/50"
                                                  style="width: {{ $width }}%" aria-hidden="true"></span>
                                            <span class="relative flex items-center justify-between gap-3 px-2 py-1.5 text-sm">
                                                <span class="truncate text-gray-800 dark:text-gray-100">{{ $row['name'] }}</span>
                                                <span class="shrink-0 font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                                    {{ $isMoney ? $amount($row[$key]) : number_format($row[$key]) }}
                                                </span>
                                            </span>
                                        </li>
                                    @empty
                                        <li class="px-2 py-1.5 text-sm text-gray-500 dark:text-gray-400">Nothing to rank.</li>
                                    @endforelse
                                </ol>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>

        {{-- A trade with no stock gets the activity list full width instead. --}}
        <div class="mt-4 grid grid-cols-1 gap-4 {{ $tracksStock ? 'lg:grid-cols-2' : '' }}">
            @if ($tracksStock)
                {{-- Reorder --}}
                <x-card title="Running low" :padding="false">
                <x-slot:actions>
                    <a href="{{ route('reorder.index') }}"
                       class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                        Reorder list
                    </a>
                </x-slot:actions>

                <x-table :dense="true">
                    <x-slot:head>
                        <tr>
                            <th>{{ term('product') }}</th>
                            <th class="text-right">In stock</th>
                            <th class="text-right">Reorder at</th>
                        </tr>
                    </x-slot:head>

                    @forelse ($lowStock as $product)
                        <tr>
                            <td>
                                <span class="font-medium">{{ $product->name }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">
                                    {{ $product->category->name ?? 'Uncategorised' }}
                                </span>
                            </td>
                            <td class="text-right tabular-nums">
                                <x-badge :variant="(int) $product->stock_quantity <= 0 ? 'red' : 'amber'" size="sm">
                                    {{ number_format($product->stock_quantity) }}
                                </x-badge>
                            </td>
                            <td class="text-right tabular-nums text-gray-500 dark:text-gray-400">
                                {{ number_format($product->reorderLevel()) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3">
                                <x-empty icon="check-circle" title="Nothing is running low"
                                         message="Items at or below their reorder level appear here." />
                            </td>
                        </tr>
                    @endforelse

                    @if ($lowStockCount > $lowStock->count())
                        <x-slot:foot>
                            <tr>
                                <td colspan="3" class="text-sm font-normal text-gray-500 dark:text-gray-400">
                                    and {{ number_format($lowStockCount - $lowStock->count()) }} more.
                                </td>
                            </tr>
                        </x-slot:foot>
                    @endif
                </x-table>
            </x-card>
            @endif

            {{-- Recent activity --}}
            <x-card title="Latest {{ Str::lower(term('sale', true)) }}" :padding="false">
                <x-slot:actions>
                    <a href="{{ route('sales.index') }}"
                       class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">All</a>
                </x-slot:actions>

                <x-table :dense="true">
                    <x-slot:head>
                        <tr>
                            <th>When</th>
                            <th>Invoice</th>
                            <th>{{ $cashierTerm }}</th>
                            <th class="text-right">Total</th>
                            <th>Status</th>
                        </tr>
                    </x-slot:head>

                    @forelse ($recent as $sale)
                        <tr>
                            <td class="whitespace-nowrap text-gray-500 dark:text-gray-400">
                                {{ $sale->created_at->isToday() ? $sale->created_at->format('H:i') : $sale->created_at->format('j M') }}
                            </td>
                            <td>
                                <a href="{{ route('sales.show', $sale) }}"
                                   class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                    {{ $sale->invoice_number }}
                                </a>
                            </td>
                            <td class="truncate">{{ $sale->user->name ?? '-' }}</td>
                            <td class="text-right tabular-nums">{{ $amount($sale->grand_total) }}</td>
                            <td>
                                @php [$variant, $label] = $statusBadge[$sale->status] ?? ['gray', $sale->status]; @endphp
                                <x-badge :variant="$variant" size="sm">{{ $label }}</x-badge>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-empty icon="receipt" title="No sales yet"
                                         message="Your first sale will show up here.">
                                    <x-slot:action>
                                        <a href="{{ route('pos.index') }}"
                                           class="inline-flex min-h-touch items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                                            Open the till
                                        </a>
                                    </x-slot:action>
                                </x-empty>
                            </td>
                        </tr>
                    @endforelse
                </x-table>
            </x-card>
        </div>

        {{-- Open drawers, when there are any --}}
        @if ($openShifts->isNotEmpty())
            <x-card title="Drawers open now" :padding="false" class="mt-4">
                <x-table :dense="true">
                    <x-slot:head>
                        <tr>
                            <th>{{ $cashierTerm }}</th>
                            <th>Open since</th>
                            <th class="text-right">{{ term('sale', true) }}</th>
                            <th class="text-right">Net taken</th>
                            <th class="text-right">Cash expected</th>
                            <th></th>
                        </tr>
                    </x-slot:head>

                    @foreach ($openShifts as $row)
                        <tr>
                            <td class="font-medium">{{ $row['shift']->user->name ?? '-' }}</td>
                            <td class="whitespace-nowrap text-gray-500 dark:text-gray-400">
                                {{ $row['shift']->opened_at->format('d M, H:i') }}
                            </td>
                            <td class="text-right tabular-nums">{{ number_format($row['summary']['sales_count']) }}</td>
                            <td class="text-right tabular-nums">{{ $amount($row['summary']['net']) }}</td>
                            <td class="text-right tabular-nums">
                                {{ $amount($row['summary']['methods']['cash']['expected']) }}
                            </td>
                            <td class="text-right">
                                <a href="{{ route('shifts.show', $row['shift']) }}"
                                   class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                                    Z report
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </x-table>
            </x-card>
        @endif
    @endif
@endsection

@push('scripts')
    @include('reports.partials.charts')

    <script>
        @if ($role === 'cashier')
            spChart('cashier-trend', (t) => ({
                type: 'line',
                data: {
                    labels: @json(array_map(fn($d) => \Carbon\Carbon::parse($d)->format('j M'), $trend['labels'])),
                    datasets: [spLine(t, 'My takings', @json($trend['values']), t.series[0], { fill: true })],
                },
                options: spAxes(t),
            }));
        @else
            spChart('sales-trend', (t) => ({
                type: 'line',
                data: {
                    labels: @json(array_map(fn($d) => \Carbon\Carbon::parse($d)->format('j M'), $trend['labels'])),
                    datasets: [
                        // This period in the accent hue; the one before it in the
                        // de-emphasis grey, so the eye goes to the series that matters.
                        spLine(t, 'This period', @json($trend['values']), t.series[0], { fill: true }),
                        spLine(t, 'Previous period', @json($previousValues), t.context, { dashed: true }),
                    ],
                },
                options: spAxes(t, { legend: true }),
            }));

            spChart('method-split', (t) => ({
                type: 'doughnut',
                data: {
                    labels: @json(array_values(array_column($methods, 'label'))),
                    datasets: [{
                        data: @json(array_map(fn($row) => max(0, $row['taken']), array_values($methods))),
                        backgroundColor: t.series,
                        // A 2px ring in the surface colour is the gap between
                        // segments, not a stroke drawn around them.
                        borderColor: t.surface,
                        borderWidth: 2,
                        hoverOffset: 6,
                    }],
                },
                options: {
                    cutout: '58%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: t.ink,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                boxWidth: 8,
                                boxHeight: 8,
                                padding: 14,
                            },
                        },
                        tooltip: {
                            backgroundColor: '#1f2937',
                            titleColor: '#f9fafb',
                            bodyColor: '#e5e7eb',
                            padding: 10,
                            cornerRadius: 6,
                            callbacks: {
                                label: (ctx) => ` ${ctx.label}: ${spMoney(ctx.parsed)}`,
                            },
                        },
                    },
                },
            }));
        @endif
    </script>
@endpush
