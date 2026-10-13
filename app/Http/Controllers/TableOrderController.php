<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Table;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Services\TableOrderService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;

/**
 * An order opened against a table and added to through the meal.
 *
 * This is the one sale flow in the app that is not a till sale. The order is
 * opened when the guests sit down, added to in rounds, sent to the kitchen in
 * batches, and only becomes money at the end - see settle(), which is where the
 * two flows meet and the most important thing in this file to understand.
 *
 * The rules all live in App\Services\TableOrderService; this class validates
 * input, authorises it, and turns a DomainException into a message a server can
 * read while standing up.
 */
class TableOrderController extends Controller implements HasMiddleware
{
    /** Table service does not exist for a trade without tables. See TableController. */
    public static function middleware(): array
    {
        return [
            function ($request, $next) {
                abort_unless(feature('tables'), 404);

                return $next($request);
            },
        ];
    }

    public function __construct(private TableOrderService $orders)
    {
    }

    /** Seat guests: open an order on a free table. */
    public function store(Request $request, Table $table)
    {
        Gate::authorize('create', TableOrder::class);

        $data = $request->validate([
            'guests' => ['required', 'integer', 'min:1', 'max:99'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $order = $this->orders->open(
                $table,
                $request->user(),
                (int) $data['guests'],
                $data['customer_id'] ?? null,
                $data['note'] ?? null
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('table-orders.show', $order)
            ->with('success', "{$table->name} opened for {$order->guests} " . lcfirst(term('customer', $order->guests > 1)) . '.');
    }

    /**
     * The order screen: what has been ordered, the menu to add from, the bill.
     *
     * Everything a server does to a table happens here, because on a tablet in
     * one hand there is no room for a trip to another screen per tap.
     */
    public function show(TableOrder $tableOrder)
    {
        Gate::authorize('view', $tableOrder);

        $tableOrder->load(['table', 'user', 'customer', 'items.product', 'sale']);

        $totals = $this->orders->totals($tableOrder);

        // The menu, grouped the way the printed one is. Services (a cover charge,
        // a corkage fee) sit alongside dishes: a restaurant sells both.
        $menu = Product::active()
            ->with('category')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Product $product) => $product->category->name ?? 'Other')
            ->sortKeys();

        // Free tables to transfer to. A table with an open order is not one.
        $freeTables = Table::active()
            ->ordered()
            ->whereDoesntHave('orders', fn ($query) => $query->open())
            ->where('id', '!=', $tableOrder->table_id)
            ->get();

        return view('table-orders.show', compact('tableOrder', 'totals', 'menu', 'freeTables'));
    }

    /** Add something to the order. The price is snapshotted by the service. */
    public function addItem(Request $request, TableOrder $tableOrder)
    {
        Gate::authorize('update', $tableOrder);

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'note' => ['nullable', 'string', 'max:255'],
            'course' => ['nullable', 'in:' . implode(',', TableOrderItem::COURSES)],
        ]);

        // Note what is NOT validated here: a price. The browser never gets to say
        // what anything costs - the service reads sale_price from the database.
        $product = Product::findOrFail($data['product_id']);

        try {
            $this->orders->addItem(
                $tableOrder,
                $product,
                (int) $data['quantity'],
                $data['note'] ?? null,
                $data['course'] ?? null
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$data['quantity']} x {$product->name} added.");
    }

    /** Change a line the kitchen has not seen. A sent line is refused - see the service. */
    public function updateItem(Request $request, TableOrder $tableOrder, TableOrderItem $item)
    {
        Gate::authorize('update', $tableOrder);
        $this->assertBelongs($tableOrder, $item);

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'note' => ['nullable', 'string', 'max:255'],
            'course' => ['nullable', 'in:' . implode(',', TableOrderItem::COURSES)],
        ]);

