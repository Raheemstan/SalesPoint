@extends('layouts.app')
@section('title', 'Sales')

@php
    $badge = [
        'completed' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        'partially_refunded' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        'refunded' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
        'voided' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    ];
    $input = 'rounded-md border border-gray-300 dark:border-gray-600 px-2 py-1 bg-white dark:bg-gray-700';
@endphp

@section('content')
    <div class="bg-white dark:bg-gray-800 p-6 rounded shadow-sm text-sm">
        <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100 mb-4">
            {{ $manager ? 'Sales History' : "My Sales Today" }}
        </h2>

        <form method="GET" class="flex flex-wrap gap-3 mb-6 items-end">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Invoice number" class="{{ $input }}">

            @if ($manager)
                <label class="text-xs text-gray-500">From <input type="date" name="from" value="{{ $from }}" class="{{ $input }}"></label>
                <label class="text-xs text-gray-500">To <input type="date" name="to" value="{{ $to }}" class="{{ $input }}"></label>
                <select name="cashier" class="{{ $input }}">
                    <option value="">All cashiers</option>
                    @foreach ($cashiers as $c)
                        <option value="{{ $c->id }}" @selected(request('cashier') == $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            @endif

            <select name="status" class="{{ $input }}">
                <option value="">Any status</option>
                @foreach (['completed' => 'Completed', 'partially_refunded' => 'Partially refunded', 'refunded' => 'Refunded', 'voided' => 'Voided'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="payment_method" class="{{ $input }}">
                <option value="">Any payment</option>
                @foreach (['cash' => 'Cash', 'pos' => 'POS', 'transfer' => 'Transfer'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('payment_method') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded">Filter</button>
        </form>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-blue-50 dark:bg-blue-900 p-3 rounded"><p class="text-xs">Sales (excl. voided)</p><p class="text-lg font-bold">{{ $summary['count'] }}</p></div>
            <div class="bg-green-50 dark:bg-green-900 p-3 rounded"><p class="text-xs">Total sold</p><p class="text-lg font-bold">{{ money($summary['total']) }}</p></div>
            <div class="bg-orange-50 dark:bg-orange-900 p-3 rounded"><p class="text-xs">Refunded</p><p class="text-lg font-bold">{{ money($summary['refunded']) }}</p></div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-gray-100 dark:bg-gray-700">
                        <th class="p-2">Invoice</th>
                        <th class="p-2">Date</th>
                        <th class="p-2">Cashier</th>
                        <th class="p-2">Payment</th>
                        <th class="p-2 text-right">Total</th>
                        <th class="p-2 text-right">Refunded</th>
                        <th class="p-2">Status</th>
                        <th class="p-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sales as $sale)
                        <tr class="border-b border-gray-200 dark:border-gray-600">
                            <td class="p-2 font-mono">{{ $sale->invoice_number }}</td>
                            <td class="p-2">{{ $sale->created_at->format('d M Y H:i') }}</td>
                            <td class="p-2">{{ $sale->user->name ?? '-' }}</td>
                            <td class="p-2">{{ ucfirst($sale->payment_method) }}</td>
                            <td class="p-2 text-right">{{ money($sale->grand_total) }}</td>
                            <td class="p-2 text-right">{{ $sale->refunded_amount > 0 ? money($sale->refunded_amount) : '-' }}</td>
                            <td class="p-2">
                                <span class="px-2 py-0.5 rounded text-xs {{ $badge[$sale->status] ?? '' }}">{{ ucfirst(str_replace('_', ' ', $sale->status)) }}</span>
                            </td>
                            <td class="p-2"><a href="{{ route('sales.show', $sale) }}" class="text-blue-600 hover:underline">View</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-4 text-center text-gray-500">No sales found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $sales->links() }}</div>
    </div>
@endsection
