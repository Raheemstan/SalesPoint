@php
    /*
    | One item in the till's grid. Big enough to hit with a thumb: the whole card
    | is the button, not a small "Add" link inside it.
    |
    | A service (a haircut, a delivery fee, an hour of labour) has no stock level
    | at all, so it shows no figure and no warning. "Stock: 0" against a haircut
    | is both wrong and alarming.
    */
    $tracksStock = $product->tracksStock();
    $lowStockOn = setting('enable_low_stock_warning') === '1';
    $low = $tracksStock && $lowStockOn && $product->isLowStock();
    $soldOut = $tracksStock && (int) $product->stock_quantity <= 0;
@endphp

{{--
    The data-* attributes are what lets the till search this grid and add a line without a
    round trip: when the whole catalogue is on the page, typing in the search box only hides
    cards, and a tap shows the new line before the server has answered.
--}}
<form method="POST" action="{{ route('pos.add', $product->id) }}" class="add-to-cart-form"
      data-id="{{ $product->id }}"
      data-name="{{ $product->name }}"
      data-price="{{ (float) $product->sale_price }}"
      data-stock="{{ (int) $product->stock_quantity }}"
      data-tracks="{{ $tracksStock ? 1 : 0 }}"
      data-sku="{{ $product->sku }}"
      data-barcode="{{ $product->barcode }}"
      data-search="{{ mb_strtolower(trim($product->name . ' ' . $product->sku . ' ' . $product->barcode)) }}">
    @csrf
    <button type="submit" @disabled($soldOut)
            class="flex h-full min-h-touch w-full flex-col items-start gap-1 rounded-xl border border-gray-200 bg-white p-3
                   text-left transition hover:border-primary-400 hover:bg-primary-50 active:scale-[0.98]
                   disabled:cursor-not-allowed disabled:opacity-50
                   dark:border-gray-600 dark:bg-gray-700 dark:hover:border-primary-500 dark:hover:bg-gray-600">
        <span class="line-clamp-2 font-semibold leading-snug text-gray-800 dark:text-gray-100">{{ $product->name }}</span>

        <span class="text-base font-bold text-primary-700 dark:text-primary-300">{{ money($product->sale_price) }}</span>

        @if ($tracksStock)
            <span class="text-xs text-gray-500 dark:text-gray-400">
                {{ $soldOut ? 'Sold out' : $product->stock_quantity . ' in stock' }}
                @if ($low && !$soldOut)
                    <span class="font-semibold text-amber-600 dark:text-amber-400">(low)</span>
                @endif
            </span>
        @else
            <span class="text-xs text-gray-400 dark:text-gray-500">{{ term('service', false, 'Service') }}</span>
        @endif
    </button>
</form>
