@extends('layouts.app')

@section('title', 'Sales Report')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded p-6">

    {{-- Date Range Filter --}}
    <form method="GET" class="flex flex-wrap gap-4 mb-6">
        <div>
            <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Start Date</label>
            <input type="date" name="start_date" value="{{ $startDate }}"
                class="rounded border border-gray-300 dark:border-gray-600 px-3 py-2 bg-white dark:bg-gray-700">
        </div>
        <div>
            <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">End Date</label>
            <input type="date" name="end_date" value="{{ $endDate }}"
                class="rounded border border-gray-300 dark:border-gray-600 px-3 py-2 bg-white dark:bg-gray-700">
        </div>
        <div class="flex items-end">
            <button type="submit"
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                Filter
            </button>
        </div>
    </form>

    {{-- Summary --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-blue-600 text-white rounded p-4">
            <div class="text-sm">Total Sales</div>
            <div class="text-xl font-bold">₦{{ number_format($summary['total_sales'], 2) }}</div>
        </div>
        <div class="bg-green-600 text-white rounded p-4">
            <div class="text-sm">Total Tax</div>
            <div class="text-xl font-bold">₦{{ number_format($summary['total_tax'], 2) }}</div>
        </div>
        <div class="bg-yellow-500 text-white rounded p-4">
            <div class="text-sm">Total Discount</div>
            <div class="text-xl font-bold">₦{{ number_format($summary['total_discount'], 2) }}</div>
        </div>
        <div class="bg-gray-700 text-white rounded p-4">
            <div class="text-sm">Total Transactions</div>
            <div class="text-xl font-bold">{{ $summary['total_transactions'] }}</div>
        </div>
    </div>

    {{-- Sales Table --}}
    <div class="overflow-x-auto">
        <table class="w-full table-auto">
            <thead>
                <tr class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-100">
                    <th class="px-4 py-2 text-left">Date</th>
                    <th class="px-4 py-2 text-left">Invoice ID</th>
                    <th class="px-4 py-2 text-left">Items</th>
                    <th class="px-4 py-2 text-right">Total</th>
                    <th class="px-4 py-2 text-right">Tax</th>
                    <th class="px-4 py-2 text-right">Discount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($sales as $sale)
                    <tr class="border-b border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600">
                        <td class="px-4 py-2">{{ $sale->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-2">#{{ str_pad($sale->id, 6, '0', STR_PAD_LEFT) }}</td>
                        <td class="px-4 py-2">
                            <ul class="list-disc list-inside">
                                @foreach($sale->items as $item)
                                    <li>{{ $item->product->name }} (x{{ $item->quantity }})</li>
                                @endforeach
                            </ul>
                        </td>
                        <td class="px-4 py-2 text-right">₦{{ number_format($sale->grand_total, 2) }}</td>
                        <td class="px-4 py-2 text-right">₦{{ number_format($sale->tax, 2) }}</td>
                        <td class="px-4 py-2 text-right">₦{{ number_format($sale->discount, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-2 text-center text-gray-500 dark:text-gray-400">
                            No sales found for the selected period.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</div>
@endsection
