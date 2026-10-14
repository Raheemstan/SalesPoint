<?php

namespace App\Models;

use App\Services\BranchContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'sku',
        'barcode',
        'image_path',
        'parent_id',
        'variant_label',
        'category_id',
        'supplier_id',
        'cost_price',
        'sale_price',
        'unit',
        'pack_size',
        'is_service',
        'track_stock',
        'serial_required',
        'stock_quantity',
        'alert_quantity',
        'batch_no',
        'expiry_date',
        'reorder_quantity',
        'is_active',
    ];

    protected $casts = [
        'is_service' => 'boolean',
        'track_stock' => 'boolean',
        'serial_required' => 'boolean',
        'is_active' => 'boolean',
        'expiry_date' => 'date',
        'pack_size' => 'decimal:3',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /** Who we normally buy this from; used to group the reorder list. */
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function inventoryLogs()
    {
        return $this->hasMany(InventoryLog::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Variants
    |--------------------------------------------------------------------------
    |
    | A VARIANT IS A PRODUCT WITH A PARENT. There is no separate variants table
    | on purpose: a variant is something you sell, and a product row already has
    | its own SKU, barcode, cost, price and stock level, so the till, sale_items,
    | inventory_logs, stock-takes, the reorder list, purchases and every report
    | work on a variant with no changes at all. A separate table would have meant
    | a parallel "is this a product or a variant?" code path in every one of them.
    |
    | What that costs is two rules the database cannot state and this class has
    | to: only one level is allowed (enforced in App\Services\VariantService), and
    | a parent is a grouping row rather than something you sell - its stock is the
    | sum of its variants' and it must stay out of the till, stock-takes, the
    | reorder list and low-stock warnings (see scopeSellable()).
    */

    /** The style this row is a variant of, if it is one. */
    public function parent()
    {
        return $this->belongsTo(Product::class, 'parent_id');
    }

    public function variants()
    {
        return $this->hasMany(Product::class, 'parent_id');
    }

    /** Serials recorded against this item, one row per unit sold. */
    public function serials()
    {
        return $this->hasMany(SaleItemSerial::class);
    }

    public function isVariant(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * Is this a grouping row? Answered from whatever is already loaded -
     * withCount('variants') or the loaded relation - so a list of products does
     * not fire a query per row.
     */
    public function hasVariants(): bool
    {
        // Only one level is allowed, so a variant can never have variants of its
        // own and does not need to be asked.
        if ($this->isVariant()) {
            return false;
        }

        if ($this->relationLoaded('variants')) {
            return $this->variants->isNotEmpty();
        }

        if (array_key_exists('variants_count', $this->attributes)) {
            return (int) $this->variants_count > 0;
        }

        return $this->variants()->exists();
    }

    /** Can this row itself be sold? A parent cannot: its variants are what you sell. */
    public function isSellable(): bool
    {
        return !$this->hasVariants();
    }

    /**
     * What to call this on a receipt or in the till. A variant carries its parent's
     * name plus its label ("T-Shirt - Red / XL") in its own `name` column, so this
     * is only a fallback for rows saved before a label existed.
     */
    public function displayName(): string
    {
        if ($this->variant_label && !str_contains((string) $this->name, (string) $this->variant_label)) {
            return $this->name . ' - ' . $this->variant_label;
        }

        return (string) $this->name;
    }

    /**
     * Stock to show for this row: a parent has none of its own, so it is the sum
     * of its variants'. (Its own column is zeroed when it gains its first
     * variant - see VariantService - so nothing is counted twice.)
     */
    public function totalStock(): int
    {
        if (!$this->hasVariants()) {
            return (int) $this->stock_quantity;
        }

        return (int) ($this->relationLoaded('variants')
            ? $this->variants->sum('stock_quantity')
            : $this->variants()->sum('stock_quantity'));
    }

    /** The same sum, valued at cost: what a parent's row is worth on a valuation. */
    public function totalStockValue(): float
    {
        if (!$this->hasVariants()) {
            return $this->stock_value;
        }

        $variants = $this->relationLoaded('variants') ? $this->variants : $this->variants()->get();

        return round((float) $variants->sum(fn(Product $variant) => $variant->stock_value), 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Stock
    |--------------------------------------------------------------------------
    */

    /**
     * Does this item have a stock level at all? A service (a haircut, a delivery
     * fee, an hour of labour) never runs out, so it must never block a sale or
     * show up on the reorder list.
     *
     * A style with variants answers *yes*, at zero. It owns no stock - the stock
     * is on its variants, and VariantService moves it there - but saying no would
     * tell the till it may sell the style without counting anything, which is
     * exactly what must not happen. "Tracked, and none left" is the safe answer
     * for every screen that was never written for variants; the screens that must
     * skip a style altogether use scopeSellable().
     */
    public function tracksStock(): bool
    {
        return $this->track_stock && !$this->is_service;
    }

    /** Must a serial / IMEI be recorded for each unit of this sold? */
    public function requiresSerials(): bool
    {
        return (bool) $this->serial_required && !$this->is_service && feature('serials');
    }

    /** The level at which this item counts as low, falling back to the shop-wide setting. */
    public function reorderLevel(): int
    {
        return (int) ($this->alert_quantity ?: setting('low_stock_threshold', 5));
    }

    /**
     * A style never runs low: each of its variants does, on its own level. (Load
     * the variants, or withCount('variants'), when asking this for a list of
     * products, or every row pays for a subquery to find out it has none.)
     */
    public function isLowStock(): bool
    {
        return $this->tracksStock()
            && !$this->hasVariants()
            && (int) $this->stock_quantity <= $this->reorderLevel();
    }

    /** Stock valued at what it cost us (not what we hope to sell it for). */
    public function getStockValueAttribute(): float
    {
        return $this->tracksStock() ? round((float) $this->cost_price * (int) $this->stock_quantity, 2) : 0.0;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Rows that are themselves sold. A parent is a grouping row: it must not
     * appear at the till, on a count sheet or on the reorder list. Its variants
     * do, each with its own stock.
     *
     * Soft deletes are respected by the subquery, so a style whose variants have
     * all been archived becomes sellable again by itself.
     */
    public function scopeSellable($query)
    {
        return $query->whereDoesntHave('variants');
    }

    /** Items that are counted in and out of stock (excludes services and parents). */
    public function scopeStocked($query)
    {
        return $query->where('track_stock', true)->where('is_service', false)->sellable();
    }

    public function scopeLowStock($query)
    {
        $fallback = (int) setting('low_stock_threshold', 5);

        return $query->stocked()->whereRaw(
            'stock_quantity <= CASE WHEN alert_quantity IS NULL OR alert_quantity = 0 THEN ? ELSE alert_quantity END',
            [$fallback]
        );
    }

    /** Styles and plain products: everything that is not itself a variant. */
    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    /** Only grouping rows: a style that has variants under it. */
    public function scopeParents($query)
    {
        return $query->whereNull('parent_id')->whereHas('variants');
    }

    /**
     * Only variants. Not named scopeVariants(): `Product::variants()` would
     * resolve to the relation of that name, not to the scope.
     */
    public function scopeVariantsOnly($query)
    {
        return $query->whereNotNull('parent_id');
    }

    /** Items whose units are individually identified (reports, serial coverage). */
    public function scopeRequiresSerials($query)
    {
        return $query->where('serial_required', true)->where('is_service', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Per-branch stock
    |--------------------------------------------------------------------------
    |
    | THE CATALOGUE IS SHARED; THE STOCK IS NOT. A product row is the same row in
    | every shop - one SKU, one barcode, one price - and `stock_quantity` above is
    | the GROUP-WIDE TOTAL. How that total is split between shops lives in
    | `branch_stock`, and the two are tied together by one invariant:
    |
    |     stock_quantity == SUM(branch_stock.stock_quantity) for this product
    |
    | maintained by App\Observers\ProductStockObserver. With one branch they are
    | the same number, which is why everything above this comment - the till, the
    | reorder list, stock-takes, valuation, the lowStock scope - keeps working
    | untouched for a single-site shop, and keeps answering the group-wide
    | question for a multi-branch one.
    |
    | Anything that needs *this shelf* rather than *the business* asks here.
    */

    /**
     * This product's shelf figures.
     *
     * Branch-scoped like BranchStock itself, so `$product->branchStock` is "my
     * branch's row", singular in practice. Use withoutBranchScope() on it for
     * every shop's.
     */
    public function branchStock()
    {
        return $this->hasMany(BranchStock::class);
    }

    /**
     * Add one named branch's shelf figure to a product list as
     * `branch_stock_quantity`, in one subquery rather than a query per row.
     *
     * Defaults to the branch being acted as, which is what a per-branch product
     * list wants. COALESCE, because a product nobody has stocked here has no row
     * and that means zero, not unknown.
     */
    public function scopeWithBranchStock($query, Branch|int|null $branch = null)
    {
        $branchId = $branch instanceof Branch ? $branch->getKey() : ($branch ?? app(BranchContext::class)->id());

        return $query->addSelect([
            'branch_stock_quantity' => BranchStock::query()
                // Deliberately escaping the branch scope: the branch is named in
                // the next line, so filtering by the acting one as well would
                // make this subquery answer for the wrong shop (or for none).
                ->forBranch($branchId)
                ->selectRaw('COALESCE(SUM(stock_quantity), 0)')
                ->whereColumn('branch_stock.product_id', 'products.id'),
        ]);
    }

    /** Products a branch actually holds something of. */
    public function scopeStockedAt($query, Branch|int|null $branch = null)
    {
        $branchId = $branch instanceof Branch ? $branch->getKey() : ($branch ?? app(BranchContext::class)->id());

        return $query->whereHas(
            'branchStock',
            fn ($stock) => $stock->forBranch($branchId)->where('stock_quantity', '>', 0)
        );
    }

    /**
     * How many of these are on one branch's shelf.
     *
     * Reads a `withBranchStock()` figure when the query already loaded one, so a
     * list of products costs no extra queries; falls back to asking directly.
     *
     * When branches are off this is still correct: the one branch holds the whole
     * total, so it returns the same number `stock_quantity` does.
     */
    public function stockAt(Branch|int|null $branch = null): int
    {
        if ($branch === null && array_key_exists('branch_stock_quantity', $this->attributes)) {
            return (int) $this->attributes['branch_stock_quantity'];
        }

        $branchId = $branch instanceof Branch ? $branch->getKey() : ($branch ?? app(BranchContext::class)->id());

        if ($branchId === null) {
            // No branches table: the total is the only answer there is.
            return (int) $this->stock_quantity;
        }

        return BranchStock::quantity((int) $branchId, (int) $this->getKey());
    }

    /**
     * Where every unit of this product is, shop by shop.
     *
     * For the one screen that is honestly group-wide: "we are out of it here,
     * who has some?".
     *
     * @return \Illuminate\Support\Collection<int, int>  branch id => units
     */
    public function stockByBranch(): \Illuminate\Support\Collection
    {
        return $this->branchStock()
            // Group-wide on purpose: the question is "which branches", so being
            // filtered to one of them would answer nothing.
            ->withoutBranchScope()
            ->pluck('stock_quantity', 'branch_id')
            ->map(fn ($units) => (int) $units);
    }
}
