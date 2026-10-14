<?php

namespace App\Observers;

use App\Models\BranchStock;
use App\Models\Product;
use App\Services\BranchContext;

/**
 * Keeps per-branch stock in step with the product's total.
 *
 * THIS IS THE LOAD-BEARING PIECE OF MULTI-BRANCH STOCK, SO HERE IS THE ARGUMENT
 *
 * The app has one way of moving stock, and it is `products.stock_quantity`: the
 * till decrements it on a sale, a purchase increments it on delivery, a refund
 * puts it back, a stock-take corrects it, an adjustment writes it off, the
 * product form sets the opening figure. Two dozen call sites, in code that
 * several other jobs own and are editing right now.
 *
 * Teaching all of them about branches would mean changing all of them, getting
 * one wrong, and shipping a point of sale that sometimes takes stock off the
 * wrong shelf. So none of them change. Instead the *total* keeps being written
 * exactly as it always was, and this observer splits the difference onto the
 * branch that is acting - which is the branch where the goods physically moved,
 * because that is what "acting as a branch" means.
 *
 *     products.stock_quantity == SUM(branch_stock.stock_quantity)
 *
 * holds after every write, with no call site knowing this file exists.
 *
 * IT RUNS EVEN WHEN `feature('branches')` IS OFF
 *
 * Deliberately. A single-site shop has one branch, so the mirror writes the same
 * number into one row and nothing observable changes - but it means `branch_stock`
 * is still correct on the day that shop opens a second one. Mirroring only while
 * the feature is on would leave this table holding the figures from the day the
 * migration ran: stale by however long the shop traded, and wrong in the one
 * moment it is first relied on.
 *
 * WHAT IT CANNOT SEE
 *
 * Model events. `Product::query()->update(['stock_quantity' => ...])` and
 * `DB::table('products')->update(...)` bypass Eloquent entirely and so bypass
 * this. Nothing in the app does that to a stock figure today (every writer goes
 * through a model instance - see `grep -rn "stock_quantity" app/`), but anything
 * added later that does will break the invariant silently. That is the price of
 * doing it this way, and it is stated here so the next person knows.
 */
class ProductStockObserver
{
    public function __construct(private BranchContext $branches)
    {
    }

    /**
     * A new product's opening stock lands at the branch that entered it.
     */
    public function created(Product $product): void
    {
        $this->mirror($product, (int) $product->stock_quantity);
    }

    /**
     * Any change to the total is the same change at the acting branch.
     *
     * The delta is read from the model rather than from the database, and works
     * for both ways stock is written:
     *
     *   - `$product->save()` after setting the attribute - the usual path;
     *   - `$product->decrement('stock_quantity', $n)`, which Eloquent applies as
     *     raw SQL *and* mirrors onto the in-memory attribute before firing this
     *     event, so the two agree.
     *
     * In both cases `updated` fires before `syncOriginalAttribute()` runs, so
     * getOriginal() is still the figure the row held when it was read.
     */
    public function updated(Product $product): void
    {
        if (!$product->wasChanged('stock_quantity')) {
            return;
        }

        $this->mirror(
            $product,
            (int) $product->stock_quantity - (int) $product->getOriginal('stock_quantity')
        );
    }

    /**
     * Apply one signed movement at the acting branch.
     *
     * A null branch means this install has no `branches` table yet (the
     * migration has not run), which is the one case where doing nothing is
     * right: there is no shelf to put the goods on, and the total - which is
     * what the whole app still reads - has already been written correctly.
     */
    private function mirror(Product $product, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $branchId = $this->branches->id();

        if ($branchId === null) {
            return;
        }

        BranchStock::move($branchId, (int) $product->getKey(), $delta);
    }
}
