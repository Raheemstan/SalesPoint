@extends('layouts.app')

@section('title', 'Point of Sale')

@section('content')
    <div class="grid grid-cols-3 gap-4">

        {{-- Products Section --}}
        <div class="col-span-2 bg-white dark:bg-gray-800 shadow rounded p-4">
            <h2 class="text-2xl font-semibold mb-4 text-gray-800 dark:text-gray-100">Products</h2>

            <div class="col-span-2 bg-white dark:bg-gray-800 shadow rounded p-4">

                {{-- Search --}}
                <input type="text" id="product-search" placeholder="Search products by name, SKU, or barcode..."
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-2 bg-white dark:bg-gray-700 mb-4 focus:ring-2 focus:ring-blue-500 focus:outline-none">

                {{-- Products Grid --}}
                <div id="product-list" class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    @foreach($products as $product)
                        @include('partials.product-card', ['product' => $product])
                    @endforeach
                </div>
            </div>

        </div>

        {{-- Cart Section --}}
        <div class="col-span-1 bg-white dark:bg-gray-800 shadow rounded p-4">
            <h2 class="text-xl font-semibold mb-4 text-gray-800 dark:text-gray-100">Cart</h2>

            {{-- Cart Items --}}
            @forelse($cart as $item)
                <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-600 pb-2 mb-3">
                    <div>
                        <p class="font-semibold text-gray-800 dark:text-gray-100">{{ $item['name'] }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            ₦{{ number_format($item['price'], 2) }} x {{ $item['quantity'] }} =
                            ₦{{ number_format($item['price'] * $item['quantity'], 2) }}
                        </p>

                        {{-- Update Quantity --}}
                        <form action="{{ route('pos.update', $item['product_id']) }}" method="POST" class="flex gap-2 mt-1">
                            @csrf
                            <input type="number" name="quantity" value="{{ $item['quantity'] }}" min="1"
                                class="w-16 rounded-md border border-gray-300 dark:border-gray-600 px-2 py-1 bg-white dark:bg-gray-700 focus:ring-1 focus:ring-blue-500">
                            <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white px-2 py-1 rounded-md">
                                Update
                            </button>
                        </form>
                    </div>

                    {{-- Remove --}}
                    <form action="{{ route('pos.remove', $item['product_id']) }}" method="POST">
                        @csrf
                        <button type="submit" class="text-red-500 hover:underline">
                            🗑️ Remove
                        </button>
                    </form>
                </div>
            @empty
                <p class="text-gray-500 dark:text-gray-400">Cart is empty.</p>
            @endforelse

            @if(!empty($cart))
                {{-- Totals --}}
                <div class="border-t border-gray-200 dark:border-gray-600 pt-3 mt-3">
                    @php
                        $subtotal = collect($cart)->sum(fn($item) => $item['price'] * $item['quantity']);
                        $tax = 0;
                        $discount = 0;
                        $total = ($subtotal + $tax) - $discount;
                    @endphp

                    <div class="space-y-1 text-sm">
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
                            <button type="submit"
                                class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md">
                                ✅ Complete Sale
                            </button>
                        </div>
                    </form>

                    {{-- Clear Cart --}}
                    <form action="{{ route('pos.clear') }}" method="POST" class="mt-2">
                        @csrf
                        <button type="submit" class="w-full text-red-500 hover:underline">
                            🗑️ Clear Cart
                        </button>
                    </form>
                </div>
            @endif
        </div>

    </div>
@endsection

@section('scripts')
    <script>
        document.getElementById('product-search').addEventListener('input', function () {
            let query = this.value;

            fetch(`/pos/search?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(products => {
                    let productList = document.getElementById('product-list');
                    productList.innerHTML = '';

                    if (products.length === 0) {
                        productList.innerHTML = '<p class="text-gray-500 dark:text-gray-400 col-span-4">No products found.</p>';
                        return;
                    }

                    products.forEach(product => {
                        productList.innerHTML += `
                            <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-3 bg-white dark:bg-gray-700 hover:bg-blue-50 dark:hover:bg-gray-600 transition">
                                <h3 class="font-bold text-gray-800 dark:text-gray-100">${product.name}</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-300">₦${Number(product.sale_price).toFixed(2)}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Stock: ${product.stock_quantity}</p>

                                <form action="/pos/add/${product.id}" method="POST" class="mt-2">
                                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                    <button type="submit"
                                        class="w-full bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-md">
                                        Add
                                    </button>
                                </form>
                            </div>
                        `;
                    });
                });
        });
    </script>

@endsection