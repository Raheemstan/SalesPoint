@extends('layouts.app')

@section('title', 'Returns to ' . Str::lower(term('supplier', true, 'Suppliers')))

@section('content')
    @php
        $supplierLabel = term('supplier', false, 'Supplier');
        $suppliersLabel = term('supplier', true, 'Suppliers');
        $productLabel = term('product', false, 'Product');

        $filterInput = 'min-h-touch w-full rounded-md border-gray-300 bg-white text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white';
        $filterLabel = 'mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400';
    @endphp

    <x-page-header :title="'Returns to ' . Str::lower($suppliersLabel)"
                   subtitle="Goods sent back, and what was credited for them"
                   :back="route('purchases.index')"
                   :back-label="term('purchase', true, 'Purchases')" />

    <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-stat label="Credited" :value="money($total)" icon="undo"
                hint="across everything matching" />
        <x-stat label="Returns listed" :value="number_format($returns->total())" icon="truck" />
    </div>

    {{-- Filters --}}
    <x-card class="mb-4">
        <form method="GET" class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label for="return-search" class="{{ $filterLabel }}">Search</label>
                <input id="return-search" type="search" name="search" value="{{ request('search') }}"
                       placeholder="Reference or invoice" class="{{ $filterInput }}">
            </div>

            {{-- searchable: the same long supplier list as everywhere else. --}}
            <div class="[&>div]:mb-0">
                <x-select name="supplier_id" :label="$supplierLabel" searchable
                          :options="$suppliers" option-label="name" option-value="id"
                          placeholder="All" :selected="request('supplier_id')" />
            </div>

            <div>
                <label for="return-from" class="{{ $filterLabel }}">From</label>
                <input id="return-from" type="date" name="from" value="{{ request('from') }}" class="{{ $filterInput }}">
            </div>

            <div>
                <label for="return-to" class="{{ $filterLabel }}">To</label>
                <input id="return-to" type="date" name="to" value="{{ request('to') }}" class="{{ $filterInput }}">
            </div>

            <div class="flex items-end gap-2">
                <button type="submit"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    Filter
                </button>
                <a href="{{ route('purchases.returns.index') }}"
                   class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                    Reset
                </a>
            </div>
        </form>
    </x-card>

    {{-- List --}}
    <x-card :padding="false">
        <x-table>
            <x-slot:head>
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>{{ $supplierLabel }}</th>
                    <th>Against</th>
                    <th>Items</th>
                    <th>Reason</th>
                    <th>By</th>
                    <th class="text-right">Credited</th>
                </tr>
            </x-slot:head>

            @forelse ($returns as $return)
                <tr>
                    <td class="whitespace-nowrap text-gray-500 dark:text-gray-400">
                        {{ $return->created_at->format('d M Y') }}
                    </td>
                    <td class="whitespace-nowrap font-mono text-xs">{{ $return->reference }}</td>
                    <td>
                        @if ($return->supplier)
                            <a href="{{ route('suppliers.show', $return->supplier) }}"
                               class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                {{ $return->supplier->name }}
                            </a>
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        @if ($return->purchase)
                            <a href="{{ route('purchases.show', $return->purchase) }}"
                               class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                {{ $return->purchase->invoice_number }}
                            </a>
                        @else
                            -
                        @endif
                    </td>
                    <td class="text-xs text-gray-600 dark:text-gray-300">
                        @foreach ($return->items as $line)
                            {{ $line->product->name ?? 'Deleted product' }} &times;{{ $line->quantity }}@if (!$loop->last), @endif
                        @endforeach
                    </td>
                    <td>{{ $return->reason }}</td>
                    <td class="whitespace-nowrap">{{ $return->user->name ?? '-' }}</td>
                    <td class="text-right tabular-nums">{{ money($return->total_amount) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-empty icon="undo" title="Nothing returned yet"
                                 :message="'Nothing has been returned to a ' . Str::lower($supplierLabel) . '.'">
                            <x-slot:action>
                                <a href="{{ route('purchases.index') }}"
                                   class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                                    {{ term('purchase', true, 'Purchases') }}
                                </a>
                            </x-slot:action>
                        </x-empty>
                    </td>
                </tr>
            @endforelse
        </x-table>

        @if ($returns->hasPages())
            <x-slot:footer>{{ $returns->links() }}</x-slot:footer>
        @endif
    </x-card>
@endsection
