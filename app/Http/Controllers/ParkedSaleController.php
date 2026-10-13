<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ParkedSale;
use App\Models\Product;
use App\Services\SaleCalculator;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Held carts: park a sale, serve someone else, resume it later.
 *
 * Two decisions shape everything in here.
 *
 * **Parking reserves nothing.** A held cart is not a sale: no stock is set
 * aside, no figure counts it, nothing is committed. Reserving stock would mean
 * a cart forgotten on Friday keeps the last three of an item off the shelf all
 * weekend, with no sale to show for it. The cost of not reserving is that a line
 * may have sold out by the time the cart comes back, so resume() re-reads every
 * line from the catalogue and says plainly what changed.
 *
 * **Resuming refuses to merge.** If the till already has something in it,
 * resuming is rejected rather than merged or silently parked, because both of
 * those lose a cashier's work in a way they cannot see: a merge mixes two
 * customers' goods into one sale, and an automatic park makes the cart in front
 * of them vanish behind a reference they never read. They are told to park or
 * clear what they have, which takes one tap and is reversible.
 */
class ParkedSaleController extends Controller
{
    /** The held carts: a cashier's own, or everyone's for a manager. */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', ParkedSale::class);

        $mine = !$request->user()->hasAnyRole(['admin', 'manager']);

        $parked = ParkedSale::query()
            ->with(['user', 'customer'])
            ->when($mine, fn ($query) => $query->where('user_id', $request->user()->id))
            ->latestFirst()
            ->paginate(20);

