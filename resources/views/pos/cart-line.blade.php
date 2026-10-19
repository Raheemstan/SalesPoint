@php
    /*
    | One line of the cart.
    |
    | A partial of its own because the till needs it in two places: rendered by the
    | server as part of the cart panel, and cloned by the browser (from the <template>
    | on the POS page) to show a line the instant a product is tapped - before the
    | server has answered. Both are the same markup, so the provisional line cannot
    | drift from the real one.
    |
    | The data-* hooks are what resources/js/pos/till.js patches while a change is on
    | its way to the server: the line's price and total, and the stock it may not exceed.
    */
    $calc = $item['calc'];
    // False for a service: it has no stock level, so no figure and no warning.
    $tracksStock = $item['tracks_stock'] ?? true;
    $short = $tracksStock && $item['quantity'] > $item['stock'];
    $discounted = $calc['line_discount'] > 0;

    $small = 'min-h-touch rounded-lg border border-gray-300 bg-white px-2 py-1 text-sm
              dark:border-gray-600 dark:bg-gray-700 dark:text-white';
    $chip = 'min-h-touch rounded-lg bg-gray-100 px-3 text-sm font-medium text-gray-700 hover:bg-gray-200
             dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600';
@endphp

<div class="cart-line rounded-lg border border-gray-200 p-2.5 transition-colors dark:border-gray-700"
     data-line="{{ $item['product_id'] }}"
     data-stock="{{ $item['stock'] }}"
     data-tracks="{{ $tracksStock ? 1 : 0 }}"
     x-data="{ showDiscount: {{ $discounted ? 'true' : 'false' }} }">
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="font-semibold leading-snug text-gray-900 dark:text-gray-100">{{ $item['name'] }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                <span data-line-each>{{ money($item['price']) }}</span> each
                @if ($discounted)
                    · <span class="font-medium text-green-600 dark:text-green-400">
                        less <span data-line-discount>{{ money($calc['line_discount']) }}</span>
                    </span>
                @endif
            </p>
            @if ($short)
                <p class="mt-0.5 text-xs font-medium text-red-600 dark:text-red-400">
                    Only {{ $item['stock'] }} in stock
                </p>
            @endif
        </div>

        <div class="shrink-0 text-right">
            <p class="font-bold text-gray-900 dark:text-gray-100" data-line-total>{{ money($calc['paid_total']) }}</p>
            <form data-id="{{ $item['product_id'] }}" class="remove-cart-form">
                <button type="submit"
                        class="flex min-h-touch items-center justify-end gap-1 text-xs text-red-600 hover:underline dark:text-red-400">
                    <x-icon name="x" size="3" /> Remove
                </button>
            </form>
        </div>
    </div>

    {{-- Quantity: a stepper, because "one more" is the commonest edit and a keypad is slower. --}}
    <div class="mt-2 flex items-center gap-2">
        <form data-id="{{ $item['product_id'] }}" class="update-cart-form flex items-center gap-1.5">
            <button type="button" data-step="-1" aria-label="One less"
                    class="flex h-touch w-touch items-center justify-center rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                <x-icon name="minus" size="4" />
            </button>
            <input type="number" name="quantity" value="{{ $item['quantity'] }}" data-committed="{{ $item['quantity'] }}"
                   min="1" inputmode="numeric" enterkeyhint="done"
                   aria-label="Quantity for {{ $item['name'] }}"
                   class="h-touch w-16 rounded-lg border border-gray-300 bg-white text-center text-base dark:border-gray-600 dark:bg-gray-700 dark:text-white">
            <button type="button" data-step="1" aria-label="One more"
                    class="flex h-touch w-touch items-center justify-center rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                <x-icon name="plus" size="4" />
            </button>
        </form>

        <button type="button" @click="showDiscount = !showDiscount"
                class="ml-auto flex min-h-touch items-center gap-1 rounded-lg px-2 text-xs font-medium
                       {{ $discounted ? 'text-green-700 dark:text-green-400' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400' }}">
            <x-icon name="coins" size="4" />
            {{ $discounted ? 'Discounted' : 'Discount' }}
        </button>
    </div>

    {{-- Folded away until asked for, so a plain line stays readable. --}}
    <form data-id="{{ $item['product_id'] }}" class="line-discount-form mt-2 flex items-center gap-1.5"
          x-show="showDiscount" x-cloak>
        <input type="number" name="value" step="0.01" min="0" placeholder="0" inputmode="decimal"
               value="{{ $item['discount_value'] }}" aria-label="Discount for {{ $item['name'] }}"
               class="w-20 {{ $small }}">
        <select name="type" aria-label="Discount type" class="{{ $small }}">
            <option value="percent" @selected(($item['discount_type'] ?? 'percent') === 'percent')>%</option>
            <option value="fixed" @selected(($item['discount_type'] ?? null) === 'fixed')>{{ currency() }}</option>
        </select>
        <button type="submit" class="{{ $chip }}">Apply</button>
        <button type="button" data-clear-discount
                class="min-h-touch px-2 text-xs text-gray-500 hover:underline dark:text-gray-400">Off</button>
    </form>
</div>
