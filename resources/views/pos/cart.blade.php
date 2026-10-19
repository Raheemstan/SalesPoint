@php
    /*
    |--------------------------------------------------------------------------
    | The cart, the totals and the payment panel
    |--------------------------------------------------------------------------
    |
    | Re-rendered on its own (POSController::index() answers an AJAX request with
    | just this partial) after every cart change. The payment panel lives in here
    | rather than in the page around it for exactly that reason: the amount it
    | offers to charge is always the total the server has just recomputed, never a
    | figure left over from before the last line was scanned.
    |
    | Every amount printed here comes from SaleCalculator via $totals. The
    | arithmetic the Alpine component below does - change, what is left owing - is
    | for the cashier's eyes only; checkout recomputes all of it from the database
    | and ignores anything this form claims about money.
    |
    | Tap targets are min-h-touch (2.75rem) throughout: this is worked with a
    | finger on a tablet, often by someone holding goods in the other hand.
    */
    $field = 'min-h-touch w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-base
              focus:border-primary-500 focus:ring-2 focus:ring-primary-200
              dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:ring-primary-900';
    $small = 'min-h-touch rounded-lg border border-gray-300 bg-white px-2 py-1 text-sm
              dark:border-gray-600 dark:bg-gray-700 dark:text-white';
    $chip = 'min-h-touch rounded-lg bg-gray-100 px-3 text-sm font-medium text-gray-700 hover:bg-gray-200
             dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600';
    $tidy = fn ($number) => rtrim(rtrim(number_format($number, 2), '0'), '.');

    // Shared by the Blade and the Alpine side, so the two cannot disagree.
    $decimals = (int) setting('currency_decimals', config('sectors.currency.decimals'));
    $blocked = $shiftRequired && !$openShift;

    /*
    | What will actually be charged: the bill SaleCalculator worked out, plus any
    | tip or service charge, which sits on top of the bill including tax.
    | Checkout recomputes exactly this sum, so the two cannot drift.
    */
    $charged = round(($totals['grand_total'] ?? 0) + $serviceCharge['amount'], 2);

    /*
    | A money formatter for Alpine, mirroring the money() helper: the symbol comes
    | from the setting, never from a literal, because the same install is sold
    | into different countries.
    */
    $jsMoney = "money(v) { return this.symbol + Number(v).toLocaleString(undefined, "
        . "{ minimumFractionDigits: this.dp, maximumFractionDigits: this.dp }); }";

    /*
    | The cart as the device will have to remember it if the line drops.
    |
    | The cart lives in the server session, so the only copy a till has offline is
    | this one. It is re-rendered with the partial on every change, so it cannot go
    | stale, and it is written as JSON rather than built from the DOM because
    | reading prices back out of formatted text is how a receipt ends up wrong.
    |
    | THESE FIGURES ARE NOT AUTHORITATIVE. They go up with a queued sale as
    | `claimed_total` and as the numbers on the provisional receipt. The server
    | recomputes every one of them from the catalogue before it banks anything -
    | see App\Services\CheckoutService. The unit prices are the exception, and
    | bounded: a queued sale is banked at the price the customer agreed to, within
    | a tolerance of today's price.
    */
    $offlineSnapshot = [
        'lines' => collect($cart)->values()->map(fn ($line) => [
            'product_id' => (int) $line['product_id'],
            'name' => $line['name'],
            'quantity' => (int) $line['quantity'],
            'price' => (float) $line['price'],
            'discount_type' => $line['discount_type'] ?? null,
            'discount_value' => isset($line['discount_value']) ? (float) $line['discount_value'] : null,
        ])->all(),
        'order_discount' => $orderDiscount ?: null,
        'service_charge' => $serviceCharge['amount'] > 0
            ? ['type' => session('cart_service_charge.type'), 'value' => session('cart_service_charge.value'), 'label' => $serviceCharge['label']]
            : null,
        'customer_name' => $customer?->name,
        // Who was at the till and which drawer they were on, so a sale queued
        // here is attributed to them and not to whoever syncs the device.
        'cashier_id' => auth()->id(),
        'shift_id' => $openShift?->id,
        'totals' => [
            'subtotal' => (float) ($totals['subtotal'] ?? 0),
            'discount' => (float) ($totals['discount_total'] ?? 0),
            'tax' => (float) ($totals['tax'] ?? 0),
            'tax_rate' => (float) ($totals['tax_rate'] ?? 0),
            'service_charge' => (float) $serviceCharge['amount'],
            'grand_total' => (float) $charged,
        ],
    ];
@endphp