        return view('pos.parked', [
            'parked' => $parked,
            'ownOnly' => $mine,
        ]);
    }

    /**
     * Put the current cart aside and clear the till.
     *
     * The stored totals are recomputed here rather than taken from the browser,
     * for the same reason checkout does it: they are shown to a person as the
     * value of a held sale, and a figure a customer can edit is not a figure.
     */
    public function store(Request $request)
    {
        Gate::authorize('create', ParkedSale::class);

        $data = $request->validate([
            'note' => 'nullable|string|max:255',
        ]);

        $cart = session()->get('cart', []);

        if (empty($cart)) {
            return $this->back($request, 'error', 'There is nothing in the till to hold.');
        }

        $discount = session('cart_discount');
        $customerId = session('cart_customer');

        // Priced from the catalogue, discounted by the one calculator the cart and
        // checkout both use. Any discount the cart is carrying is already valid -
        // the till refuses to store an invalid one - so this cannot throw.
        $totals = SaleCalculator::calculate(
            $this->linesFor($cart),
            $discount,
            (float) setting('tax_rate', 0)
        );

        $parked = ParkedSale::create([
            'reference' => ParkedSale::newReference(),
            'user_id' => $request->user()->id,
            'customer_id' => $customerId,
            'cart' => $cart,
            'discount' => $discount,
            'note' => $data['note'] ?? null,
            'item_count' => array_sum(array_map(fn ($line) => (int) $line['quantity'], $cart)),
            'total' => $totals['grand_total'],
        ]);

        session()->forget(['cart', 'cart_discount', 'cart_customer', 'cart_service_charge']);

        AuditLog::record('Held ' . strtolower(term('sale')) . ' ' . $parked->reference
            . ' (' . money($parked->total) . ')', 'parked_sales', $parked->id);

        return $this->back(
            $request,
            'success',
            'Held as ' . $parked->reference . '. The till is clear for the next ' . strtolower(term('customer')) . '.',
            ['reference' => $parked->reference, 'parked_count' => $this->countFor($request)]
        );
    }

    /**
     * Load a held cart back into the till.
     *
     * Everything about it comes back: the lines, the line discounts, the sale
     * discount and the customer it was for. What cannot come back unchanged is
     * said out loud - a line that has sold out, one that has only part of its
     * quantity left, one whose price has moved, one whose product has been
     * archived. The cashier sees the cart they parked with a list of what is
     * different, which is the only version of this that does not quietly
     * overcharge or oversell somebody.
     */
    public function resume(Request $request, ParkedSale $parkedSale)
    {
        Gate::authorize('resume', $parkedSale);

        if (!empty(session()->get('cart', []))) {
            return $this->back(
                $request,
                'error',
                'The till already has a ' . strtolower(term('sale'))
                    . ' in it. Hold or clear that one first, then resume ' . $parkedSale->reference . '.'
            );
        }

        [$cart, $warnings] = $this->restorable($parkedSale);

        if (empty($cart)) {
            return $this->back(
                $request,
                'error',
                $parkedSale->reference . ' cannot be resumed: none of its '
                    . strtolower(term('product', true)) . ' are still on sale.'
            );
        }

        /*
         * One transaction so a held cart cannot be consumed without landing in
         * the session, or land in the session twice: the row is deleted under a
         * lock, so two taps on "Resume" resume it once.
         */
        $resumed = DB::transaction(function () use ($parkedSale) {
            $locked = ParkedSale::lockForUpdate()->find($parkedSale->id);

            if ($locked === null) {
                throw new DomainException('That held ' . strtolower(term('sale')) . ' has already been resumed.');
            }

            $locked->delete();

            return $locked;
        });

        session()->put('cart', $cart);

        $resumed->discount
            ? session()->put('cart_discount', $resumed->discount)
            : session()->forget('cart_discount');

        /*
         * A held cart never carries a tip. A service charge only arrives with a
         * table bill, and that table's order stays open while the bill sits here,
         * so re-sending it to the till is what puts the charge back - freshly
         * computed against the bill as it then is. Dropping it here is also what
         * stops a charge from whatever the cashier was doing before attaching
         * itself to this cart.
         */
        session()->forget('cart_service_charge');

        // Only re-attach a customer who is still able to buy; an account closed
        // since the cart was parked must not come back silently attached to it.
        $customer = $resumed->customer_id ? Customer::find($resumed->customer_id) : null;

        if ($customer && $customer->is_active) {
            session()->put('cart_customer', $customer->id);
        } else {
            session()->forget('cart_customer');

            if ($customer) {
                $warnings[] = $customer->name . ' is no longer active, so the '
                    . strtolower(term('sale')) . ' was resumed without them.';
            }
        }

        AuditLog::record('Resumed held ' . strtolower(term('sale')) . ' ' . $resumed->reference,
            'parked_sales', $resumed->id);

        if ($warnings) {
            session()->flash('warning', implode(' ', $warnings));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $resumed->reference . ' is back in the till.',
                'warnings' => $warnings,
            ]);
        }

        // Straight to the till, not back to the list: the cart is loaded and the
        // next thing wanted is the payment panel.
        return redirect()->route('pos.index')
            ->with('success', $resumed->reference . ' is back in the till.');
    }

    /** Throw a held cart away. A cashier's own, or anyone's if you are a manager. */
    public function destroy(Request $request, ParkedSale $parkedSale)
    {
        Gate::authorize('delete', $parkedSale);

        $reference = $parkedSale->reference;

        AuditLog::record('Deleted held ' . strtolower(term('sale')) . ' ' . $reference
            . ' (' . money($parkedSale->total) . ')', 'parked_sales', $parkedSale->id);

        $parkedSale->delete();

        return $this->back($request, 'success', $reference . ' was deleted.');
    }

    /**
     * The held cart as it can be rung up *today*: current prices, current stock,
     * archived lines dropped, with a note of everything that moved.
     *
     * @return array{0: array<int|string, array<string, mixed>>, 1: array<int, string>} [cart, warnings]
     */
    private function restorable(ParkedSale $parked): array
    {
        $stored = $parked->cart ?? [];

        if (empty($stored)) {
            return [[], []];
        }

        $products = Product::whereIn('id', array_keys($stored))->get()->keyBy('id');
        $cart = [];
        $warnings = [];

        foreach ($stored as $id => $line) {
            $product = $products->get($id);

            if ($product === null) {
                // The name is the one the cart was parked with: the product is gone,
                // so there is nothing current to call it.
                $warnings[] = ($line['name'] ?? 'An item') . ' is no longer in the catalogue and was removed.';
                continue;
            }

            $wanted = max(1, (int) ($line['quantity'] ?? 1));
            $quantity = $wanted;

            if ($product->tracksStock()) {
                $available = (int) $product->stock_quantity;

                if ($available <= 0) {
                    $warnings[] = $product->name . ' has sold out and was removed.';
                    continue;
                }

                if ($available < $wanted) {
                    $quantity = $available;
                    $warnings[] = $product->name . ': only ' . $available . ' of ' . $wanted . ' left.';
                }
            }

            // Worth saying even though the till always prices from the catalogue:
            // the figure the cashier quoted the customer has changed.
            if (round((float) ($line['price'] ?? 0), 2) !== round((float) $product->sale_price, 2)) {
                $warnings[] = $product->name . ' is now ' . money($product->sale_price)
                    . ' (was ' . money($line['price'] ?? 0) . ').';
            }

            $cart[$product->id] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'price' => (float) $product->sale_price,
                'quantity' => $quantity,
                'discount_type' => $line['discount_type'] ?? null,
                'discount_value' => $line['discount_value'] ?? null,
            ];
        }

        return [$cart, $warnings];
    }

    /**
     * The cart in the shape SaleCalculator wants, priced from the catalogue.
     *
     * A line whose product has gone is dropped: it cannot be priced, and it will
     * not survive a resume either.
     *
     * @param  array<int|string, array<string, mixed>>  $cart
     * @return array<int|string, array<string, mixed>>
     */
    private function linesFor(array $cart): array
    {
        $products = Product::whereIn('id', array_keys($cart))->get()->keyBy('id');
        $lines = [];

        foreach ($cart as $id => $line) {
            if ($product = $products->get($id)) {
                $lines[$id] = [
                    'price' => (float) $product->sale_price,
                    'quantity' => (int) $line['quantity'],
                    'discount_type' => $line['discount_type'] ?? null,
                    'discount_value' => $line['discount_value'] ?? null,
                ];
            }
        }

        return $lines;
    }

    /** How many carts this cashier is holding, for the till's badge. */
    private function countFor(Request $request): int
    {
        return ParkedSale::where('user_id', $request->user()->id)->count();
    }

    /**
     * Answer the till (JSON, because it posts with fetch) or a plain form post
     * (a redirect with a flash), from one place.
     *
     * @param  array<string, mixed>  $extra  added to the JSON body only
     */
    private function back(Request $request, string $key, string $message, array $extra = [])
    {
        if ($request->expectsJson()) {
            return response()->json(array_merge([
                'success' => $key !== 'error',
                'message' => $message,
            ], $extra), $key === 'error' ? 422 : 200);
        }

        return back()->with($key, $message);
    }
}
