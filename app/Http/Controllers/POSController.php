<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\OfflineSaleAttempt;
use App\Models\ParkedSale;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\CheckoutService;
use App\Services\CreditService;
use App\Services\OfflineSyncService;
use App\Services\PaymentSplitService;
use App\Services\SaleCalculator;
use App\Services\ShiftService;
use App\Services\TableOrderService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\Printer;

class POSController extends Controller
{
    private const PAYMENT_METHODS = ['cash', 'pos', 'transfer'];

    /** How many products the till page carries; beyond this the search box asks the server. */
    private const CATALOGUE_LIMIT = 200;

    /**
     * The character that makes `%` and `_` literal in a LIKE pattern.
     *
     * Not a backslash, deliberately. MySQL treats `\` as an escape inside a
     * string literal and SQLite does not, so no single piece of SQL spells
     * `ESCAPE '\'` for both drivers - `'\\'` is one character to MySQL and two
     * to SQLite, and SQLite rejects a two-character escape. `!` needs no
     * escaping anywhere, so the same statement runs on either.
     */
    private const LIKE_ESCAPE = '!';

    /**
     * Display the POS page
     */
    public function index()
    {
        if (request()->ajax()) {
            return $this->renderCart();
        }

        $cartData = $this->cartViewData();

        // The search box covers the rest of the catalogue.
        $limit = self::CATALOGUE_LIMIT;
        $products = Product::orderBy('name')->limit($limit)->get();

        return view('pos.index', array_merge($cartData, $this->offlineState(), [
            'products' => $products,
            // When the whole catalogue fits on screen the till can filter it in the
            // browser, with no request at all. Past the limit it asks the server.
            'catalogueComplete' => $products->count() < $limit,
        ]));
    }

    /**
     * Everything pos.cart needs: the cart and its totals, discounts, service charge
     * and the till state around it (customer, shift, payment methods).
     *
     * @return array<string, mixed>
     */
    private function cartViewData(): array
    {
        [$cart, $totals] = $this->resolveCart();
        $orderDiscount = session('cart_discount');

        return array_merge($this->tillState(), [
            'cart' => $cart,
            'totals' => $totals,
            'orderDiscount' => $orderDiscount,
            'discountLimit' => $this->cashierDiscountLimit(),
            'serviceCharge' => $this->serviceChargeFor($totals['grand_total'] ?? 0.0),
        ]);
    }

    /** The cart panel as HTML: the AJAX answer to GET /pos, and what the mutations return. */
    private function renderCart(): string
    {
        return view('pos.cart', $this->cartViewData())->render();
    }

    /**
     * A JSON answer for a cart action, with the re-rendered cart panel attached when the till
     * asks for it (X-Till-Render).
     *
     * The till used to do POST, wait, then GET the panel: two sequential round trips for every
     * tap. Rendering the panel into the same response halves the waiting, and because it is
     * rendered *after* the change (and after any refusal has rolled the cart back) it always
     * shows what the server really holds.
     */
    private function withCartHtml(array $payload, int $status = 200)
    {
        if (request()->hasHeader('X-Till-Render')) {
            $payload['html'] = $this->renderCart();
        }

        return response()->json($payload, $status);
    }

