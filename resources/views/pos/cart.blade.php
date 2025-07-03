<h2 class="text-xl font-semibold mb-4 text-gray-800 dark:text-gray-100">Cart</h2>

@php
    $subtotal = collect($cart)->sum(fn($item) => $item['price'] * $item['quantity']);
    $tax = 0;
    $discount = 0;
    $total = ($subtotal + $tax) - $discount;
@endphp

{{-- Cart Items --}}
@forelse($cart as $item)
    <div class="flex justify-between items-start border-b border-gray-200 dark:border-gray-600 pb-2 mb-3">
        <div>
            <p class="font-semibold text-gray-800 dark:text-gray-100">{{ $item['name'] }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                ₦{{ number_format($item['price'], 2) }} x {{ $item['quantity'] }} =
                ₦{{ number_format($item['price'] * $item['quantity'], 2) }}
            </p>

            {{-- Update Quantity --}}
            <form data-id="{{ $item['product_id'] }}" class="update-cart-form flex gap-2 mt-1">
                <input type="number" name="quantity" value="{{ $item['quantity'] }}" min="1"
                    class="w-16 rounded-md border border-gray-300 dark:border-gray-600 px-2 py-1 bg-white dark:bg-gray-700 focus:ring-1 focus:ring-blue-500">
                <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white px-2 py-1 rounded-md">
                    Update
                </button>
            </form>
        </div>

        {{-- Remove --}}
        <form data-id="{{ $item['product_id'] }}" class="remove-cart-form mt-1">
            <button type="submit" class="text-red-500 hover:underline">🗑️ Remove</button>
        </form>
    </div>
@empty
    <p class="text-gray-500 dark:text-gray-400">Cart is empty.</p>
@endforelse

@if(!empty($cart))
    {{-- Totals --}}
    <div class="border-t border-gray-200 dark:border-gray-600 pt-3 mt-3 space-y-1 text-sm">
        <p class="flex justify-between text-gray-700 dark:text-gray-300">
            <span>Subtotal:</span>
            <span>₦{{ number_format($subtotal, 2) }}</span>
        </p>
        <p class="flex justify-between text-gray-700 dark:text-gray-300">
            <span>Tax:</span>
            <span>₦{{ number_format($tax, 2) }}</span>
        </p>
        <p class="flex justify-between text-gray-700 dark:text-gray-300">
            <span>Discount:</span>
            <span>₦{{ number_format($discount, 2) }}</span>
        </p>
        <p class="flex justify-between font-bold text-lg text-gray-800 dark:text-gray-100">
            <span>Total:</span>
            <span>₦{{ number_format($total, 2) }}</span>
        </p>
    </div>

    {{-- Payment --}}
    <form action="{{ route('pos.checkout') }}" method="POST" class="mt-4 space-y-2">
        @csrf

        <div>
            <label class="block mb-1 text-sm text-gray-700 dark:text-gray-300">Payment Method</label>
            <select name="payment_method"
                class="w-full rounded-md border border-gray-300 dark:border-gray-600 px-2 py-1 bg-white dark:bg-gray-700 focus:ring-1 focus:ring-blue-500">
                <option value="cash">Cash</option>
                <option value="pos">POS</option>
                <option value="transfer">Transfer</option>
            </select>
        </div>

        <div>
            <label class="block mb-1 text-sm text-gray-700 dark:text-gray-300">Amount Paid</label>
            <input type="number" step="0.01" name="paid_amount" required
                class="w-full rounded-md border border-gray-300 dark:border-gray-600 px-2 py-1 bg-white dark:bg-gray-700 focus:ring-1 focus:ring-blue-500">
        </div>

        <input type="hidden" name="tax" value="{{ $tax }}">
        <input type="hidden" name="discount" value="{{ $discount }}">

        <div class="flex gap-2">
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md">
                ✅ Complete Sale
            </button>
        </div>
    </form>

    {{-- Clear Cart --}}
    <form class="clear-cart-form mt-2">
        <button type="submit" class="w-full text-red-500 hover:underline">🗑️ Clear Cart</button>
    </form>
@endif