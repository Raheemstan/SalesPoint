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

    {{-- Renders nothing when a shift is open; looks the shift up itself. --}}
    <div class="mb-4">
        @include('shifts.partials.no-open-shift', ['openShift' => $openShift])
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-5">

        {{-- Catalogue --}}
        <div class="lg:col-span-3">
            <x-card>
                <div class="flex flex-wrap items-center justify-between gap-2">
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
                <div class="relative mt-3">
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

                <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">
                    Enter adds the exact barcode. F2 comes back here.
                </p>

                <div id="product-list" class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                    @foreach($products as $product)
                        @include('pos.product-card', ['product' => $product])
                    @endforeach
                </div>
            </x-card>
        </div>

        {{-- Cart. Sticky on a wide screen so the total stays in view while scrolling the grid. --}}
        <div class="lg:col-span-2">
            <div id="cart-section"
                 class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 lg:sticky lg:top-0 dark:bg-gray-800 dark:ring-gray-700">
                @include('pos.cart')
            </div>
        </div>
    </div>

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
                        'Enter' => 'Add the scanned barcode',
                        'F2' => 'Back to the scan / search box',
                        'F4' => 'Hold this ' . strtolower(term('sale')),
                        'F8' => 'Clear the ' . strtolower(term('sale')),
                        'F9' => 'Take payment',
                        'F1' => 'This list',
                        'Esc' => 'Empty the search box',
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

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const search = document.getElementById('product-search');
            const grid = document.getElementById('product-list');
            const cartSection = document.getElementById('cart-section');
            const token = document.querySelector('meta[name=csrf-token]').content;

            // Everything the server knows that this script needs. No URL is spelled
            // out by hand and no currency symbol is hardcoded.
            const pos = @js(url('/pos'));
            const cartUrl = @js(route('pos.index'));
            const searchUrl = @js(route('pos.search'));
            const scanUrl = @js(route('pos.scan'));
            const symbol = @js(currency());
            const decimals = @js((int) setting('currency_decimals', config('sectors.currency.decimals')));
            const lowStockOn = @js(setting('enable_low_stock_warning') === '1');
            const lowStockLimit = @js((int) setting('low_stock_threshold', 5));
            const serviceLabel = @js(term('service', false, 'Service'));
            const noMatches = @js('No ' . strtolower(term('product', true)) . ' match that.');

            // The grid as the server first rendered it, so emptying the search box
            // is instant rather than another round trip.
            const initialGrid = grid.innerHTML;

            // Names and SKUs are typed by people; never put one in innerHTML raw.
            const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[c]));

            const fmt = (v) => symbol + Number(v).toLocaleString(undefined, {
                minimumFractionDigits: decimals, maximumFractionDigits: decimals,
            });

            const debounce = (fn, ms = 250) => {
                let timer;
                return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), ms); };
            };

            /*
            |------------------------------------------------------------------
            | Scanner feedback
            |------------------------------------------------------------------
            |
            | A cashier scanning a trolley is looking at the goods, not the
            | screen, so the till has to answer with a sound: a short high blip
            | for a hit, two low notes for a code it does not know. Generated
            | with the Web Audio API rather than shipped as a file - a till
            | should not need a download to say "yes".
            |
            | Wrapped in try/catch throughout: a machine with no sound device,
            | or a browser that has not had a user gesture yet, must still sell.
            */
            let audio = null;

            function tone(frequency, ms, afterMs = 0) {
                try {
                    const Ctx = window.AudioContext || window.webkitAudioContext;
                    if (!Ctx) return;

                    audio = audio || new Ctx();
                    const at = audio.currentTime + afterMs / 1000;

                    const osc = audio.createOscillator();
                    const gain = audio.createGain();

                    osc.type = 'square';
                    osc.frequency.value = frequency;

                    // A flat square wave clicks on stop; fade it instead.
                    gain.gain.setValueAtTime(0.06, at);
                    gain.gain.exponentialRampToValueAtTime(0.0001, at + ms / 1000);

                    osc.connect(gain).connect(audio.destination);
                    osc.start(at);
                    osc.stop(at + ms / 1000);
                } catch (e) {
                    /* no audio: the toast still says what happened */
                }
            }

            const beepFound = () => tone(1760, 70);
            const beepUnknown = () => { tone(360, 130); tone(240, 200, 150); };

            function focusSearch() {
                search.focus();
                search.select();
            }

            /*
            |------------------------------------------------------------------
            | Talking to the server
            |------------------------------------------------------------------
            */
            async function tillPost(url, body) {
                let data = {};

                try {
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': token,
                        },
                        body: JSON.stringify(body || {}),
                    });

                    data = await res.json().catch(() => ({}));

                    if (!res.ok) {
                        window.toast(data.message || 'That did not work.', { variant: 'error' });
                        return data;
                    }
                } catch (e) {
                    window.toast('The till could not reach the server.', { variant: 'error' });
                    return data;
                }

                await reloadCart();
                return data;
            }

            // Re-render the cart panel from the server, which is the only thing that
            // knows what the sale now comes to.
            async function reloadCart(refocus = true) {
                try {
                    const res = await fetch(cartUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    cartSection.innerHTML = await res.text();
                } catch (e) {
                    window.toast('Could not refresh the cart.', { variant: 'error' });
                    return;
                }

                // Alpine picks up the new x-data trees on its own.
                if (refocus) focusSearch();
            }

            // The cart partial's Alpine components use these.
            window.reloadCart = reloadCart;
            window.tillPost = tillPost;

            /*
            |------------------------------------------------------------------
            | Scanning
            |------------------------------------------------------------------
            */
            async function scan() {
                const code = search.value.trim();

                if (code === '') return;

                const res = await fetch(scanUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token,
                    },
                    body: JSON.stringify({ code }),
                }).catch(() => null);

                if (!res) {
                    beepUnknown();
                    window.toast('The till could not reach the server.', { variant: 'error' });
                    return;
                }

                const data = await res.json().catch(() => ({}));

                if (data.found && data.success) {
                    beepFound();
                    search.value = '';
                    grid.innerHTML = initialGrid;
                    await reloadCart();
                    return;
                }

                // Known code, but it cannot be sold (out of stock): keep the code on
                // screen, because the cashier will want to look at it.
                beepUnknown();
                window.toast(data.message || 'Could not scan that code.',
                    { variant: data.found ? 'error' : 'warning' });

                if (!data.found) {
                    // Nothing matched exactly, so show what nearly did rather than
                    // leaving the cashier wondering if the scanner is broken.
                    renderProducts(data.matches || []);
                    search.select();
                }
            }

            async function runSearch() {
                const query = search.value.trim();

                if (query === '') {
                    grid.innerHTML = initialGrid;
                    return;
                }

                try {
                    const res = await fetch(`${searchUrl}?q=${encodeURIComponent(query)}`,
                        { headers: { 'Accept': 'application/json' } });
                    renderProducts(res.ok ? await res.json() : []);
                } catch (e) {
                    /* a failed filter is not worth a toast; the grid simply does not change */
                }
            }

            function renderProducts(products) {
                if (!products.length) {
                    grid.innerHTML = `<p class="col-span-full py-6 text-center text-gray-500 dark:text-gray-400">${esc(noMatches)}</p>`;
                    return;
                }

                grid.innerHTML = products.map(product => {
                    // A service has no stock level, so it shows no figure and no warning.
                    const tracks = !!product.track_stock && !product.is_service;
                    const stock = Number(product.stock_quantity);
                    const soldOut = tracks && stock <= 0;
                    const low = tracks && lowStockOn && stock <= lowStockLimit;

                    const note = !tracks
                        ? `<span class="text-xs text-gray-400 dark:text-gray-500">${esc(serviceLabel)}</span>`
                        : `<span class="text-xs text-gray-500 dark:text-gray-400">${soldOut ? 'Sold out' : esc(stock) + ' in stock'}`
                          + `${low && !soldOut ? ' <span class="font-semibold text-amber-600 dark:text-amber-400">(low)</span>' : ''}</span>`;

                    return `
                        <form method="POST" action="${pos}/add/${esc(product.id)}" class="add-to-cart-form" data-id="${esc(product.id)}">
                            <input type="hidden" name="_token" value="${esc(token)}">
                            <button type="submit" ${soldOut ? 'disabled' : ''}
                                    class="flex h-full min-h-touch w-full flex-col items-start gap-1 rounded-xl border border-gray-200 bg-white p-3
                                           text-left transition hover:border-primary-400 hover:bg-primary-50 active:scale-[0.98]
                                           disabled:cursor-not-allowed disabled:opacity-50
                                           dark:border-gray-600 dark:bg-gray-700 dark:hover:border-primary-500 dark:hover:bg-gray-600">
                                <span class="line-clamp-2 font-semibold leading-snug text-gray-800 dark:text-gray-100">${esc(product.name)}</span>
                                <span class="text-base font-bold text-primary-700 dark:text-primary-300">${esc(fmt(product.sale_price))}</span>
                                ${note}
                            </button>
                        </form>`;
                }).join('');
            }

            search.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    // Never submit a form or open a picker: add the exact code.
                    event.preventDefault();
                    scan();
                }
            });

            search.addEventListener('input', debounce(runSearch, 250));

            /*
            |------------------------------------------------------------------
            | Cart actions
            |------------------------------------------------------------------
            |
            | Delegated from the document, so the cart panel can be replaced
            | wholesale by reloadCart() without anything needing to be rebound.
            | The checkout and hold forms are deliberately not listed: those are
            | ordinary posts that navigate.
            */
            function sendDiscount(form, url, override) {
                return tillPost(url, override || {
                    type: form.querySelector('[name=type]').value,
                    value: form.querySelector('[name=value]').value || 0,
                });
            }

            function updateQuantity(form) {
                return tillPost(`${pos}/update/${form.dataset.id}`, {
                    quantity: form.querySelector('[name=quantity]').value,
                });
            }

            document.addEventListener('submit', function (event) {
                const form = event.target;

                if (form.matches('.add-to-cart-form')) {
                    event.preventDefault();
                    tillPost(`${pos}/add/${form.dataset.id}`).then(data => {
                        if (data && data.success) beepFound();
                    });
                } else if (form.matches('.update-cart-form')) {
                    event.preventDefault();
                    updateQuantity(form);
                } else if (form.matches('.remove-cart-form')) {
                    event.preventDefault();
                    tillPost(`${pos}/remove/${form.dataset.id}`);
                } else if (form.matches('.line-discount-form')) {
                    event.preventDefault();
                    sendDiscount(form, `${pos}/discount/${form.dataset.id}`);
                } else if (form.matches('.order-discount-form')) {
                    event.preventDefault();
                    sendDiscount(form, `${pos}/discount`);
                } else if (form.matches('.clear-cart-form')) {
                    event.preventDefault();
                    tillPost(`${pos}/clear`);
                }
            });

            document.addEventListener('click', function (event) {
                const stepper = event.target.closest('[data-step]');

                if (stepper) {
                    const form = stepper.closest('.update-cart-form');
                    const input = form.querySelector('[name=quantity]');
                    const step = parseInt(stepper.dataset.step, 10);

                    input.value = Math.max(1, (parseInt(input.value, 10) || 1) + step);
                    updateQuantity(form);
                    return;
                }

                const off = event.target.closest('[data-clear-discount]');

                if (off) {
                    const form = off.closest('.line-discount-form');
                    sendDiscount(form, `${pos}/discount/${form.dataset.id}`, { type: 'none', value: 0 });
                }
            });

            /*
            |------------------------------------------------------------------
            | Keyboard
            |------------------------------------------------------------------
            |
            | Function keys on purpose: they do not type a character, so they
            | work from inside the amount box as well as from the grid, and they
            | are what a cashier moving from another till already expects.
            | The on-screen list is the cheat sheet (F1).
            */
            function submitTillForm(id, refusal) {
                const form = document.getElementById(id);

                if (!form) {
                    window.toast(refusal, { variant: 'warning' });
                    return;
                }

                const button = form.querySelector('button[type=submit]');

                if (button && button.disabled) {
                    window.toast(refusal, { variant: 'warning' });
                    return;
                }

                form.requestSubmit(button || undefined);
            }

            window.addEventListener('keydown', function (event) {
                if (event.ctrlKey || event.altKey || event.metaKey) return;

                switch (event.key) {
                    case 'F1':
                        event.preventDefault();
                        window.dispatchEvent(new CustomEvent('till-help'));
                        break;

                    case 'F2':
                        event.preventDefault();
                        focusSearch();
                        break;

                    case 'F4':
                        event.preventDefault();
                        submitTillForm('park-form', 'There is nothing to hold.');
                        break;

                    case 'F8':
                        event.preventDefault();
                        document.querySelector('.clear-cart-form')?.requestSubmit();
                        break;

                    case 'F9':
                        event.preventDefault();
                        submitTillForm('checkout-form', 'This sale cannot be taken yet.');
                        break;

                    case 'Escape':
                        if (document.activeElement === search && search.value !== '') {
                            search.value = '';
                            grid.innerHTML = initialGrid;
                        }
                        break;
                }
            });

            focusSearch();
        });
    </script>
@endsection