    private function offlineState(): array
    {
        $status = app(OfflineSyncService::class)->summaryFor(auth()->user());

        return [
            'offlineRejected' => $status['rejected'],
            'offlineRefused' => $status['needs_attention'],
            'offlineConfig' => [
                'syncUrl' => route('pos.sync'),
                'statusUrl' => route('pos.sync.status'),
                'batch' => OfflineSyncService::MAX_BATCH,
                // So the panel can tell when the list Blade rendered has gone
                // stale (a sale refused since this page loaded).
                'serverRejectedCount' => $status['rejected'],
                'cashierId' => auth()->id(),
                'shiftId' => app(ShiftService::class)->currentFor(auth()->user())?->id,
                // Formatting, so no symbol is ever written into the JavaScript.
                'symbol' => currency(),
                'decimals' => (int) setting('currency_decimals', config('sectors.currency.decimals')),
                // The trade's own words: a pharmacy holds "prescriptions", a
                // restaurant "bills". term() decides, never a literal.
                'saleTerm' => term('sale'),
                'salesTerm' => term('sale', true),
                'customerTerm' => term('customer'),
                // For the provisional receipt, which has to render with no network.
                'cashierName' => auth()->user()?->name,
                'storeName' => setting('store_name', 'SalesPoint'),
                'storeMotto' => setting('store_motto'),
                'storeAddress' => setting('store_address'),
                'storePhone' => setting('store_phone'),
                'footer' => setting('receipt_footer'),
                'width' => setting('receipt_size', '80mm') === '58mm' ? '54mm' : '76mm',
                'methodLabels' => collect(self::PAYMENT_METHODS)
                    ->mapWithKeys(fn ($method) => [$method => SalePayment::label($method)])
                    ->all(),
            ],
        ];
    }

    /**
     * The tip or service charge sitting on this cart, recomputed from the bill,
     * for the cart screen.
     *
     * Table service hands a charge over in `session('cart_service_charge')` as
     * `['type', 'value', 'amount', 'label']`. The `amount` in there is what was
     * quoted to the guests; it is deliberately *not* trusted, for the same
     * reason no other money field from outside this method is. The figure below
     * is recomputed from the type and value against the bill the server has just
     * worked out, by the service that owns that maths and has the tests for it
     * (TableOrderService::serviceCharge, which takes the percentage on the bill
     * including tax - the number someone tapping "10%" is looking at).
     *
     * Checkout does the same recomputation against its own final total, inside
     * CheckoutService, which is why this one only has to serve the screen and may
     * drop a bad charge rather than refuse the sale.
     *
     * @return array{amount: float, label: ?string}
     */
    private function serviceChargeFor(float $billTotal): array
    {
        $none = ['amount' => 0.0, 'label' => null];
        $charge = session('cart_service_charge');

        // Gated on the sector feature: a shop that does not take tips cannot have
        // one arrive through a stale session key.
        if (!is_array($charge) || !feature('tips')) {
            return $none;
        }

        try {
            $amount = app(TableOrderService::class)->serviceCharge(
                $billTotal,
                is_string($charge['type'] ?? null) ? $charge['type'] : null,
                $charge['value'] ?? null
            );
        } catch (DomainException $e) {
            // Same policy as an invalid discount: drop it and say so rather than
            // leave the till unable to take any payment at all.
            session()->forget('cart_service_charge');
            session()->flash('warning', 'The service charge was removed: ' . $e->getMessage());

            return $none;
        }

        if ($amount <= 0) {
            return $none;
        }

        $label = is_string($charge['label'] ?? null) && trim($charge['label']) !== ''
            ? Str::limit(trim($charge['label']), 60, '')
            : 'Service charge';

        return ['amount' => $amount, 'label' => $label];
    }

    /**
     * Everything the till screen needs that is not the cart itself.
     *
     * Shared by the full page and the AJAX cart partial, because the payment
     * panel lives in the partial: it is re-rendered with every cart change so
     * the amount it offers to charge can never be a figure from before the last
     * line was added.
     *
     * @return array<string, mixed>
     */
    private function tillState(): array
    {
        $user = auth()->user();
        $shifts = app(ShiftService::class);

        $customerId = session('cart_customer');
        $customer = $customerId ? Customer::find($customerId) : null;

        // A customer who has been deactivated since they were attached must not
        // stay on the cart: credit would be refused at checkout anyway.
        if ($customer && !$customer->is_active) {
            session()->forget('cart_customer');
            $customer = null;
        }

        $openShift = $user ? $shifts->currentFor($user) : null;

        return [
            'customer' => $customer,
            'creditAvailable' => $customer ? app(CreditService::class)->remainingCredit($customer) : null,
            'creditEnabled' => feature('credit'),
            'openShift' => $openShift,
            // Enforcement is a setting, not a hard block - see ShiftService::requiredToSell().
            'shiftRequired' => $shifts->requiredToSell(),
            'parkedCount' => $user ? ParkedSale::where('user_id', $user->id)->count() : 0,
            'paymentMethods' => self::PAYMENT_METHODS,
        ];
    }

    /**
     * The cart as the customer will really be charged: names and prices come from the
     * database (not the session copy), archived products drop out, and totals come from
     * the one calculator that checkout also uses.
     *
     * @return array{0: array, 1: ?array} [cart lines, totals|null when empty]
     * @throws DomainException in strict mode when a discount is invalid
     */
    private function resolveCart(bool $strict = false): array
    {
        $raw = session()->get('cart', []);

        if (empty($raw)) {
            session()->forget('cart_discount');
            return [[], null];
        }

        $products = Product::whereIn('id', array_keys($raw))->get()->keyBy('id');
        $stored = [];

        foreach ($raw as $id => $item) {
            if ($product = $products->get($id)) {
                $stored[$id] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'price' => (float) $product->sale_price,
                    'quantity' => (int) $item['quantity'],
                    'discount_type' => $item['discount_type'] ?? null,
                    'discount_value' => $item['discount_value'] ?? null,
                ];
            }
        }

        session()->put('cart', $stored);

        if (empty($stored)) {
            return [[], null];
        }

        try {
            $totals = $this->totalsFor($stored);
        } catch (DomainException $e) {
            if ($strict) {
                throw $e;
            }

            // A price change can make a stored discount invalid; drop discounts rather than break the till.
            foreach ($stored as $id => $line) {
                $stored[$id]['discount_type'] = $stored[$id]['discount_value'] = null;
            }
            session()->put('cart', $stored);
            session()->forget('cart_discount');
            session()->flash('warning', 'Discounts were removed because prices changed: ' . $e->getMessage());
            $totals = $this->totalsFor($stored);
        }

        foreach ($stored as $id => $line) {
            $stored[$id]['stock'] = $products[$id]->stock_quantity;
            /*
             * A service (a haircut, a delivery fee, an hour of labour) has no stock
             * level at all, so the cart must show no figure and no warning for it.
             * Showing "0 in stock" against a service is both wrong and alarming.
             */
            $stored[$id]['tracks_stock'] = $products[$id]->tracksStock();
            $stored[$id]['calc'] = $totals['lines'][$id];
        }

        return [$stored, $totals];
    }

    private function totalsFor(array $cart): array
    {
        return SaleCalculator::calculate($cart, session('cart_discount'), (float) setting('tax_rate', 0));
    }

    /**
     * The biggest discount the signed-in cashier may give, for the cart screen.
     *
     * Defined once, in CheckoutService, because checkout enforces the same limit
     * and the two must never disagree about what a cashier is allowed to do.
     */
    private function cashierDiscountLimit(): ?float
    {
        return app(CheckoutService::class)->discountLimitFor(auth()->user());
    }

    private function assertDiscountAllowed(array $totals): void
    {
        app(CheckoutService::class)->assertDiscountAllowed($totals, auth()->user());
    }

    /** Apply a cart mutation; if the result is invalid or not allowed, put the cart back and say why. */
    private function tryCartChange(callable $mutate)
    {
        $before = [session('cart'), session('cart_discount')];

        try {
            $mutate();
            [, $totals] = $this->resolveCart(true);

            if ($totals) {
                $this->assertDiscountAllowed($totals);
            }
        } catch (DomainException $e) {
            session()->put('cart', $before[0]);
            $before[1] === null ? session()->forget('cart_discount') : session()->put('cart_discount', $before[1]);

            return $this->withCartHtml(['success' => false, 'message' => $e->getMessage(), 'cart' => session('cart', [])], 422);
        }

        return $this->withCartHtml(['success' => true, 'cart' => session('cart', [])]);
    }

    /** Discount on one cart line (percent or fixed). */
    public function applyLineDiscount(Request $request, $id)
    {
        $data = $request->validate([
            'type' => 'nullable|in:percent,fixed,none',
            'value' => 'nullable|numeric|min:0',
        ]);

        if (!isset(session('cart', [])[$id])) {
            return response()->json(['success' => false, 'message' => 'Item is not in the cart.'], 404);
        }

        return $this->tryCartChange(function () use ($id, $data) {
            $cart = session('cart');
            $none = ($data['type'] ?? 'none') === 'none' || empty($data['value']);
            $cart[$id]['discount_type'] = $none ? null : $data['type'];
            $cart[$id]['discount_value'] = $none ? null : $data['value'];
            session()->put('cart', $cart);
        });
    }

    /** Discount on the whole sale (percent or fixed). */
    public function applyOrderDiscount(Request $request)
    {
        $data = $request->validate([
            'type' => 'nullable|in:percent,fixed,none',
            'value' => 'nullable|numeric|min:0',
        ]);

        return $this->tryCartChange(function () use ($data) {
            $none = ($data['type'] ?? 'none') === 'none' || empty($data['value']);
            $none
                ? session()->forget('cart_discount')
                : session()->put('cart_discount', ['type' => $data['type'], 'value' => (float) $data['value']]);
        });
    }

    /**
     * The columns the till is allowed to know about.
     *
     * A cashier sells what is in the catalogue but must never see what it cost
     * the shop - that is what ProductPolicy and PurchasePolicy say, and cost
     * prices are exactly what tells a dishonest employee which items are worth
     * stealing and how far a price can be pushed. `Product` has no $hidden, so
     * returning a model straight to JSON hands over all 25 columns including
     * cost_price, supplier_id and the reorder levels.
     *
     * Every search and scan response is built from this list. The till's own
     * JavaScript reads only id, name, sale_price, stock_quantity, track_stock
     * and is_service; sku and barcode are here because the scan path matches on
     * them and shows them in the no-exact-match list.
     */
    private const TILL_COLUMNS = ['id', 'name', 'sku', 'barcode', 'sale_price', 'stock_quantity', 'is_service', 'track_stock'];

    /**
     * Search for products (AJAX)
     */
    public function searchProducts(Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->when($search !== '', fn ($query) => $this->whereMatches($query, $search))
            ->orderBy('name')
            ->limit(30)
            ->get(self::TILL_COLUMNS);

        return response()->json($products);
    }

    /**
     * Name, SKU or barcode contains $search, with `%` and `_` taken literally.
     *
     * The bug this fixes: the pattern was escaped with a backslash and no
     * `ESCAPE` clause. MySQL honours `\` by default so it looked correct in
     * production, but SQLite does not, so on dev and in the test suite a search
     * for "50%" became a search for "contains a literal backslash" and found
     * nothing at all - including the product actually called "50% Off". The
     * escape character is now declared in the SQL, so both drivers agree.
     */
    private function whereMatches($query, string $search)
    {
        $like = '%' . str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $search
        ) . '%';

        // The escape character is written into the SQL rather than bound: MySQL
        // wants a constant there, and `!` is safe to inline.
        $clause = " LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'";

        return $query->where(fn ($q) => $q
            ->whereRaw('name' . $clause, [$like])
            ->orWhereRaw('sku' . $clause, [$like])
            ->orWhereRaw('barcode' . $clause, [$like]));
    }

    /**
     * A barcode scan: one exact code in, one more of that item in the cart.
     *
     * A scanner is a keyboard that types fast and presses Enter, so this has to
     * be a single round trip with no picker in the middle - the cashier's hands
     * are on the goods, not the screen. An exact `barcode` wins, then an exact
     * `sku` (plenty of shops label their own stock with the SKU and no barcode).
     *
     * A code that matches nothing is not an error: the cashier scanned the wrong
     * side of the box, or the label is worn. It answers 200 with `found: false`
     * and the search results for the same string, so the till can show a short
     * list to tap instead of doing nothing and making them wonder whether the
     * scanner is broken.
     */
    public function scan(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:100',
        ]);

        $code = trim($data['code']);

        // Exact match only: a scan must never be ambiguous.
        $product = Product::where('barcode', $code)->first()
            ?? Product::where('sku', $code)->first();

        if ($product === null) {
            return response()->json([
                'success' => false,
                'found' => false,
                'message' => 'Nothing is coded "' . $code . '".',
                // So the cashier has something to tap rather than nothing to look at.
                'matches' => Product::query()
                    ->where(fn ($query) => $this->whereMatches($query, $code))
                    ->orderBy('name')
                    ->limit(10)
                    ->get(self::TILL_COLUMNS),
                'cart' => session()->get('cart', []),
            ]);
        }

        [$added, $message] = $this->addOneToCart($product);

        return $this->withCartHtml([
            'success' => $added,
            'found' => true,
            'message' => $message,
            'product' => ['id' => $product->id, 'name' => $product->name],
            'quantity' => session()->get('cart', [])[$product->id]['quantity'] ?? 0,
            'cart' => session()->get('cart', []),
        ], $added ? 200 : 422);
    }

    /**
     * Add product to cart
     */
    public function addToCart(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        [$added, $message] = $this->addOneToCart($product);

        return $this->withCartHtml(array_filter([
            'success' => $added,
            'message' => $message,
            'cart' => session()->get('cart', []),
        ], fn ($value) => $value !== null), $added ? 200 : 422);
    }

    /**
     * One more of this product in the cart.
     *
     * Scanning the same code five times has to come out as a quantity of five on
     * one line, not five lines, so the cart is keyed by product id and this only
     * ever bumps the quantity.
     *
     * @return array{0: bool, 1: ?string} [added?, refusal]
     */
    private function addOneToCart(Product $product): array
    {
        $cart = session()->get('cart', []);
        $quantity = ($cart[$product->id]['quantity'] ?? 0) + 1;

        if ($refusal = $this->stockRefusal($product, $quantity)) {
            return [false, $refusal];
        }

        // merge, so adding one more of an item keeps any discount already set on its line
        $cart[$product->id] = array_merge($cart[$product->id] ?? [], [
            'product_id' => $product->id,
            'name' => $product->name,
            'price' => $product->sale_price,
            'quantity' => $quantity,
        ]);

        session()->put('cart', $cart);

        return [true, null];
    }

    /**
     * Why this quantity cannot be sold, or null if it can.
     *
     * A service never runs out, so it is never refused: `tracksStock()` is false
     * for one, and a salon selling a haircut with `stock_quantity` 0 used to be
     * told the haircut was out of stock.
     */
    private function stockRefusal(Product $product, int $quantity): ?string
    {
        if (!$product->tracksStock()) {
            return null;
        }

        /*
         * stockAt(), not stock_quantity: the latter is the total across every
         * branch, so this till would happily sell what is sitting on another
         * shop's shelf. With branches switched off the two are the same number.
         */
        $onHand = $product->stockAt();

        if ($quantity <= $onHand) {
            return null;
        }

        return "Only {$onHand} of {$product->name} in stock.";
    }

    /**
     * Remove item from cart
     */
    public function removeFromCart(Request $request, $id)
    {
        $cart = session()->get('cart', []);

        if (isset($cart[$id])) {
            unset($cart[$id]);
            session()->put('cart', $cart);
        }

        return $this->withCartHtml(['success' => true, 'cart' => $cart]);
    }

    /**
     * Update item quantity in cart
     */
    public function updateCartItem(Request $request, $id)
    {
        $quantity = max(1, (int) $request->input('quantity'));
        $cart = session()->get('cart', []);

        if (isset($cart[$id])) {
            $product = Product::find($id);

            if (!$product) {
                unset($cart[$id]);
                session()->put('cart', $cart);

                return $this->withCartHtml(['success' => false, 'message' => 'Product no longer exists.', 'cart' => $cart], 422);
            }

            if ($refusal = $this->stockRefusal($product, $quantity)) {
                return $this->withCartHtml(['success' => false, 'message' => $refusal, 'cart' => $cart], 422);
            }

            $cart[$id]['quantity'] = $quantity;
            session()->put('cart', $cart);
        }

        return $this->withCartHtml(['success' => true, 'cart' => $cart]);
    }

    /**
     * Attach or detach the customer a sale belongs to.
     *
     * Needed before anything can go on credit - a debt with no account behind it
     * is money lost - and useful on a cash sale too, so the receipt and the
     * statement carry a name.
     */
    public function setCustomer(Request $request, CreditService $credit)
    {
        $data = $request->validate([
            'customer_id' => 'nullable|integer|exists:customers,id',
        ]);

        if (empty($data['customer_id'])) {
            session()->forget('cart_customer');

            return $this->withCartHtml(['success' => true, 'customer' => null]);
        }

        $customer = Customer::findOrFail($data['customer_id']);

        if (!$customer->is_active) {
            return $this->withCartHtml([
                'success' => false,
                'message' => $customer->name . ' is not active and cannot be billed.',
            ], 422);
        }

        session()->put('cart_customer', $customer->id);

        return $this->withCartHtml([
            'success' => true,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'balance' => $customer->outstanding,
                'credit_limit' => round((float) $customer->credit_limit, 2),
                // null = no limit set, so credit may always be offered.
                'credit_available' => $credit->remainingCredit($customer),
            ],
        ]);
    }

    /**
     * Clear the entire cart
     */
    public function clearCart()
    {
        session()->forget(['cart', 'cart_discount', 'cart_customer', 'cart_service_charge']);
        return $this->withCartHtml(['success' => true]);
    }

    /**
     * Checkout and process sale
     *
     * Totals are never taken from the browser: prices, discounts and tax are all
     * recomputed from the database, the session cart and the tax setting. The
     * browser says *how* the money arrived; CheckoutService works out how much.
     *
     * This method's job is the session and the HTTP response - reading the cart,
     * validating the form, clearing the keys afterwards, redirecting to the
     * receipt. The sale itself is created by CheckoutService, which the offline
     * sync endpoint also calls, so there is exactly one piece of code in the app
     * that knows how to turn a cart into money.
     */
    public function checkout(Request $request, CheckoutService $checkouts)
    {
        $cart = session()->get('cart', []);

        if (empty($cart)) {
            return back()->with('error', 'Cart is empty!');
        }

        $data = $request->validate([
            /*
             * Only needed for a single tender: a split derives the column from its
             * own rows (see PaymentSplitService::dominant()).
             */
            'payment_method' => ['required_without:tenders', 'nullable', 'in:' . implode(',', self::PAYMENT_METHODS)],
            'paid_amount' => ['required_without:tenders', 'nullable', 'numeric', 'min:0'],
            'payment_reference' => ['nullable', 'string', 'max:100'],

            // Split payment: one row per tender, at most one row per method.
            'tenders' => ['nullable', 'array', 'max:' . PaymentSplitService::MAX_TENDERS],
            'tenders.*.method' => ['required', 'in:' . implode(',', self::PAYMENT_METHODS)],
            'tenders.*.amount' => ['required', 'numeric', 'min:0'],
            'tenders.*.reference' => ['nullable', 'string', 'max:100'],

            // Credit sale.
            'customer_id' => 'nullable|integer|exists:customers,id',
            'on_credit' => 'nullable|boolean',
            'due_date' => 'nullable|date|after_or_equal:today',
        ]);

        try {
            $sale = $checkouts->create([
                'lines' => $cart,
                'order_discount' => session('cart_discount'),
                'tenders' => $this->tendersFrom($data),
                // The cashier picks the customer on the cart; the form posts it back.
                'customer_id' => $data['customer_id'] ?? session('cart_customer'),
                'on_credit' => (bool) ($data['on_credit'] ?? false),
                'due_date' => $data['due_date'] ?? null,
                /*
                 * Read here rather than inside the service because the
                 * table-service observer clears this key the moment the sale row
                 * appears. The quoted `amount` in it is deliberately not passed:
                 * the charge is recomputed from its type and value against the
                 * bill the server has just worked out.
                 */
                'service_charge' => session('cart_service_charge'),
            ], [
                'cashier' => $request->user(),
                // The online till stamps the cashier's open shift, if they have one.
                'shift' => 'current',
            ])['sale'];
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        session()->forget(['cart', 'cart_discount', 'cart_customer', 'cart_service_charge']);

        return redirect()->route('pos.print', $sale->id);
    }

    /**
     * The tenders to settle this sale with.
     *
     * A split posts `tenders[]`; the plain single-tender form posts
     * `payment_method` and `paid_amount` as it always has, and is turned into a
     * one-row split here so there is only one path through the money below.
     * When both arrive, the explicit tenders win.
     *
     * @param  array<string, mixed>  $data  validated request data
     * @return array<int, array{method: mixed, amount: mixed, reference: ?string}>
     */
    private function tendersFrom(array $data): array
    {
        $tenders = array_values(array_filter(
            $data['tenders'] ?? [],
            fn ($tender) => is_array($tender) && isset($tender['method'])
        ));

        if (!empty($tenders)) {
            return $tenders;
        }

        return [[
            'method' => $data['payment_method'] ?? 'cash',
            'amount' => $data['paid_amount'] ?? 0,
            'reference' => $data['payment_reference'] ?? null,
        ]];
    }

    /**
     * Print Receipt
     */
    public function printReceipt($id)
    {
        $sale = Sale::with(['items.product', 'payments', 'customer', 'user'])->findOrFail($id);

        Gate::authorize('view', $sale);

        $reprint = request()->boolean('reprint');

        // A reprint from the sales history must not fire the thermal printer again.
        if (!$reprint && setting('print_method', 'dom') === 'escpos') {
            $this->printEscPos($sale);
        }

        // Always show the on-screen receipt too.
        return view('pos.checkout', [
            'sale' => $sale,
            'reprint' => $reprint,
            /*
             * The reference the cashier wrote on the customer's slip when this
             * sale was taken offline. Printing it next to the real invoice
             * number is how a customer holding "OFF-A4K9" is matched to
             * "INV-...". Only an offline sale has one, so this is null for
             * nearly every receipt and costs a query for none of them.
             */
            'provisionalReference' => $sale->wasOffline() ? $this->provisionalReferenceFor($sale) : null,
        ]);
    }

    /**
     * The device's own reference for a sale that was queued offline.
     *
     * Read back out of the sync attempt rather than stored on the sale: it is
     * the client's label for the sale, not the shop's, and `sales` already
     * carries the id that matters (`client_uuid`). The attempt row is the audit
     * trail of the sync, and this is one of the things it is for.
     */
    private function provisionalReferenceFor(Sale $sale): ?string
    {
        $payload = OfflineSaleAttempt::where('sale_id', $sale->id)
            ->orderBy('id')
            ->value('payload');

        $payload = is_string($payload) ? json_decode($payload, true) : $payload;

        return is_array($payload) && is_string($payload['reference'] ?? null)
            ? $payload['reference']
            : null;
    }

    private function printEscPos(Sale $sale): void
    {
        // Logged before anything platform-specific happens, so "did we try to print?"
        // can be asserted on any host (the branches below only run on one of them).
        Log::info('ESC/POS print attempted', ['sale' => $sale->invoice_number]);

        // WindowsPrintConnector only works on a Windows host.
        if (PHP_OS_FAMILY !== 'Windows') {
            Log::warning('ESC/POS printing requires a Windows host; skipped.');
            return;
        }

        try {
            $connector = new WindowsPrintConnector(setting('printer_name', 'POS-58'));
            $printer = new Printer($connector);

            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text(setting('store_name', 'SalesPoint POS') . "\n");
            $printer->text(setting('store_motto', 'Your Trusted Store') . "\n");
            $printer->text(setting('store_address', '123 Business Street, Nigeria') . "\n");
            $printer->text('Phone: ' . setting('store_phone', '+234 800 000 0000') . "\n");
            $printer->feed();

            $printer->setJustification(Printer::JUSTIFY_LEFT);
            foreach ($sale->items as $item) {
                $line = $item->product->name . ' x' . $item->quantity;
                $printer->text(str_pad(Str::limit($line, 30, ''), 32));
                // money(), never a hardcoded symbol: the same install is sold into
                // different countries and the receipt is the one thing a customer keeps.
                $printer->text(money($item->price * $item->quantity) . "\n");
            }

            $printer->text("--------------------------------\n");
            $printer->text('Subtotal:  ' . money($sale->total_amount) . "\n");
            $printer->text('Tax:       ' . money($sale->tax_amount) . "\n");
            $printer->text('Discount:  ' . money($sale->discount_amount) . "\n");
            $printer->text('Total:     ' . money($sale->grand_total) . "\n");

            // The tender breakdown, so a split sale shows what was taken on each.
            foreach ($this->tenderLines($sale) as $label => $amount) {
                $printer->text(str_pad($label . ':', 11) . money($amount) . "\n");
            }

            $printer->text('Change:    ' . money($sale->change_due) . "\n");

            if ($sale->isCredit()) {
                $printer->text('BALANCE:   ' . money($sale->balance_due) . "\n");

                if ($sale->due_date) {
                    $printer->text('Due:       ' . $sale->due_date->format('d M Y') . "\n");
                }
            }

            $printer->feed();

            if ($sale->customer) {
                $printer->text(term('customer') . ': ' . $sale->customer->name . "\n");
            }

            $printer->text('Payment: ' . ucfirst($sale->payment_method) . "\n");
            $printer->text('Date: ' . $sale->created_at->format('d M Y h:i A') . "\n");
            $printer->feed();

            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text(setting('receipt_footer') ?: 'Thank you for your purchase!');
            $printer->text("\n");
            $printer->feed(3);

            $printer->cut();
            $printer->close();
        } catch (\Throwable $e) {
            Log::error('ESC/POS Print failed: ' . $e->getMessage());
        }
    }

    /**
     * What the customer handed over, per tender, for a receipt.
     *
     * `sale_payments` holds money *kept*, so the cash row is already net of any
     * change. A customer reading a receipt wants to see the note they held out,
     * so the change is added back onto the cash line here - and only there,
     * because change never comes out of a card or a transfer.
     *
     * A sale from before split payments existed has no tender rows; its one
     * method and `paid_amount` stand in for them.
     *
     * @return array<string, float> label => amount handed over
     */
    private function tenderLines(Sale $sale): array
    {
        $change = round((float) $sale->change_due, 2);
        $payments = $sale->payments;

        if ($payments->isEmpty()) {
            $paid = round((float) $sale->paid_amount, 2);

            return $paid > 0 || $change > 0
                ? [ucfirst((string) $sale->payment_method) => round($paid + $change, 2)]
                : [];
        }

        $lines = [];

        foreach ($payments as $payment) {
            $gross = round((float) $payment->amount + ($payment->method === 'cash' ? $change : 0), 2);
            $lines[SalePayment::label($payment->method)] = $gross;
        }

        return $lines;
    }
}
