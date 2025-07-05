@extends('layouts.app')

@section('title', 'Purchase Orders')

@section('content')
    <div class="bg-white dark:bg-gray-800 shadow rounded p-6 space-y-6 text-sm text-gray-800 dark:text-gray-100">

        {{-- Header --}}
        <div class="flex justify-between items-center mb-4">
            <a href="{{ route('purchases.create') }}"
                class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm">
                + New Purchase
            </a>
        </div>

        {{-- Filters --}}
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <x-input type="text" name="search" value="{{ request('search') }}" placeholder="Search Invoice..."
                class="border rounded px-2 py-1 w-full" />

            <x-select name="supplier_id" label="All Suppliers" :options="$suppliers"
                option-label="name" option-value="id" placeholder="-- Select Supplier --"
                :selected="request('supplier_id')" class="w-full" />

            <x-input type="date" name="from_date" value="{{ request('from_date') }}" class="border rounded px-2 py-1 w-full"
                placeholder="From Date" />
            <x-input type="date" name="to_date" value="{{ request('to_date') }}" class="border rounded px-2 py-1 w-full"
                placeholder="To Date" />

            <div class="md:col-span-4 text-right">
                <button type="submit" class="bg-blue-600 text-white px-4 py-1 rounded">Filter</button>
                <a href="{{ route('purchases.index') }}" class="text-sm text-gray-500 ml-2">Reset</a>
            </div>
        </form>

        {{-- Table --}}
        <div class="overflow-x-auto">
            <table class="w-full table-auto border text-sm">
                <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                    <tr>
                        <th class="p-2 text-left">Invoice</th>
                        <th class="p-2 text-left">Supplier</th>
                        <th class="p-2 text-left">Date</th>
                        <th class="p-2 text-left">Total</th>
                        <th class="p-2 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($purchases as $purchase)
                        <tr class="border-t dark:border-gray-700">
                            <td class="p-2">{{ $purchase->invoice_number }}</td>
                            <td class="p-2">{{ $purchase->supplier->name ?? '-' }}</td>
                            <td class="p-2">{{ \Carbon\Carbon::parse($purchase->purchase_date)->format('d M Y') }}</td>
                            <td class="p-2">₦{{ number_format($purchase->total_amount, 2) }}</td>
                            <td class="p-2">
                                <a href="{{ route('purchases.show', $purchase->id) }}"
                                    class="text-blue-600 hover:underline">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-4 text-gray-500">No purchases found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="mt-4">
            {{ $purchases->withQueryString()->links() }}
        </div>
    </div>
@endsection