{{--
    Read by resources/js/offline/till.js on every render, and mirrored into
    IndexedDB so public/sw.js can queue a submit this page did not catch.

    HEX_TAG matters here: a product name is typed by a person, and one called
    "</script>" must not be able to break out of this block.
--}}
<script type="application/json" id="offline-cart-snapshot">{!! json_encode(
    $offlineSnapshot,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) !!}</script>

<div class="flex items-center justify-between gap-2">
    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
        {{ term('sale') }}
        @if (!empty($cart))
            <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                · {{ count($cart) }} {{ Str::plural('line', count($cart)) }}
            </span>
        @endif
    </h2>

    @if (!empty($cart))
        <form class="clear-cart-form">
            <button type="submit"
                    class="flex min-h-touch items-center gap-1 rounded-lg px-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950">
                <x-icon name="x-circle" size="4" /> Clear
            </button>
        </form>
    @endif
</div>

@if (session('warning'))
    <p class="mt-3 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:ring-amber-800">
        {{ session('warning') }}
    </p>
@endif

{{--
    The customer.

    Shown whether or not credit is switched on: a name on a receipt and in a
    statement is useful to any shop. Credit is what *needs* one, and the
    pay-later control further down only appears once somebody is attached.
--}}
<div class="mt-3 rounded-lg bg-gray-50 p-2.5 ring-1 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700"
     x-data="{
         query: '',
         results: [],
         open: false,
         searched: false,
         dp: {{ $decimals }},
         symbol: @js(currency()),
         {{ $jsMoney }},
         summary(row) {
             const owes = 'owes ' + this.money(row.balance);
             const left = row.credit_available === null
                 ? 'no limit'
                 : this.money(Math.max(0, row.credit_available)) + ' left';
             return [row.phone, owes, left].filter(Boolean).join(' · ');
         },
         async find() {
             if (this.query.trim() === '') { this.results = []; this.open = false; return; }
             try {
                 const res = await fetch('{{ route('customers.search') }}?q=' + encodeURIComponent(this.query.trim()),
                     { headers: { Accept: 'application/json' } });
                 this.results = res.ok ? await res.json() : [];
             } catch (e) {
                 this.results = [];
             }
             this.searched = true;
             this.open = true;
         },
         async choose(id) {
             this.open = false;
             await window.tillPost('{{ route('pos.customer') }}', { customer_id: id });
         },
     }">
    @if ($customer)
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <p class="truncate font-semibold text-gray-900 dark:text-gray-100">{{ $customer->name }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($customer->phone){{ $customer->phone }} · @endif
                    owes {{ money($customer->outstanding) }}
                    @if ($creditEnabled)
                        · {{ $creditAvailable === null ? 'no credit limit' : money(max(0, $creditAvailable)) . ' credit left' }}
                    @endif
                </p>
            </div>

            <button type="button" @click="choose(null)"
                    class="flex min-h-touch shrink-0 items-center gap-1 rounded-lg px-2 text-xs text-gray-500 hover:bg-gray-200 dark:text-gray-400 dark:hover:bg-gray-700">
                <x-icon name="x" size="4" /> Remove
            </button>
        </div>
    @else
        <label for="customer-search" class="block text-xs font-medium text-gray-500 dark:text-gray-400">
            {{ term('customer') }} <span class="font-normal">— needed to sell on credit</span>
        </label>
        <div class="relative mt-1" @click.outside="open = false">
            <input type="search" id="customer-search" x-model="query" @input.debounce.300ms="find()"
                   placeholder="Search by name or phone" autocomplete="off" class="{{ $field }}">

            <div x-show="open && results.length" x-cloak
                 class="absolute z-modal mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-600 dark:bg-gray-800">
                <template x-for="row in results" :key="row.id">
                    <button type="button" @click="choose(row.id)"
                            class="flex min-h-touch w-full flex-col items-start justify-center px-3 py-2 text-left hover:bg-primary-50 dark:hover:bg-primary-900/40">
                        <span class="font-medium text-gray-900 dark:text-gray-100" x-text="row.name"></span>
                        <span class="text-xs text-gray-500 dark:text-gray-400" x-text="summary(row)"></span>
                    </button>
                </template>
            </div>

            <p x-show="open && searched && !results.length" x-cloak
               class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                No {{ strtolower(term('customer', true)) }} match that.
            </p>
        </div>
    @endif
</div>

{{-- Cart lines --}}
<div class="mt-3 space-y-2" data-cart-lines>
    @forelse($cart as $item)
        @include('pos.cart-line', ['item' => $item])
    @empty
        <div class="rounded-lg border border-dashed border-gray-300 p-6 text-center dark:border-gray-600" data-cart-empty>
            <p class="text-gray-500 dark:text-gray-400">Nothing in this {{ strtolower(term('sale')) }} yet.</p>
            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">Scan a barcode, or tap an item.</p>
        </div>
    @endforelse
</div>

@if (!empty($cart))
    {{-- Whole-sale discount. Applying 0 takes it off, so it needs no separate switch. --}}
    <form class="order-discount-form mt-3 flex items-center gap-1.5">
        <span class="mr-auto text-sm text-gray-600 dark:text-gray-300">{{ term('sale') }} discount</span>
        <input type="number" name="value" step="0.01" min="0" placeholder="0" inputmode="decimal"
               value="{{ $orderDiscount['value'] ?? '' }}" aria-label="{{ term('sale') }} discount"
               class="w-20 {{ $small }}">
        <select name="type" aria-label="{{ term('sale') }} discount type" class="{{ $small }}">
            <option value="percent" @selected(($orderDiscount['type'] ?? 'percent') === 'percent')>%</option>
            <option value="fixed" @selected(($orderDiscount['type'] ?? null) === 'fixed')>{{ currency() }}</option>
        </select>
        <button type="submit" class="{{ $chip }}">Apply</button>
    </form>

    @if ($discountLimit !== null)
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
            {{ term('cashier', true, 'Cashiers') }} can discount up to {{ $tidy($discountLimit) }}% of a {{ strtolower(term('sale')) }}.
        </p>
    @endif

    {{-- Totals, exactly as checkout will recompute them. --}}
    <div class="mt-3 space-y-1 border-t border-gray-200 pt-3 text-sm dark:border-gray-700">
        <p class="flex justify-between text-gray-600 dark:text-gray-300">
            <span>Subtotal</span><span data-total="subtotal">{{ money($totals['subtotal']) }}</span>
        </p>
        @if ($totals['discount_total'] > 0)
            <p class="flex justify-between text-green-600 dark:text-green-400">
                <span>Discount</span><span>− <span data-total="discount">{{ money($totals['discount_total']) }}</span></span>
            </p>
        @endif
        @if ($totals['tax_rate'] > 0)
            <p class="flex justify-between text-gray-600 dark:text-gray-300">
                <span>Tax ({{ $tidy($totals['tax_rate']) }}%)</span><span data-total="tax">{{ money($totals['tax']) }}</span>
            </p>
        @endif
        @if ($serviceCharge['amount'] > 0)
            {{-- A tip or service charge, on top of the bill including tax. --}}
            <p class="flex justify-between text-gray-600 dark:text-gray-300">
                <span>{{ $serviceCharge['label'] }}</span><span data-total="service">{{ money($serviceCharge['amount']) }}</span>
            </p>
        @endif
        <p class="flex items-baseline justify-between pt-1 text-gray-900 dark:text-gray-100">
            <span class="text-base font-semibold">Total</span>
            <span class="text-2xl font-bold" data-total="grand">{{ money($charged) }}</span>
        </p>
    </div>

    {{--
        Payment.

        The visible controls are the cashier's working-out. What is *posted* is the
        narrow set of named inputs in each branch: a single tender keeps the
        original `payment_method` / `paid_amount` pair, so that path still works
        with JavaScript off, and a split posts one `tenders[]` row per method that
        has money on it.
    --}}
    <form action="{{ route('pos.checkout') }}" method="POST" id="checkout-form" class="mt-4"
          x-data="{
              total: {{ $charged }},
              mode: 'single',
              method: 'cash',
              paid: '{{ $charged }}',
              reference: '',
              credit: false,
              split: { @foreach ($paymentMethods as $m) {{ $m }}: '', @endforeach },
              refs: { @foreach ($paymentMethods as $m) {{ $m }}: '', @endforeach },
              methods: @js($paymentMethods),
              labels: @js(collect($paymentMethods)->mapWithKeys(fn ($m) => [$m => \App\Models\SalePayment::label($m)])),
              dp: {{ $decimals }},
              symbol: @js(currency()),
              {{ $jsMoney }},

              num(v) { const n = parseFloat(v); return isNaN(n) ? 0 : n; },
              p2(v) { return Math.round(v * 100) / 100; },

              /* Only the tenders with money on them are posted. */
              get used() { return this.methods.filter(m => this.num(this.split[m]) > 0); },
              get tendered() {
                  return this.p2(this.mode === 'single'
                      ? this.num(this.paid)
                      : this.methods.reduce((sum, m) => sum + this.num(this.split[m]), 0));
              },
              /*
               * Change leaves the drawer, so in a split the card side may settle
               * the sale but never exceed it. A single tender is not checked:
               * 'amount received' has always been allowed to exceed the total
               * whatever the method, and the server agrees (PaymentSplitService).
               */
              get nonCash() {
                  return this.p2(this.methods.filter(m => m !== 'cash')
                      .reduce((sum, m) => sum + this.num(this.split[m]), 0));
              },
              get change() { return Math.max(0, this.p2(this.tendered - this.total)); },
              get owing() { return Math.max(0, this.p2(this.total - this.tendered)); },
              get short() { return this.owing > 0.004; },
              get cardOverpaid() {
                  return this.mode === 'split' && this.used.length > 1 && this.nonCash > this.total + 0.004;
              },
              get blocked() {
                  return {{ $blocked ? 'true' : 'false' }} || this.cardOverpaid || (this.short && !this.credit);
              },

              /* 'Rest' button: put whatever is still unpaid on this tender. */
              fill(method) { this.split[method] = String(this.p2(Math.max(0, this.owing))); },
          }"
          @submit="$nextTick(() => $el.querySelector('button[type=submit]').disabled = true)">
        @csrf

        {{-- Who the sale belongs to, posted so the server is not left inferring it. --}}
        @if ($customer)
            <input type="hidden" name="customer_id" value="{{ $customer->id }}">
        @endif

        <div class="flex gap-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-900/60">
            <button type="button" @click="mode = 'single'"
                    :class="mode === 'single' ? 'bg-white shadow dark:bg-gray-700' : 'opacity-70'"
                    class="min-h-touch flex-1 rounded-md px-3 text-sm font-semibold text-gray-800 dark:text-gray-100">
                One payment
            </button>
            <button type="button" @click="mode = 'split'"
                    :class="mode === 'split' ? 'bg-white shadow dark:bg-gray-700' : 'opacity-70'"
                    class="min-h-touch flex-1 rounded-md px-3 text-sm font-semibold text-gray-800 dark:text-gray-100">
                Split it
            </button>
        </div>

        {{-- One payment. Real named inputs: this is the path that survives JavaScript being off. --}}
        <div x-show="mode === 'single'" class="mt-3 space-y-2">
            <div>
                <label for="payment_method" class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
                    How it was paid
                </label>
                <select name="payment_method" id="payment_method" x-model="method" :disabled="mode !== 'single'"
                        class="{{ $field }}">
                    @foreach ($paymentMethods as $m)
                        <option value="{{ $m }}">{{ \App\Models\SalePayment::label($m) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="paid_amount" class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
                    Amount received ({{ currency() }})
                </label>
                <input type="number" step="0.01" min="0" inputmode="decimal" name="paid_amount" id="paid_amount"
                       x-model="paid" :disabled="mode !== 'single'"
                       class="{{ $field }} text-right text-lg font-semibold">
            </div>

            {{-- A card slip or transfer reference, when there is one to record. --}}
            <div x-show="method !== 'cash'" x-cloak>
                <label for="payment_reference" class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
                    Reference (optional)
                </label>
                <input type="text" name="payment_reference" id="payment_reference" maxlength="100"
                       x-model="reference" :disabled="mode !== 'single' || method === 'cash'"
                       class="{{ $field }} text-sm">
            </div>
        </div>

        {{-- Split. The visible boxes carry no name; the hidden rows underneath are what posts. --}}
        <div x-show="mode === 'split'" x-cloak class="mt-3 space-y-2">
            <template x-for="m in methods" :key="m">
                <div class="flex items-center gap-1.5">
                    <span class="w-20 shrink-0 text-sm font-medium text-gray-600 dark:text-gray-300" x-text="labels[m]"></span>
                    <input type="number" step="0.01" min="0" inputmode="decimal" x-model="split[m]" placeholder="0"
                           :aria-label="labels[m] + ' amount'" class="{{ $field }} text-right">
                    <button type="button" @click="fill(m)" class="{{ $chip }} shrink-0">Rest</button>
                </div>
            </template>

            <template x-for="(m, i) in used" :key="m">
                <span>
                    <input type="hidden" :name="'tenders[' + i + '][method]'" :value="m">
                    <input type="hidden" :name="'tenders[' + i + '][amount]'" :value="split[m]">
                    <input type="hidden" :name="'tenders[' + i + '][reference]'" :value="refs[m]">
                </span>
            </template>

            <p class="flex justify-between text-sm text-gray-600 dark:text-gray-300">
                <span>Tendered</span><span class="font-semibold" x-text="money(tendered)"></span>
            </p>
        </div>

        {{-- Change and what is left owing, worked out as the cashier types. --}}
        <div class="mt-3 space-y-1 rounded-lg bg-gray-50 p-2.5 text-sm dark:bg-gray-900/40">
            <p class="flex justify-between" x-show="change > 0" x-cloak>
                <span class="text-gray-600 dark:text-gray-300">Change</span>
                <span class="text-lg font-bold text-primary-700 dark:text-primary-300" x-text="money(change)"></span>
            </p>
            <p class="flex justify-between" x-show="short" x-cloak>
                <span class="text-gray-600 dark:text-gray-300">Still owing</span>
                <span class="text-lg font-bold text-amber-600 dark:text-amber-400" x-text="money(owing)"></span>
            </p>
            <p x-show="change === 0 && !short" class="text-center text-xs text-gray-400 dark:text-gray-500">
                Paid in full, no change.
            </p>
            <p x-show="cardOverpaid" x-cloak class="text-xs font-medium text-red-600 dark:text-red-400">
                Change can only be given in cash, so the card and transfer parts cannot come to more than the total.
            </p>
        </div>

        @if ($creditEnabled && $customer)
            {{--
                Pay later. Offered only once a customer is attached: a debt with no
                account behind it is money lost, and CreditService refuses it anyway.
            --}}
            <div class="mt-3 rounded-lg ring-1 ring-gray-200 dark:ring-gray-700">
                <label class="flex min-h-touch cursor-pointer items-center gap-2 p-2.5">
                    <input type="checkbox" x-model="credit"
                           class="h-5 w-5 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                    <span class="text-sm font-medium text-gray-800 dark:text-gray-100">
                        Put the rest on {{ $customer->name }}'s account
                    </span>
                </label>

                <div x-show="credit" x-cloak class="space-y-2 border-t border-gray-200 p-2.5 dark:border-gray-700">
                    <input type="hidden" name="on_credit" value="1">

                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Owes {{ money($customer->outstanding) }} now ·
                        {{ $creditAvailable === null
                            ? 'no credit limit set'
                            : money(max(0, $creditAvailable)) . ' of ' . money($customer->credit_limit) . ' left' }}
                    </p>

                    <p class="flex justify-between text-sm">
                        <span class="text-gray-600 dark:text-gray-300">Will be owed on this {{ strtolower(term('sale')) }}</span>
                        <span class="font-bold text-amber-600 dark:text-amber-400" x-text="money(owing)"></span>
                    </p>

                    @if ($creditAvailable !== null)
                        <p x-show="owing > {{ max(0, $creditAvailable) }} + 0.004" x-cloak
                           class="text-xs font-medium text-red-600 dark:text-red-400">
                            That is more than {{ $customer->name }} has left on their limit, so checkout will refuse it.
                        </p>
                    @endif

                    <div>
                        <label for="due_date" class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
                            Due by (optional)
                        </label>
                        <input type="date" name="due_date" id="due_date" min="{{ today()->toDateString() }}"
                               class="{{ $field }}">
                    </div>
                </div>
            </div>
        @endif

        @if ($blocked)
            <p class="mt-3 rounded-lg bg-amber-50 p-2.5 text-xs font-medium text-amber-800 ring-1 ring-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:ring-amber-800">
                No shift is open, and this shop requires one before anything can be sold.
            </p>
        @endif

        <button type="submit" :disabled="blocked"
                class="mt-3 flex min-h-touch w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-3
                       text-lg font-bold text-white shadow-sm transition hover:bg-green-700 active:scale-[0.99]
                       disabled:cursor-not-allowed disabled:bg-gray-400 disabled:shadow-none dark:disabled:bg-gray-600">
            <x-icon name="check-circle" size="5" />
            <span x-text="(credit &amp;&amp; short ? 'Take ' + money(tendered) + ' now' : 'Charge ' + money(total))"></span>
        </button>

        <p class="mt-1.5 text-center text-xs text-gray-400 dark:text-gray-500">F9 charge · F4 hold · F1 shortcuts</p>
    </form>

    {{-- Hold this cart and clear the till for the next customer. --}}
    <form action="{{ route('pos.park') }}" method="POST" id="park-form" class="mt-3 flex items-center gap-1.5">
        @csrf
        <input type="text" name="note" maxlength="255" placeholder="Hold note (optional)"
               aria-label="Note for the held {{ strtolower(term('sale')) }}" class="{{ $field }} text-sm">
        <button type="submit" class="{{ $chip }} flex shrink-0 items-center gap-1 font-semibold">
            <x-icon name="pause" size="4" /> Hold
        </button>
    </form>
@endif
