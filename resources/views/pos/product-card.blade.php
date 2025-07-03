<div
    class="border border-gray-200 dark:border-gray-600 rounded-lg p-3 bg-white dark:bg-gray-700 hover:bg-blue-50 dark:hover:bg-gray-600 transition">
    <h3 class="font-bold text-gray-800 dark:text-gray-100">{{ $product->name }}</h3>
    <p class="text-sm text-gray-600 dark:text-gray-300">₦{{ number_format($product->sale_price, 2) }}</p>
    <p class="text-xs text-gray-500 dark:text-gray-400">Stock: {{ $product->stock_quantity }}</p>

    <form method="POST" action="{{ route('pos.add', $product->id) }}" class="mt-2 add-to-cart-form"
        data-id="{{ $product->id }}">
        @csrf
        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-md">
            Add
        </button>
    </form>
</div>