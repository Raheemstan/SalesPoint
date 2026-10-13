@extends('layouts.app')

@section('title', 'Returns to ' . Str::lower(term('supplier', true, 'Suppliers')))

@section('content')
    @php
        $card = 'bg-white dark:bg-gray-800 rounded shadow p-4';
        $input = 'w-full rounded border border-gray-300 dark:border-gray-600 px-3 py-2 bg-white dark:bg-gray-700';
    @endphp

    <div class="space-y-4">

        <div class="{{ $card }} flex flex-wrap justify-between items-center gap-3">
            <div>
                <h2 class="text-lg font-semibold">Returns to {{ Str::lower(term('supplier', true, 'Suppliers')) }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Goods sent back: <strong>{{ money($total) }}</strong> credited across everything matching.
                </p>
            </div>
            <a href="{{ route('purchases.index') }}"
               class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm">
                {{ term('purchase', true, 'Purchases') }}
            </a>
        </div>

        {{-- Filters --}}
        <form method="GET" class="{{ $card }} grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Reference or invoice"
                       class="{{ $input }}">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    {{ term('supplier', false, 'Supplier') }}
                </label>
                <select name="supplier_id" class="{{ $input }}">
                    <option value="">All</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected(request('supplier_id') == $supplier->id)>
                            {{ $supplier->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">From</label>
                <input type="date" name="from" value="{{ request('from') }}" class="{{ $input }}">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">To</label>
                <input type="date" name="to" value="{{ request('to') }}" class="{{ $input }}">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">Filter</button>
                <a href="{{ route('purchases.returns.index') }}" class="px-4 py-2 text-sm text-gray-500">Reset</a>
            </div>
        </form>

        {{-- List --}}
        <div class="{{ $card }} overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-100 dark:bg-gray-700 text-left">
                    <tr>
                        <th class="p-2">Date</th>
                        <th class="p-2">Reference</th>
                        <th class="p-2">{{ term('supplier', false, 'Supplier') }}</th>
                        <th class="p-2">Against</th>
                        <th class="p-2">Items</th>
                        <th class="p-2">Reason</th>
                        <th class="p-2">By</th>
                        <th class="p-2 text-right">Credited</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($returns as $return)
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <td class="p-2 whitespace-nowrap">{{ $return->created_at->format('d M Y') }}</td>
                            <td class="p-2 font-mono text-xs">{{ $return->reference }}</td>
                            <td class="p-2">
                                @if ($return->supplier)
                                    <a href="{{ route('suppliers.show', $return->supplier) }}"
                                       class="text-blue-600 dark:text-blue-400 hover:underline">{{ $return->supplier->name }}</a>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="p-2">
                                @if ($return->purchase)
                                    <a href="{{ route('purchases.show', $return->purchase) }}"
                                       class="text-blue-600 dark:text-blue-400 hover:underline">{{ $return->purchase->invoice_number }}</a>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="p-2 text-xs text-gray-600 dark:text-gray-300">
                                @foreach ($return->items as $line)
                                    {{ $line->product->name ?? 'Deleted product' }} &times;{{ $line->quantity }}@if (!$loop->last), @endif
                                @endforeach
                            </td>
                            <td class="p-2">{{ $return->reason }}</td>
                            <td class="p-2">{{ $return->user->name ?? '-' }}</td>
                            <td class="p-2 text-right">{{ money($return->total_amount) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-4 text-center text-gray-500 dark:text-gray-400">
                                Nothing has been returned to a {{ Str::lower(term('supplier', false, 'Supplier')) }}.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div class="mt-4">{{ $returns->links() }}</div>
        </div>
    </div>
@endsection
