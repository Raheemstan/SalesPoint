@extends('layouts.app')

@section('title', 'Inventory Movement')

@section('content')
    <div class="bg-white dark:bg-gray-800 shadow rounded p-6">
        <h2 class="text-2xl font-semibold mb-4 text-gray-800 dark:text-gray-100">Inventory Movement</h2>

        {{-- Date Filter --}}
        <form method="GET" class="flex gap-4 mb-6">
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
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                    Filter
                </button>
            </div>
        </form>

        {{-- Movement Table --}}
        <div class="overflow-x-auto">
            <table class="w-full table-auto">
                <thead>
                    <tr class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-100">
                        <th class="px-4 py-2 text-left">Date</th>
                        <th class="px-4 py-2 text-left">Product</th>
                        <th class="px-4 py-2 text-right">Quantity</th>
                        <th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-left">Reference</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($movements as $sale)
                        @foreach($sale->items as $item)
                            <tr class="border-b border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600">
                                <td class="px-4 py-2">{{ $sale->created_at->format('d M Y H:i') }}</td>
                                <td class="px-4 py-2">{{ $item->product->name }}</td>
                                <td class="px-4 py-2 text-right">-{{ $item->quantity }}</td>
                                <td class="px-4 py-2">Sale</td>
                                <td class="px-4 py-2">Sale #{{ str_pad($sale->id, 6, '0', STR_PAD_LEFT) }}</td>
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-gray-500 dark:text-gray-400 py-4">
                                No inventory movement in selected period.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection