@extends('layouts.app')

@section('title', 'Purchase Details')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded p-6 space-y-6 text-sm text-gray-800 dark:text-gray-100">

    {{-- Header --}}
    <div class="flex justify-between items-center">
        <h2 class="text-lg font-semibold">Purchase Details - {{ $purchase->invoice_number }}</h2>
        <div class="space-x-2">
            <a href="{{ route('purchases.index') }}" class="bg-gray-300 dark:bg-gray-700 text-gray-900 dark:text-white px-4 py-2 rounded">Back</a>
            <a href="{{ route('purchases.edit', $purchase->id) }}" class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded">Edit</a>
            <form action="{{ route('purchases.destroy', $purchase->id) }}" method="POST" class="inline-block"
                onsubmit="return confirm('Are you sure you want to delete this purchase?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">Delete</button>
            </form>
        </div>
    </div>

    {{-- Supplier & Purchase Info --}}
    <div class="grid md:grid-cols-2 gap-6">
        <div>
            <h4 class="font-medium text-sm mb-1">Supplier</h4>
            <div class="text-gray-600 dark:text-gray-300">
                <strong>{{ $purchase->supplier->name }}</strong><br>
                {{ $purchase->supplier->phone }}<br>
                {{ $purchase->supplier->email }}<br>
                {{ $purchase->supplier->address }}
            </div>
        </div>
        <div>
            <h4 class="font-medium text-sm mb-1">Purchase Info</h4>
            <div class="text-gray-600 dark:text-gray-300">
                <strong>Invoice #:</strong> {{ $purchase->invoice_number }}<br>
                <strong>Date:</strong> {{ \Carbon\Carbon::parse($purchase->purchase_date)->format('d M Y') }}<br>
                <strong>Note:</strong> {{ $purchase->note ?? '-' }}<br>
                <strong>Total:</strong> ₦{{ number_format($purchase->total_amount, 2) }}
            </div>
        </div>
    </div>

    {{-- Line Items --}}
    <div>
        <h4 class="font-medium mb-2">Items Purchased</h4>
        <div class="overflow-x-auto">
            <table class="w-full table-auto border text-sm">
                <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">
                    <tr>
                        <th class="p-2">Product</th>
                        <th class="p-2">Quantity</th>
                        <th class="p-2">Cost Price</th>
                        <th class="p-2">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($purchase->purchaseItems as $item)
                        <tr class="border-b dark:border-gray-700">
                            <td class="p-2">{{ $item->product->name }}</td>
                            <td class="p-2">{{ $item->quantity }}</td>
                            <td class="p-2">₦{{ number_format($item->cost_price, 2) }}</td>
                            <td class="p-2">₦{{ number_format($item->total, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
