@extends('layouts.app')
@section('title', term('sale', true, 'Sales'))

@php
    /*
    | Status colours and labels are the same four the dashboard uses, so a sale
    | that reads "Partially refunded" in amber there reads the same here.
    */
    $statusBadge = [
        \App\Models\Sale::STATUS_COMPLETED => ['green', 'Completed'],
        \App\Models\Sale::STATUS_PARTIAL => ['amber', 'Partially refunded'],
        \App\Models\Sale::STATUS_REFUNDED => ['orange', 'Refunded'],
        \App\Models\Sale::STATUS_VOIDED => ['red', 'Voided'],
    ];

    $saleTerm = term('sale', false, 'Sale');
    $salesTerm = term('sale', true, 'Sales');
    $cashierTerm = term('cashier', false, 'Cashier');

    $filterInput = 'min-h-touch rounded-md border-gray-300 bg-white text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white';
    $filterLabel = 'mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400';
@endphp

@section('content')
    <x-page-header :title="$manager ? $salesTerm . ' history' : 'My ' . Str::lower($salesTerm) . ' today'"
                   :subtitle="$manager
                        ? 'Everything the till has rung up, with what was given back'
                        : 'What you have rung up today'">
        <x-slot:actions>
            <a href="{{ route('pos.index') }}"
               class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                <x-icon name="cart" size="4" />
                New {{ Str::lower($saleTerm) }}
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Filters --}}
    <x-card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1 basis-56">
                <label for="sale-search" class="{{ $filterLabel }}">Invoice number</label>
                <input id="sale-search" type="search" name="search" value="{{ request('search') }}"
                       placeholder="Invoice number" class="{{ $filterInput }} w-full">
            </div>

            @if ($manager)
                <div>
                    <label for="sale-from" class="{{ $filterLabel }}">From</label>
                    <input id="sale-from" type="date" name="from" value="{{ $from }}" class="{{ $filterInput }}">
                </div>

                <div>
                    <label for="sale-to" class="{{ $filterLabel }}">To</label>
                    <input id="sale-to" type="date" name="to" value="{{ $to }}" class="{{ $filterInput }}">
                </div>

                {{--
                    A shop with thirty people on the rota needs to type a name
                    rather than scroll. [&>div]:mb-0 cancels the component's own
                    bottom margin so it lines up with the other filters.
                --}}
                <div class="basis-56 [&>div]:mb-0">
                    <x-searchable-select
                        name="cashier"
                        id="sale-cashier"
                        :label="$cashierTerm"
                        :options="$cashiers"
                        option-value="id"
                        option-label="name"
                        :value="request('cashier')"
                        :placeholder="'All ' . Str::lower(term('cashier', true))"
                        :search-placeholder="'Type to filter ' . Str::lower(term('cashier', true))"
                    />
                </div>
            @endif

            <div>
                <label for="sale-status" class="{{ $filterLabel }}">Status</label>
                <select id="sale-status" name="status" class="{{ $filterInput }}">
                    <option value="">Any status</option>
                    @foreach (['completed' => 'Completed', 'partially_refunded' => 'Partially refunded', 'refunded' => 'Refunded', 'voided' => 'Voided'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="sale-payment" class="{{ $filterLabel }}">Payment</label>
                <select id="sale-payment" name="payment_method" class="{{ $filterInput }}">
                    <option value="">Any payment</option>
                    @foreach (['cash' => 'Cash', 'pos' => 'POS', 'transfer' => 'Transfer'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('payment_method') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <button type="submit"
                    class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                Filter
            </button>
        </form>
    </x-card>

    {{-- Summary --}}
    <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-stat label="{{ $salesTerm }} (excl. voided)" :value="number_format($summary['count'])" icon="receipt" />
        <x-stat label="Total sold" :value="money($summary['total'])" icon="coins" />
        <x-stat label="Refunded" :value="money($summary['refunded'])" icon="undo" invert
                :trend="$summary['refunded'] > 0 ? 'up' : null" />
    </div>

    <x-card :padding="false">
        <x-table>
            <x-slot:head>
                <tr>
                    <th>Invoice</th>
                    <th>Date</th>
                    <th>{{ $cashierTerm }}</th>
                    <th>Payment</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Refunded</th>
                    <th>Status</th>
                    <th><span class="sr-only">Actions</span></th>
                </tr>
            </x-slot:head>

            @forelse ($sales as $sale)
                <tr>
                    <td class="whitespace-nowrap font-mono">{{ $sale->invoice_number }}</td>
                    <td class="whitespace-nowrap text-gray-500 dark:text-gray-400">
                        {{ $sale->created_at->format('d M Y H:i') }}
                    </td>
                    <td>{{ $sale->user->name ?? '-' }}</td>
                    <td>{{ ucfirst($sale->payment_method) }}</td>
                    <td class="text-right tabular-nums">{{ money($sale->grand_total) }}</td>
                    <td class="text-right tabular-nums">
                        {{ $sale->refunded_amount > 0 ? money($sale->refunded_amount) : '-' }}
                    </td>
                    <td>
                        @php [$variant, $label] = $statusBadge[$sale->status] ?? ['gray', ucfirst(str_replace('_', ' ', $sale->status))]; @endphp
                        <x-badge :variant="$variant" size="sm">{{ $label }}</x-badge>
                    </td>
                    <td class="text-right">
                        <a href="{{ route('sales.show', $sale) }}"
                           class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                            View
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-empty icon="receipt"
                                 :title="'No ' . Str::lower($salesTerm) . ' found'"
                                 :message="$manager
                                    ? 'Nothing matches these filters. Try a wider date range.'
                                    : 'Everything you ring up today shows here.'">
                            <x-slot:action>
                                <a href="{{ route('pos.index') }}"
                                   class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                                    Open the till
                                </a>
                            </x-slot:action>
                        </x-empty>
                    </td>
                </tr>
            @endforelse
        </x-table>

        @if ($sales->hasPages())
            <x-slot:footer>{{ $sales->links() }}</x-slot:footer>
        @endif
    </x-card>
@endsection