        try {
            $this->orders->updateItem($item, (int) $data['quantity'], $data['note'] ?? null, $data['course'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Order updated.');
    }

    /**
     * Take a line off.
     *
     * The reason is optional in the rules and mandatory in the service, because
     * whether it is needed depends on something the request cannot see: whether
     * the kitchen has already cooked it.
     */
    public function removeItem(Request $request, TableOrder $tableOrder, TableOrderItem $item)
    {
        Gate::authorize('update', $tableOrder);
        $this->assertBelongs($tableOrder, $item);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->orders->removeItem($item, $data['reason'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Item removed.');
    }

    /**
     * Stamp the new lines and print a prep ticket.
     *
     * Deliberately NOT wired to the thermal printer. POSController owns the
     * ESC/POS path and firing it from here would mean two features sharing one
     * device with no coordination; a kitchen printer is also usually a different
     * device from the receipt printer, on a different queue. So this renders a
     * printable HTML ticket that prints itself - good enough for a shop with one
     * printer, and the obvious place to add a real kitchen queue later.
     */
    public function sendToKitchen(TableOrder $tableOrder)
    {
        Gate::authorize('update', $tableOrder);

        // Separate from feature('tables'): a cafe may run tables without a kitchen.
        abort_unless(feature('kitchen'), 404);

        try {
            $sent = $this->orders->sendToKitchen($tableOrder);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($sent->isEmpty()) {
            return back()->with('warning', 'Everything on this order has already gone to the kitchen.');
        }

        $tableOrder->load(['table', 'user']);

        return view('table-orders.ticket', [
            'order' => $tableOrder,
            'items' => $sent,
            'printedAt' => now(),
        ]);
    }

    /** Move the order to another free table. */
    public function transfer(Request $request, TableOrder $tableOrder)
    {
        Gate::authorize('update', $tableOrder);

        $data = $request->validate([
            'table_id' => ['required', 'integer', 'exists:tables,id'],
        ]);

        try {
            $this->orders->transfer($tableOrder, Table::findOrFail($data['table_id']));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Moved to {$tableOrder->table->name}.");
    }

    /**
     * Hand the bill over to the till.
     *
     * ======================================================================
     * HOW SETTLING WORKS, AND WHY IT WORKS THIS WAY
     * ======================================================================
     *
     * This method takes no money. It cannot: taking money properly means locking
     * every product row, checking stock, capping the cashier's discount,
     * computing tax, splitting the payment across tenders, handling credit and
     * stamping the till shift. All of that already exists, correct and tested, in
     * POSController::checkout(). Writing a second copy of it here would be the
     * worst thing this feature could do - two checkouts that drift apart, one of
     * which forgets to take stock off.
     *
     * So settling is a HANDOVER, not a transaction:
     *
     *   1. the order's lines are turned into the session cart the till already
     *      reads (POSController::resolveCart() dictates the shape: an array keyed
     *      by product id, each entry product_id / name / price / quantity /
     *      discount_type / discount_value)
     *   2. the order's guest, if it has one, goes into the session as the sale's
     *      customer
     *   3. the order's id goes into the session as `table_order_id` - the baton
     *   4. the server is redirected to the till, where the cashier takes payment
     *      through the one checkout the shop has
     *
     * The order is still OPEN at the end of this method. It is closed by
     * App\Observers\TableOrderSaleObserver, which watches for the Sale the till
     * creates and, finding the baton in the session, marks the order settled and
     * records which sale paid for it. That observer is registered from
     * App\Providers\TableServiceProvider, so none of this requires editing a file
     * table service does not own.
     *
     * A consequence worth knowing: the till re-prices every cart line from the
     * database (that is what resolveCart() does, on purpose, so the cart is never
     * stale). The snapshot prices on the order therefore govern the bill the
     * guests are shown, and the live menu prices govern the receipt. For an order
     * settled in the same service those are the same number; if a manager
     * re-prices the menu mid-meal they are not, and the till's figure wins
     * because the till is where the money is taken. Charging the snapshot through
     * the till would mean teaching it to trust prices from elsewhere, which is
     * exactly the hole its own tests exist to keep shut.
     */
    public function settle(Request $request, TableOrder $tableOrder)
    {
        Gate::authorize('settle', $tableOrder);

        /*
         * The ceiling depends on the type: 150 is a nonsense percentage but a
         * perfectly good fixed tip, so the rule is built rather than fixed.
         * The service checks again for callers that do not come through HTTP.
         */
        $valueRules = ['nullable', 'numeric', 'min:0'];

        if ($request->input('service_charge_type') === 'percent') {
            $valueRules[] = 'max:100';
        }

        $data = $request->validate([
            'service_charge_type' => ['nullable', 'in:percent,fixed,none'],
            'service_charge_value' => $valueRules,
        ]);

        $cart = $this->orders->cartFor($tableOrder);

        if (empty($cart)) {
            return back()->with('error', 'Nothing has been ordered yet, so there is nothing to settle.');
        }

        /*
         * The tip / service charge. The amount is computed here, server-side,
         * from the order's own total - the browser only ever says "10%" or
         * "500", and even those are whitelisted above.
         *
         * Gated on feature('tips') here and not only in the view: a trade that
         * does not take tips must not acquire one through a crafted request.
         */
        $billTotal = $this->orders->totals($tableOrder)['grand_total'];

        try {
            $charge = feature('tips')
                ? $this->orders->serviceCharge(
                    $billTotal,
                    $data['service_charge_type'] ?? null,
                    $data['service_charge_value'] ?? null
                )
                : 0.0;
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $replaced = !empty(session('cart'));

        session()->put('cart', $cart);
        session()->put('table_order_id', $tableOrder->id);

        // A discount left in the session from whatever the cashier was doing
        // before must not attach itself to this table's bill.
        session()->forget('cart_discount');

        if ($tableOrder->customer_id) {
            session()->put('cart_customer', $tableOrder->customer_id);
        } else {
            session()->forget('cart_customer');
        }

        if ($charge > 0) {
            /*
             * Carried as a documented session field rather than a cart line,
             * because a cart line would be re-priced from the product by
             * resolveCart() and the charge would vanish. `type` and `value`
             * mirror `cart_discount` so the till can treat it the same way;
             * `amount` is the figure as quoted to the guests at handover.
             */
            session()->put('cart_service_charge', [
                'type' => $data['service_charge_type'],
                'value' => (float) $data['service_charge_value'],
                'amount' => $charge,
                'label' => $data['service_charge_type'] === 'percent'
                    ? 'Service charge (' . rtrim(rtrim(number_format((float) $data['service_charge_value'], 2), '0'), '.') . '%)'
                    : 'Service charge',
            ]);
        } else {
            session()->forget('cart_service_charge');
        }

        AuditLog::record(
            "Sent {$tableOrder->reference} ({$tableOrder->table->name}) to the till: "
                . money($billTotal, false)
                . ($charge > 0 ? ' + ' . money($charge, false) . ' service' : ''),
            'table_orders',
            $tableOrder->id
        );

        $message = "{$tableOrder->table->name} is on the till. Take payment to close the " . lcfirst(term('sale')) . '.';

        return redirect()->route('pos.index')
            ->with('success', $replaced ? $message . ' The cart that was open has been replaced.' : $message);
    }

    /** Cancel the order with a reason. Manager only - see TableOrderPolicy. */
    public function destroy(Request $request, TableOrder $tableOrder)
    {
        Gate::authorize('delete', $tableOrder);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        try {
            $this->orders->cancel($tableOrder, $data['reason']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tables.index')
            ->with('success', "{$tableOrder->reference} cancelled.");
    }

    /**
     * The item routes nest the line under its order but the route is not scoped,
     * so a line from somebody else's table would otherwise be editable through
     * this order's URL.
     */
    private function assertBelongs(TableOrder $order, TableOrderItem $item): void
    {
        abort_unless($item->table_order_id === $order->id, 404);
    }
}
