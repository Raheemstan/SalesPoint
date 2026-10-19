@extends('layouts.app')

@section('title', 'Point of Sale')

@section('content')
    @php
        /*
        |--------------------------------------------------------------------------
        | The till
        |--------------------------------------------------------------------------
        |
        | Built for a tablet on a counter: two columns on a wide screen, stacked on
        | a phone, nothing smaller than a 2.75rem tap target, and a keyboard path
        | for every action so a till with a scanner and a numeric keypad never
        | needs the screen touched at all.
        |
        | The search box is the scanner's input. A barcode scanner is a keyboard
        | that types fast and presses Enter, so Enter means "add the exact code I
        | just scanned" - never "open a picker". Focus returns to that box after
        | every change, because the next thing to happen is almost always another
        | scan.
        */
    @endphp

    {{--
        Two panes that scroll on their own.

        <main> (the app shell) is what normally scrolls. Here the page fills it exactly and the
        two columns scroll inside it, so wheeling through a long product list never drags the
        sale along, and a long sale never pushes the catalogue off screen.

        Only from `lg` up, where the columns sit side by side. Below that they stack, and a
        stacked page is one that has to scroll as a whole.
    --}}
    <div class="flex flex-col lg:h-full lg:min-h-0">

        {{--
            Offline state, held sales and refusals. Rendered above everything else
            on purpose: a cashier has to know whether the till has a line *before*
            they start ringing something up, and a refused offline sale has to be
            the first thing on the screen.
        --}}
        <div class="shrink-0">
            @include('pos.offline')
        </div>

        {{-- Renders nothing when a shift is open; looks the shift up itself. --}}
        <div class="mb-4 shrink-0 empty:mb-0">
            @include('shifts.partials.no-open-shift', ['openShift' => $openShift])
        </div>

        <div class="grid grid-cols-1 gap-4 lg:min-h-0 lg:flex-1 lg:grid-cols-5 lg:grid-rows-[minmax(0,1fr)]">

            {{-- Catalogue: the header and search stay put, only the grid of products scrolls. --}}
            <section class="flex min-h-0 flex-col rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 sm:p-5 lg:col-span-3"
                     aria-label="{{ term('product', true) }}">
                <div class="flex shrink-0 flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        {{ term('product', true) }}
                    </h2>

                    <div class="flex items-center gap-2">
                        <a href="{{ route('pos.parked') }}"
                           class="flex min-h-touch items-center gap-1.5 rounded-lg bg-gray-100 px-3 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                            <x-icon name="pause" size="4" />
                            Held
                            @if ($parkedCount > 0)
                                <x-badge variant="amber" size="sm">{{ $parkedCount }}</x-badge>
                            @endif
                        </a>

                        <button type="button" @click="$dispatch('till-help')" aria-label="Keyboard shortcuts"
                                class="flex min-h-touch items-center gap-1.5 rounded-lg bg-gray-100 px-3 text-sm font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                            <x-icon name="info" size="4" />
                            <span class="hidden sm:inline">Shortcuts</span>
                        </button>
                    </div>
                </div>

                {{--
                    One box for both jobs: type a few letters to filter, or let the
                    scanner type a whole code and press Enter to add it outright.
                --}}
                <div class="relative mt-3 shrink-0">
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                        <x-icon name="search" size="5" />
                    </span>
                    <input type="text" id="product-search" autocomplete="off" autofocus
                           placeholder="Scan a barcode, or search by name or SKU"
                           aria-label="Scan or search {{ strtolower(term('product', true)) }}"
                           class="min-h-touch w-full rounded-xl border border-gray-300 bg-white py-3 pl-10 pr-3 text-base
                                  focus:border-primary-500 focus:ring-2 focus:ring-primary-200
                                  dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:ring-primary-900">
                </div>

                <p class="mt-1.5 shrink-0 text-xs text-gray-400 dark:text-gray-500">
                    Pick an item, type its quantity and press Enter. Arrow keys move through the list; F2 comes back here.
                </p>

                {{-- The only part of this column that scrolls. Capped on a stacked screen so the sale is never pages away. --}}
                <div id="product-list"
                     class="mt-3 grid max-h-[55vh] grid-cols-2 content-start gap-2 overflow-y-auto overscroll-contain pr-1 sm:grid-cols-3 lg:max-h-none lg:min-h-0 lg:flex-1 xl:grid-cols-4">
                    @foreach($products as $product)
                        @include('pos.product-card', ['product' => $product])
                    @endforeach
                </div>
            </section>

            {{-- The sale: its own scroll, so a long sale and the payment panel beneath it stay reachable. --}}
            <div class="min-h-0 lg:col-span-2 lg:overflow-y-auto lg:overscroll-contain">
                <div id="cart-section"
                     class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    @include('pos.cart')
                </div>
            </div>
        </div>
    </div>

    {{--
        What the browser clones to show a line the moment a product is tapped, before the server
        has answered. It is the real cart-line partial with placeholders, so it cannot drift from
        the lines the server renders. Never displayed itself: it lives in a <template>.
    --}}
    <template id="cart-line-template">
        @include('pos.cart-line', ['item' => [
            'product_id' => '__ID__',
            'name' => '__NAME__',
            'price' => 0,
            'quantity' => 1,
            'stock' => 0,
            'tracks_stock' => false,
            'discount_type' => null,
            'discount_value' => null,
            'calc' => ['line_discount' => 0, 'paid_total' => 0],
        ]])
    </template>

    {{--
        Everything the till script needs from the server. No URL is spelled out by hand in the
        script and no currency symbol is hardcoded. JSON in a script tag, escaped so a store or
        product name can never break out of it.
    --}}
    <script type="application/json" id="till-config">{!! json_encode([
        'pos' => url('/pos'),
        'cartUrl' => route('pos.index'),
        'searchUrl' => route('pos.search'),
        'scanUrl' => route('pos.scan'),
        'symbol' => currency(),
        'decimals' => (int) setting('currency_decimals', config('sectors.currency.decimals')),
        'lowStockOn' => setting('enable_low_stock_warning') === '1',
        'lowStockLimit' => (int) setting('low_stock_threshold', 5),
        'serviceLabel' => term('service', false, 'Service'),
        'noMatches' => 'No ' . strtolower(term('product', true)) . ' match that.',
        'catalogueComplete' => (bool) $catalogueComplete,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

    {{-- Shortcut cheat sheet. Opened with F1 or the button above. --}}
    <div x-data="{ open: false }" @till-help.window="open = !open" @keydown.escape.window="open = false">
        <div x-show="open" x-cloak x-transition.opacity class="fixed inset-0 z-modal flex items-end justify-center p-3 sm:items-center">
            <div class="absolute inset-0 bg-gray-900/50" @click="open = false" aria-hidden="true"></div>

            <div class="relative w-full max-w-md rounded-xl bg-white p-4 shadow-xl dark:bg-gray-800"
                 role="dialog" aria-modal="true" aria-label="Keyboard shortcuts">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Keyboard shortcuts</h3>
                    <button type="button" @click="open = false" aria-label="Close"
                            class="flex h-touch w-touch items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                        <x-icon name="x" size="4" />
                    </button>
                </div>

                <dl class="mt-3 divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    @foreach ([
                        'Enter' => 'Add the item; in a quantity box, confirm it and return to search',
                        '↑ ↓' => 'Move through the search results',
                        'Esc' => 'Empty the search box (or undo a quantity edit)',
                        'F2' => 'Back to the scan / search box',
                        'F4' => 'Hold this ' . strtolower(term('sale')),
                        'F8' => 'Clear the ' . strtolower(term('sale')),
                        'F9' => 'Take payment',
                        'F1' => 'This list',
                    ] as $key => $what)
                        <div class="flex items-center justify-between gap-3 py-2">
                            <dt>
                                <kbd class="rounded border border-gray-300 bg-gray-50 px-2 py-0.5 font-mono text-xs font-semibold text-gray-700 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200">{{ $key }}</kbd>
                            </dt>
                            <dd class="text-right text-gray-600 dark:text-gray-300">{{ $what }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </div>
@endsection
