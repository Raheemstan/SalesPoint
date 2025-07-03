@extends('layouts.app')

@section('title', 'Point of Sale')

@section('content')
    <div class="grid grid-cols-3 gap-4">
        {{-- Products Section --}}
        <div class="col-span-2 bg-white dark:bg-gray-800 shadow rounded p-4">
            <h2 class="text-2xl font-semibold mb-4 text-gray-800 dark:text-gray-100">Products</h2>

            <input type="text" id="product-search" placeholder="Search products by name, SKU, or barcode..."
                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-2 bg-white dark:bg-gray-700 mb-4 focus:ring-2 focus:ring-blue-500 focus:outline-none">

            <div id="product-list" class="grid grid-cols-2 md:grid-cols-4 gap-4">
                @foreach($products as $product)
                    @include('pos.product-card', ['product' => $product])
                @endforeach
            </div>
        </div>

        {{-- Cart Section --}}
        <div id="cart-section" class="col-span-1 bg-white dark:bg-gray-800 shadow rounded p-4">
            @include('pos.cart')
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const productSearch = document.getElementById('product-search');

            // Live Product Search
            productSearch.addEventListener('input', function () {
                fetch(`/pos/search?q=${encodeURIComponent(this.value)}`)
                    .then(res => res.json())
                    .then(products => {
                        const list = document.getElementById('product-list');
                        list.innerHTML = '';

                        if (products.length === 0) {
                            list.innerHTML = '<p class="text-gray-500 dark:text-gray-400 col-span-4">No products found.</p>';
                            return;
                        }

                        products.forEach(product => {
                            list.innerHTML += `
                                    <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-3 bg-white dark:bg-gray-700 hover:bg-blue-50 dark:hover:bg-gray-600 transition">
                                        <h3 class="font-bold text-gray-800 dark:text-gray-100">${product.name}</h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-300">₦${parseFloat(product.sale_price).toFixed(2)}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Stock: ${product.stock_quantity}</p>
                                        <form method="POST" action="/pos/add/${product.id}" class="mt-2 add-to-cart-form" data-id="${product.id}">
                                            <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-md">
                                                Add
                                            </button>
                                        </form>
                                    </div>
                                `;
                        });

                        initAddToCartHandlers(); // Rebind add-to-cart
                    });
            });

            // AJAX Add to Cart
            function initAddToCartHandlers() {
                document.querySelectorAll('.add-to-cart-form').forEach(form => {
                    form.addEventListener('submit', function (e) {
                        e.preventDefault();
                        const id = this.dataset.id;

                        fetch(`/pos/add/${id}`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            }
                        }).then(() => reloadCart());
                    });
                });
            }

            // AJAX Reload Cart
            function reloadCart() {
                fetch(`{{ route('pos.index') }}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                    .then(res => res.text())
                    .then(html => {
                        document.getElementById('cart-section').innerHTML = html;
                        initCartListeners();
                    });
            }

            // AJAX Cart Listeners
            function initCartListeners() {
                document.querySelectorAll('.update-cart-form').forEach(form => {
                    form.addEventListener('submit', function (e) {
                        e.preventDefault();
                        const id = this.dataset.id;
                        const qty = this.querySelector('input[name="quantity"]').value;

                        fetch(`/pos/update/${id}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            },
                            body: JSON.stringify({ quantity: qty })
                        }).then(() => reloadCart());
                    });
                });

                document.querySelectorAll('.remove-cart-form').forEach(form => {
                    form.addEventListener('submit', function (e) {
                        e.preventDefault();
                        const id = this.dataset.id;

                        fetch(`/pos/remove/${id}`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            }
                        }).then(() => reloadCart());
                    });
                });

                const clearCartForm = document.querySelector('.clear-cart-form');
                if (clearCartForm) {
                    clearCartForm.addEventListener('submit', function (e) {
                        e.preventDefault();
                        fetch(`/pos/clear`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            }
                        }).then(() => reloadCart());
                    });
                }
            }

            // Initialize everything
            initAddToCartHandlers();
            initCartListeners();
        });
    </script>
@endsection