@extends('layouts.app')
@section('title', 'Sale ' . $sale->invoice_number)

@php
    $input = 'rounded-md border border-gray-300 dark:border-gray-600 px-2 py-1 bg-white dark:bg-gray-700';
    $canManage = auth()->user()->can('refund', $sale);
@endphp

@section('content')
    <div class="bg-white dark:bg-gray-800 p-6 rounded shadow-sm text-sm space-y-6">

        <div class="flex flex-wrap justify-between items-start gap-4">
            <div>
                <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100 font-mono">{{ $sale->invoice_number }}</h2>
                <p class="text-gray-500">
                    {{ $sale->created_at->format('d M Y H:i') }} · {{ $sale->user->name ?? '-' }} ·
                    {{ ucfirst($sale->payment_method) }} ·
                    <strong>{{ ucfirst(str_replace('_', ' ', $sale->status)) }}</strong>
                </p>
                @if ($sale->status === 'voided')
                    <p class="text-red-600 mt-1">
                        Voided {{ $sale->voided_at?->format('d M Y H:i') }} by {{ $sale->voider->name ?? '-' }}: {{ $sale->void_reason }}
                    </p>
                @endif
            </div>
            <div class="flex gap-3">
                {{--
                    Serial / IMEI capture (electronics). Route::has keeps this out
                    of the way of an install where the screen is not registered.
                --}}
                @if (feature('serials') && $canManage && Route::has('serials.edit'))
                    <a href="{{ route('serials.edit', $sale) }}" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                        Serial numbers
                    </a>
                @endif
                <a href="{{ route('pos.print', ['sale' => $sale->id, 'reprint' => 1]) }}" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded">🖨️ Reprint receipt</a>
                <a href="{{ route('sales.index') }}" class="px-4 py-2 rounded border">← Back</a>
            </div>
        </div>

        {{-- Totals --}}
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            <div class="p-3 rounded bg-gray-50 dark:bg-gray-700"><p class="text-xs">Subtotal</p><p class="font-bold">{{ money($sale->total_amount) }}</p></div>
            <div class="p-3 rounded bg-gray-50 dark:bg-gray-700"><p class="text-xs">Discount</p><p class="font-bold">{{ money($sale->discount_amount) }}</p></div>
            <div class="p-3 rounded bg-gray-50 dark:bg-gray-700"><p class="text-xs">Tax</p><p class="font-bold">{{ money($sale->tax_amount) }}</p></div>
            <div class="p-3 rounded bg-green-50 dark:bg-green-900"><p class="text-xs">Total</p><p class="font-bold">{{ money($sale->grand_total) }}</p></div>
            <div class="p-3 rounded bg-orange-50 dark:bg-orange-900"><p class="text-xs">Refunded</p><p class="font-bold">{{ money($sale->refunded_amount) }}</p></div>
        </div>

        {{-- Refund form wraps the items table so quantities can be picked right on the lines --}}
        <form method="POST" action="{{ route('sales.refund', $sale) }}" id="refund-form">
            @csrf
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-gray-100 dark:bg-gray-700">
                            <th class="p-2">Product</th>
                            <th class="p-2 text-right">Price</th>
                            <th class="p-2 text-right">Qty</th>
                            <th class="p-2 text-right">Paid</th>
                            <th class="p-2 text-right">Refunded</th>
                            @if ($canManage && $sale->isRefundable())
                                <th class="p-2">Refund qty</th>
                                <th class="p-2">Back to stock</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sale->items as $item)
                            <tr class="border-b border-gray-200 dark:border-gray-600">
                                <td class="p-2">{{ $item->product->name ?? 'Deleted product' }}</td>
                                <td class="p-2 text-right">{{ money($item->price) }}</td>
                                <td class="p-2 text-right">{{ $item->quantity }}</td>
                                <td class="p-2 text-right">{{ money($item->paid_total) }}</td>
                                <td class="p-2 text-right">{{ $item->refunded_quantity }} ({{ money($item->refunded_amount) }})</td>
                                @if ($canManage && $sale->isRefundable())
                                    <td class="p-2">
                                        <input type="number" name="items[{{ $item->id }}][quantity]" min="0"
                                            max="{{ $item->refundable_quantity }}" value="0"
                                            @disabled($item->refundable_quantity === 0) class="w-20 {{ $input }}">
                                    </td>
                                    <td class="p-2">
                                        <input type="checkbox" name="items[{{ $item->id }}][restock]" value="1" checked
                                            @disabled($item->refundable_quantity === 0)>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($canManage && $sale->isRefundable())
                <div class="mt-4 flex flex-wrap gap-3 items-end">
                    <input type="text" name="reason" placeholder="Reason (required)" value="{{ old('reason') }}" required minlength="3" class="w-64 {{ $input }}">
                    <select name="method" class="{{ $input }}">
                        <option value="cash">Refund via cash</option>
                        <option value="pos">Refund via POS</option>
                        <option value="transfer">Refund via transfer</option>
                    </select>
                    <button type="submit" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded">Refund selected items</button>
                </div>
                <p class="text-xs text-gray-500 mt-1">Untick "Back to stock" for damaged goods: the money is returned but the cost stays booked as a loss.</p>
            @endif
        </form>

        {{-- Void --}}
        @if ($canManage && $sale->status === 'completed')
            <form method="POST" action="{{ route('sales.void', $sale) }}" class="border-t pt-4 flex flex-wrap gap-3 items-end"
                  onsubmit="return confirm('Void this whole sale and return all items to stock?');">
                @csrf
                <input type="text" name="reason" placeholder="Reason for voiding (required)" required minlength="3" class="w-64 {{ $input }}">
                <select name="method" class="{{ $input }}">
                    <option value="cash">Refund via cash</option>
                    <option value="pos">Refund via POS</option>
                    <option value="transfer">Refund via transfer</option>
                </select>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">Void entire sale</button>
            </form>
        @endif

        {{-- History --}}
        @if ($sale->refunds->isNotEmpty())
            <div class="border-t pt-4">
                <h3 class="font-semibold mb-2">Refund history</h3>
                @foreach ($sale->refunds as $refund)
                    <div class="mb-3 p-3 rounded bg-gray-50 dark:bg-gray-700">
                        <p>
                            <strong>{{ money($refund->total_amount) }}</strong>
                            {{ $refund->is_void ? '(void)' : '' }} via {{ $refund->method }} ·
                            {{ $refund->created_at->format('d M Y H:i') }} · {{ $refund->user->name ?? '-' }}
                        </p>
                        <p class="text-gray-500">{{ $refund->reason }}</p>
                        <p class="text-xs">
                            @foreach ($refund->items as $ri)
                                {{ $ri->product->name ?? 'Deleted product' }} ×{{ $ri->quantity }}{{ $ri->restocked ? '' : ' (not restocked)' }}@if (!$loop->last), @endif
                            @endforeach
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection
