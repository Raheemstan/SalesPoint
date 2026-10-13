<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryLog;
use App\Models\ParkedSale;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Services\CreditService;
use App\Services\PaymentSplitService;
use App\Services\SaleCalculator;
use App\Services\ShiftService;
use App\Services\TableOrderService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\Printer;

class POSController extends Controller
{
    private const PAYMENT_METHODS = ['cash', 'pos', 'transfer'];

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
        [$cart, $totals] = $this->resolveCart();
        $discountLimit = $this->cashierDiscountLimit();
        $orderDiscount = session('cart_discount');
        $serviceCharge = $this->serviceChargeFor($totals['grand_total'] ?? 0.0);
        $till = $this->tillState();

        if (request()->ajax()) {
            return view('pos.cart', array_merge($till, compact(
                'cart', 'totals', 'orderDiscount', 'discountLimit', 'serviceCharge'
            )))->render();
        }

        // The search box covers the rest of the catalogue.
        $products = Product::orderBy('name')->limit(200)->get();

        return view('pos.index', array_merge($till, compact(
            'products', 'cart', 'totals', 'orderDiscount', 'discountLimit', 'serviceCharge'
        )));
    }

    /**
     * The tip or service charge sitting on this cart, recomputed from the bill.
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
     * @param  bool  $strict  throw on a malformed charge instead of dropping it
     * @return array{amount: float, label: ?string}
     */
    private function serviceChargeFor(float $billTotal, bool $strict = false): array
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
            if ($strict) {
                throw $e;
            }

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

    private function cashierDiscountLimit(): ?float
    {
        // Managers and admins are not limited.
        if (auth()->user()?->hasAnyRole(['admin', 'manager'])) {
            return null;
        }

        return (float) setting('max_cashier_discount', 10);
    }

    private function assertDiscountAllowed(array $totals): void
    {
        $limit = $this->cashierDiscountLimit();

        if ($limit !== null && SaleCalculator::discountPercent($totals) > $limit + 0.005) {
            throw new DomainException("Discounts above {$limit}% need a manager.");
        }
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

            return response()->json(['success' => false, 'message' => $e->getMessage(), 'cart' => session('cart', [])], 422);
        }

        return response()->json(['success' => true, 'cart' => session('cart', [])]);
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
     * Search for products (AJAX)
     */
    public function searchProducts(Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        $products = Product::query()
            ->when($search !== '', fn ($query) => $this->whereMatches($query, $search))
            ->orderBy('name')
            ->limit(30)
            ->get();

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
                    ->get(['id', 'name', 'sku', 'barcode', 'sale_price', 'stock_quantity', 'is_service', 'track_stock']),
                'cart' => session()->get('cart', []),
            ]);
        }

        [$added, $message] = $this->addOneToCart($product);

        return response()->json([
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

        return response()->json(array_filter([
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
        if (!$product->tracksStock() || $quantity <= (int) $product->stock_quantity) {
            return null;
        }

        return "Only {$product->stock_quantity} of {$product->name} in stock.";
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

        return response()->json(['success' => true, 'cart' => $cart]);
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

                return response()->json(['success' => false, 'message' => 'Product no longer exists.', 'cart' => $cart], 422);
            }

            if ($refusal = $this->stockRefusal($product, $quantity)) {
                return response()->json(['success' => false, 'message' => $refusal, 'cart' => $cart], 422);
            }

            $cart[$id]['quantity'] = $quantity;
            session()->put('cart', $cart);
        }

        return response()->json(['success' => true, 'cart' => $cart]);
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

            return response()->json(['success' => true, 'customer' => null]);
        }

        $customer = Customer::findOrFail($data['customer_id']);

        if (!$customer->is_active) {
            return response()->json([
                'success' => false,
                'message' => $customer->name . ' is not active and cannot be billed.',
            ], 422);
        }

        session()->put('cart_customer', $customer->id);

        return response()->json([
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
        return response()->json(['success' => true]);
    }

    /**
     * Checkout and process sale
     *
     * Totals are never taken from the browser: prices, discounts and tax are all
     * recomputed here from the database, the session cart and the tax setting.
     * The browser says *how* the money arrived; this works out how much.
     */
    public function checkout(
        Request $request,
        CreditService $credit,
        ShiftService $shifts,
        PaymentSplitService $split
    ) {
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
            $sale = DB::transaction(function () use ($cart, $data, $request, $credit, $shifts, $split) {
                $products = [];
                $lines = [];

                // Lock each product row so two cashiers can't both sell the last unit,
                // and price every line from the database rather than the session copy.
                foreach ($cart as $id => $item) {
                    $product = Product::lockForUpdate()->find($item['product_id']);

                    if (!$product) {
                        throw new DomainException('A product in the cart no longer exists.');
                    }

                    // A service has no stock and so can never be short of it.
                    if ($product->tracksStock() && $product->stock_quantity < $item['quantity']) {
                        throw new DomainException("{$product->name} is out of stock or has insufficient quantity.");
                    }

                    $products[$id] = $product;
                    $lines[$id] = [
                        'price' => $product->sale_price,
                        'quantity' => (int) $item['quantity'],
                        'discount_type' => $item['discount_type'] ?? null,
                        'discount_value' => $item['discount_value'] ?? null,
                    ];
                }

                $orderDiscount = session('cart_discount');
                $taxRate = (float) setting('tax_rate', 0);
                $totals = SaleCalculator::calculate($lines, $orderDiscount, $taxRate);

                $this->assertDiscountAllowed($totals);

                /*
                 * A tip or service charge goes on top of the bill, tax included.
                 * Recomputed here from the type and value, never taken from the
                 * session's quoted `amount`, and read before Sale::create because
                 * the table-service observer clears the key the moment the sale
                 * row appears.
                 */
                $service = $this->serviceChargeFor($totals['grand_total'], true);

                $grandTotal = round($totals['grand_total'] + $service['amount'], 2);

                // The cashier picks the customer on the cart; the form posts it back.
                $customerId = $data['customer_id'] ?? session('cart_customer');
                $customer = $customerId ? Customer::find($customerId) : null;

                $onCredit = (bool) ($data['on_credit'] ?? false);

                if ($onCredit && !feature('credit')) {
                    throw new DomainException('Selling on credit is switched off for this shop.');
                }

                /*
                 * The tenders decide what was taken, what goes back as change and
                 * what is left owing. A short payment is only allowed when the
                 * cashier asked for it; whether there is an account to put it on
                 * is CreditService's call, not ours - it refuses a part-paid sale
                 * with no customer and says so in words the cashier can act on.
                 */
                $plan = $split->plan($this->tendersFrom($data), $grandTotal, $onCredit);
                $paid = $plan['tendered'];

                // Said early so the cashier is not told after the drawer opens.
                // recordCreditSale() checks again under a lock, where it counts.
                if ($plan['credit'] > 0 && $customer) {
                    $credit->assertCanTakeCredit($customer, $plan['credit']);
                }

                /*
                 * Resolved inside the transaction on purpose: currentFor() only ever
                 * returns an open shift, so a sale finishing just after a manager
                 * closed the drawer is never stamped into a reconciliation that has
                 * already been signed off.
                 */
                $shift = $shifts->currentFor($request->user());

                if (!$shift && $shifts->requiredToSell()) {
                    throw new DomainException('No shift is open. Open a shift before selling.');
                }

                $sale = Sale::create([
                    'invoice_number' => 'INV-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4)),
                    'user_id' => userId(),
                    'customer_id' => $customer?->id,
                    'shift_id' => $shift?->id,
                    'total_amount' => $totals['subtotal'],
                    'tax_amount' => $totals['tax'],
                    'discount_amount' => $totals['discount_total'],
                    'grand_total' => $grandTotal,
                    // The largest tender, so every existing report and filter still works.
                    'payment_method' => $plan['dominant'],
                    'paid_amount' => $paid,
                    // Clamped: a sale that is part on credit has no change to give.
                    'change_due' => $plan['change'],
                    'due_date' => $data['due_date'] ?? null,
                    'sale_date' => now(),
                    'status' => Sale::STATUS_COMPLETED,
                    'tax_rate' => $taxRate,
                    'discount_type' => $orderDiscount['type'] ?? null,
                    'discount_value' => $orderDiscount['value'] ?? null,
                    // Stored, not derived: a reprint renders from the sale row alone,
                    // and the guest should see the charge named on the receipt.
                    'service_charge' => $service['amount'],
                    'service_charge_label' => $service['amount'] > 0 ? $service['label'] : null,
                ]);

                foreach ($lines as $id => $line) {
                    $product = $products[$id];
                    $calc = $totals['lines'][$id];

                    SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $product->id,
                        'quantity' => $line['quantity'],
                        'price' => $product->sale_price,
                        // Snapshot: profit must not change when the product's cost is edited later.
                        'cost_price' => $product->cost_price,
                        'total' => $calc['gross'],
                        'discount_amount' => $calc['discount_total'],
                        'tax_amount' => $calc['tax'],
                        'paid_total' => $calc['paid_total'],
                    ]);

                    // A service has no stock level, so there is no movement to record.
                    if (!$product->tracksStock()) {
                        continue;
                    }

                    // Each line adjusts *its own* product.
                    $product->decrement('stock_quantity', $line['quantity']);

                    InventoryLog::create([
                        'product_id' => $product->id,
                        'user_id' => userId(),
                        'type' => 'OUT',
                        'quantity' => $line['quantity'],
                        'reason' => 'Sale ' . $sale->invoice_number,
                    ]);
                }

                // One row per tender that brought money in; the drawer figures read these.
                $split->record($sale, $plan);

                /*
                 * The only thing allowed to write balance_due and payment_status.
                 * It caps paid_amount at the grand total (the rest is change, which
                 * is already in change_due), so paid_amount and the sale_payments
                 * rows always come to the same figure.
                 */
                $credit->recordCreditSale($sale, $paid);

                AuditLog::record('Created Sale', 'sales', $sale->id);

                return $sale;
            });
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
        return view('pos.checkout', compact('sale', 'reprint'));
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
