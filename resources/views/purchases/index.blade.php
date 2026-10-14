@extends('layouts.app')

@section('title', term('purchase', true, 'Purchase Orders'))

@section('content')
    @php
        $label = term('purchase', false, 'Purchase');
        $supplierLabel = term('supplier', false, 'Supplier');

        $statusBadge = [
            \App\Models\Purchase::STATUS_ORDERED => 'amber',
            \App\Models\Purchase::STATUS_RECEIVED => 'green',
            \App\Models\Purchase::STATUS_CANCELLED => 'gray',
        ];

        $filterInput = 'min-h-touch w-full rounded-md border-gray-300 bg-white text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white';
        $filterLabel = 'mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400';
    @endphp

    <x-page-header :title="term('purchase', true, 'Purchase Orders')"
                   subtitle="What has been ordered from whom, and what is still owed on it">
        <x-slot:actions>
            <a href="{{ route('suppliers.index') }}"
               class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                <x-icon name="truck" size="4" />
                {{ term('supplier', true, 'Suppliers') }}
            </a>

            <a href="{{ route('purchases.returns.index') }}"
               class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                <x-icon name="undo" size="4" />
                Returns
            </a>

            <a href="{{ route('purchases.create') }}"
               class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                <x-icon name="plus" size="4" />
                New {{ $label }}
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Filters --}}
    <x-card class="mb-4">
        <form method="GET" class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label for="purchase-search" class="{{ $filterLabel }}">Search</label>
                <input id="purchase-search" type="search" name="search" value="{{ request('search') }}"
                       placeholder="Search Invoice..." class="{{ $filterInput }}">
            </div>

            {{-- searchable: a wholesaler's supplier list is far too long to scroll. --}}
            <div class="[&>div]:mb-0">
                <x-select name="supplier_id" :label="'All ' . term('supplier', true, 'Suppliers')" searchable
                          :options="$suppliers" option-label="name" option-value="id"
                          :placeholder="'-- Select ' . $supplierLabel . ' --'"
                          :selected="request('supplier_id')" />
            </div>

            <div>
                <label for="purchase-status" class="{{ $filterLabel }}">Status</label>
                <select id="purchase-status" name="status" class="{{ $filterInput }}">
                    <option value="">-- Any status --</option>
                    @foreach (['ordered' => 'Ordered', 'received' => 'Received', 'cancelled' => 'Cancelled'] as $value => $text)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $text }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="purchase-from" class="{{ $filterLabel }}">From Date</label>
                <input id="purchase-from" type="date" name="from_date" value="{{ request('from_date') }}"
                       class="{{ $filterInput }}">
            </div>

            <div>
                <label for="purchase-to" class="{{ $filterLabel }}">To Date</label>
                <input id="purchase-to" type="date" name="to_date" value="{{ request('to_date') }}"
                       class="{{ $filterInput }}">
            </div>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-5 lg:justify-end">
                <button type="submit"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    Filter
                </button>
                <a href="{{ route('purchases.index') }}"
                   class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                    Reset
                </a>
            </div>
        </form>
    </x-card>

    <x-card :padding="false">
        <x-table>
            <x-slot:head>
                <tr>
                    <th>Invoice</th>
                    <th>{{ $supplierLabel }}</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Outstanding</th>
                    <th><span class="sr-only">Actions</span></th>
                </tr>
            </x-slot:head>

            @forelse($purchases as $purchase)
                <tr>
                    <td class="whitespace-nowrap font-mono">{{ $purchase->invoice_number }}</td>
                    <td>{{ $purchase->supplier->name ?? '-' }}</td>
                    <td class="whitespace-nowrap text-gray-500 dark:text-gray-400">
                        {{ \Carbon\Carbon::parse($purchase->purchase_date)->format('d M Y') }}
                    </td>
                    <td>
                        <x-badge :variant="$statusBadge[$purchase->status] ?? 'gray'" size="sm">
                            {{ ucfirst($purchase->status) }}
                        </x-badge>
                    </td>
                    <td class="text-right tabular-nums">{{ money($purchase->total_amount) }}</td>
                    <td class="text-right tabular-nums">
                        @if ($purchase->balance_due > 0)
                            <span class="font-medium text-amber-700 dark:text-amber-400">{{ money($purchase->balance_due) }}</span>
                        @else
                            {{ money($purchase->balance_due) }}
                        @endif
                    </td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('purchases.show', $purchase->id) }}"
                           class="font-medium text-primary-600 hover:underline dark:text-primary-400">View</a>
                        <a href="{{ route('purchases.edit', $purchase->id) }}"
                           class="ml-3 font-medium text-primary-600 hover:underline dark:text-primary-400">Edit</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-empty icon="truck"
                                 :title="'No ' . Str::lower(term('purchase', true, 'purchases')) . ' found'"
                                 message="Raise one when you order goods, or type in a delivery that has arrived.">
                            <x-slot:action>
                                <a href="{{ route('purchases.create') }}"
                                   class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                                    <x-icon name="plus" size="4" />
                                    New {{ $label }}
                                </a>
                            </x-slot:action>
                        </x-empty>
                    </td>
                </tr>
            @endforelse
        </x-table>

        @if ($purchases->hasPages())
            <x-slot:footer>{{ $purchases->withQueryString()->links() }}</x-slot:footer>
        @endif
    </x-card>
@endsection
