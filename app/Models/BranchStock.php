<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * How much of one product is on one branch's shelves.
 *
 * READ THE RELATIONSHIP WITH `products.stock_quantity` BEFORE CHANGING ANYTHING
 *
 *     products.stock_quantity == SUM(branch_stock.stock_quantity) for that product
 *
 * `products.stock_quantity` is the group-wide total and stays the single-site
 * answer; this table is the split of that total between shops. They are not two
 * opinions about the same number - one is the sum of the other, always.
 *
 * Nothing writes this table directly except App\Observers\ProductStockObserver,
 * which mirrors every change to `products.stock_quantity` into the acting
 * branch's row. Moving stock by writing here and not there (or the other way
 * round) breaks the invariant, which is why `move()` below is private to that
 * observer's use and every other caller goes through the product.
 *
 * It is branch-scoped like everything else, so `BranchStock::where('product_id',
 * $id)->first()` is "what is on *my* shelf" without having to say so.
 */
class BranchStock extends Model
{
    /** @use HasFactory<\Database\Factories\BranchStockFactory> */
    use BelongsToBranch, HasFactory;

    /** Laravel would guess `branch_stocks`; the quantity of a thing is not plural. */
    protected $table = 'branch_stock';

    protected $fillable = [
        'branch_id',
        'product_id',
        'stock_quantity',
        'alert_quantity',
    ];

    protected $casts = [
        'stock_quantity' => 'integer',
        'alert_quantity' => 'integer',
    ];

    public function product()
    {
        // withTrashed: a shelf figure must still resolve its product after the
        // product has been archived, or a stock-take loses the name of what it
        // is counting.
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Move this branch's figure by a signed amount, atomically.
     *
     * Written as two statements rather than read-modify-write because two tills
     * in the same shop sell at the same moment: `insertOrIgnore` makes sure the
     * row exists without caring who created it, and the `UPDATE ... SET x = x +
     * n` leaves the arithmetic to the database. No lock, no race, no exception
     * to swallow - and it works the same on SQLite and MySQL, which the app has
     * to.
     *
     * `$delta` is cast to an integer before it reaches the SQL. It is never user
     * input (it is a difference between two stock figures), and binding it is
     * not possible inside a raw expression on both drivers.
     */
    public static function move(int $branchId, int $productId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $now = now();

        DB::table('branch_stock')->insertOrIgnore([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'stock_quantity' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('branch_stock')
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->update([
                'stock_quantity' => DB::raw('stock_quantity + (' . (int) $delta . ')'),
                'updated_at' => $now,
            ]);
    }

    /**
     * What is on one branch's shelf, without needing a row to exist.
     *
     * A product nobody has ever stocked at a branch has no row there, and that
     * is not the same as a missing figure - it is zero.
     */
    public static function quantity(int $branchId, int $productId): int
    {
        return (int) DB::table('branch_stock')
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->value('stock_quantity');
    }

    /** This branch's own reorder level, falling back to the product's. */
    public function reorderLevel(): int
    {
        return (int) ($this->alert_quantity ?: $this->product?->reorderLevel() ?: setting('low_stock_threshold', 5));
    }

    public function isLowStock(): bool
    {
        return $this->stock_quantity <= $this->reorderLevel();
    }
}
