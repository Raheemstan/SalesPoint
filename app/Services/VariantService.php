<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Variants of one style: "T-Shirt" in Red/S, Red/M, Blue/S ...
 *
 * A variant is a product row with `parent_id` set (see the migration and the
 * Product model for why), so most of what a variant needs - selling it, counting
 * it, buying it, reporting on it - needs no code here at all. What does live here
 * is the handful of rules the products table cannot state on its own:
 *
 *  - only one level: a variant may not have variants of its own;
 *  - a style that has variants is a grouping row, not something you sell, so its
 *    own stock is moved onto the variants and tracking is switched off on it;
 *  - a variant inherits its style's category, supplier and unit unless it is
 *    given its own;
 *  - and the bulk generator, which is what makes the feature usable: two axes in
 *    ("S,M,L" x "Red,Blue"), the six missing combinations out.
 */
class VariantService
{
    /**
     * Guard rails for the generator. Two axes of 30 would be 900 products from
     * one click, so the number of combinations is capped: a shop that really
     * wants 300 variants can generate them in batches and will notice it is
     * doing so.
     */
    public const MAX_COMBINATIONS = 120;
    public const MAX_AXIS_VALUES = 30;

    /**
     * Add one variant to a style.
     *
     * @param  array<string, mixed>  $attributes  variant_label (required), and any of
     *                                            sku, barcode, cost_price, sale_price,
     *                                            stock_quantity, alert_quantity,
     *                                            reorder_quantity, category_id,
     *                                            supplier_id, unit, serial_required
     *
     * @throws DomainException when the style is itself a variant, or the label is blank or taken
     */
    public function addVariant(Product $parent, array $attributes, ?User $user = null): Product
    {
        return DB::transaction(function () use ($parent, $attributes, $user) {
            // Lock the style: two managers adding variants at once must not both
            // decide to move its opening stock.
            $style = Product::lockForUpdate()->findOrFail($parent->id);

            $this->assertCanHaveVariants($style);

            $label = $this->cleanLabel($attributes['variant_label'] ?? null);

            if ($label === '') {
                throw new DomainException('A variant needs a label, for example "Red / XL".');
            }

            if ($this->labelTaken($style, $label)) {
                throw new DomainException("{$style->name} already has a \"{$label}\" variant.");
            }

            $columns = $this->columnsFor($style, $label, $attributes);

            // Only now, once we know the variant is going to be created.
            $this->markAsParent($style, $user);

            $variant = Product::create($columns);

            // Opening stock leaves a trail, exactly as it does for a new product.
            if ($variant->tracksStock() && (int) $variant->stock_quantity > 0) {
                $this->logStock($variant, 'IN', (int) $variant->stock_quantity, 'Opening stock', $user);
            }

            AuditLog::record("Added variant \"{$label}\" to {$style->name}", 'products', $variant->id);

            return $variant;
        });
    }

    /**
     * The bulk generator: every combination of one or two axes that does not
     * exist yet, with SKUs built from the style's own SKU plus a suffix.
     *
     * Combinations that already exist are skipped rather than refused, so running
     * it again after adding a size does exactly what you would hope: it fills in
     * the gaps and leaves the stock on the rows that are already selling alone.
     *
     * @param  array<string, mixed>  $defaults  cost_price, sale_price, stock_quantity,
     *                                          alert_quantity, reorder_quantity applied
     *                                          to every row it creates
     * @return array{created: Collection<int, Product>, skipped: array<int, string>}
     *
     * @throws DomainException on a blank first axis, too many values, or too many combinations
     */
    public function generate(Product $parent, ?string $axisOne, ?string $axisTwo = null, array $defaults = [], ?User $user = null): array
    {
        $this->assertCanHaveVariants($parent);

        $first = $this->splitValues($axisOne);
        $second = $this->splitValues($axisTwo);

        if (empty($first)) {
            throw new DomainException('Enter at least one value for the first axis, for example "S, M, L".');
        }

        if (count($first) > self::MAX_AXIS_VALUES || count($second) > self::MAX_AXIS_VALUES) {
            throw new DomainException('An axis can have at most ' . self::MAX_AXIS_VALUES . ' values.');
        }

        $labels = $this->combinations($first, $second);

        if (count($labels) > self::MAX_COMBINATIONS) {
            throw new DomainException(
                'That would create ' . count($labels) . ' variants; ' . self::MAX_COMBINATIONS .
                ' is the most in one go. Generate them in smaller batches.'
            );
        }

        $created = collect();
        $skipped = [];

        DB::transaction(function () use ($parent, $labels, $defaults, $user, &$created, &$skipped) {
            foreach ($labels as $label) {
                // Already there: skip it and leave its stock and price alone.
                // Checked per label rather than once up front, because each pass
                // of this loop adds to the set.
                if ($this->labelTaken($parent, $label)) {
                    $skipped[] = $label;
                    continue;
                }

                $created->push($this->addVariant($parent, array_merge($defaults, [
                    'variant_label' => $label,
                    // Each row gets its own generated SKU and no barcode; a
                    // shared one would never be valid.
                    'sku' => null,
                    'barcode' => null,
                ]), $user));
            }
        });

        if ($created->isNotEmpty()) {
            AuditLog::record(
                "Generated {$created->count()} variant(s) for {$parent->name}" .
                (count($skipped) ? ', ' . count($skipped) . ' already existed' : ''),
                'products',
                $parent->id
            );
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Only one level is allowed.
     *
     * Allowing a variant to have variants would turn every "what is the stock of
     * this style?" question into a tree walk, and there is no trade that needs
     * it: a third axis is another value on one of the two, "Red / XL / Cotton".
     *
     * @throws DomainException
     */
    public function assertCanHaveVariants(Product $product): void
    {
        if ($product->isVariant()) {
            throw new DomainException(
                "\"{$product->name}\" is itself a variant. Variants are one level deep: " .
                'add the extra choice to the label of a variant of its style instead.'
            );
        }
    }

    /**
     * May this product be deleted?
     *
     * A style with variants is refused. The alternative - detaching the children
     * so they stand alone - loses the one thing the parent was for (knowing that
     * these six rows are one style), and deleting them with it would hide stock
     * that is physically on the shelf and bury the sales history of six products
     * behind one click. Products are soft-deleted here, so `nullOnDelete` would
     * never even fire: the children would be left pointing at an archived parent
     * and vanish from the list entirely. Refusing says what to do instead.
     *
     * @throws DomainException
     */
    public function assertDeletable(Product $product): void
    {
        if ($product->hasVariants()) {
            $count = $product->variants()->count();

            throw new DomainException(
                "{$product->name} has {$count} variant(s) and cannot be deleted while it does. " .
                'Delete its variants first, or archive the style by unticking Active.'
            );
        }
    }

    /**
     * Turn a plain product into a grouping row by moving its own stock off it.
     *
     * Once the stock lives on the variants, leaving a figure on the style as well
     * would count the same shirts twice on every valuation, and would let the
     * till sell a "T-Shirt" that is not any particular size. The move is written
     * to the inventory log like any other stock movement - the shirts did not
     * disappear, they were re-counted somewhere else.
     *
     * What this deliberately does NOT do is switch `track_stock` off on the
     * style, tempting as it is (a style has no stock of its own to track). It
     * would be wrong twice over: the till only enforces stock on items that
     * track it, so an untracked style could be sold for ever out of nothing, and
     * deleting the last variant would leave that behind as a plain product the
     * till would sell with no stock at all. Tracking left on, at zero, means
     * every part of the app that was never written for variants treats a style as
     * out of stock - which is the safe answer. Keeping it off count sheets and
     * the reorder list is Product::scopeSellable()'s job, not the column's.
     *
     * Idempotent: with nothing left to move, it does nothing.
     */
    public function markAsParent(Product $parent, ?User $user = null): void
    {
        $this->assertCanHaveVariants($parent);

        $stock = (int) $parent->stock_quantity;

        if ($stock === 0) {
            return;
        }

        DB::transaction(function () use ($parent, $stock, $user) {
            if ($parent->tracksStock()) {
                $this->logStock($parent, 'ADJUST', -$stock, 'Stock moved onto variants', $user);
            }

            $parent->update(['stock_quantity' => 0]);
        });
    }

    /**
     * Keep variant names in step when the style is renamed.
     *
     * A variant's `name` column carries its style's name plus its label
     * ("T-Shirt - Red / XL") so that the till, receipts and every report show
     * something a customer would recognise without any of them having to know
     * what a variant is. The cost of that copy is this: when the style is
     * renamed, rows that still follow the pattern are renamed with it. One that
     * has been given its own name by hand is left alone.
     */
    public function syncVariantNames(Product $parent, string $previousName): void
    {
        if ($previousName === $parent->name) {
            return;
        }

        foreach ($parent->variants()->get() as $variant) {
            if ($variant->name === $this->variantName($previousName, (string) $variant->variant_label)) {
                $variant->update(['name' => $this->variantName($parent->name, (string) $variant->variant_label)]);
            }
        }
    }

    /**
     * "S, M , L" -> ['S', 'M', 'L']. Commas or new lines, blanks dropped,
     * duplicates dropped (typing "S, S, M" means two sizes, not three).
     *
     * @return array<int, string>
     */
    public function splitValues(?string $raw): array
    {
        $parts = preg_split('/[,\r\n]+/', (string) $raw) ?: [];

        return collect($parts)
            ->map(fn($value) => $this->cleanLabel($value))
            ->filter()
            ->unique(fn($value) => Str::upper($value))
            ->values()
            ->all();
    }

    /**
     * Every pairing of the two axes, as labels. One axis on its own gives its own
     * values back, so "S,M,L" with no colours is three variants, not none.
     *
     * @param  array<int, string>  $first
     * @param  array<int, string>  $second
     * @return array<int, string>
     */
    public function combinations(array $first, array $second = []): array
    {
        if (empty($second)) {
            return $first;
        }

        $labels = [];

        foreach ($first as $one) {
            foreach ($second as $two) {
                $labels[] = $this->labelFor([$one, $two]);
            }
        }

        return $labels;
    }

    /** "Red" + "XL" -> "Red / XL", the shape the whole app shows a variant in. */
    public function labelFor(array $parts): string
    {
        return collect($parts)
            ->map(fn($part) => $this->cleanLabel($part))
            ->filter()
            ->implode(' / ');
    }

    /**
     * A SKU for a variant: the style's own SKU plus a suffix from the label, so a
     * scan or a stock sheet reads "TSHIRT-RED-XL" rather than a random string.
     * A clash (two labels that slug the same, or a SKU already used elsewhere -
     * archived products included, because the column is unique across them too)
     * takes a counter.
     */
    public function skuFor(Product $parent, string $label): string
    {
        $suffix = Str::upper(Str::slug($label, '-'));
        $base = Str::upper((string) $parent->sku) . ($suffix === '' ? '' : '-' . $suffix);
        // products.sku is validated at 100 characters; leave room for the counter.
        $base = Str::limit($base, 90, '');

        $sku = $base;
        $counter = 1;

        while ($this->skuTaken($sku)) {
            $sku = $base . '-' . (++$counter);
        }

        return $sku;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** What a variant's `name` column holds: the style's name plus the label. */
    private function variantName(string $parentName, string $label): string
    {
        return trim($label === '' ? $parentName : $parentName . ' - ' . $label);
    }

    /**
     * The columns a new variant is created with.
     *
     * Category, supplier and unit are INHERITED from the style unless the form
     * gave its own - a blue shirt is in the same department, from the same
     * supplier, sold by the same piece as the red one, and retyping that six
     * times is how six rows end up disagreeing. Everything that is genuinely per
     * variant (its own SKU, barcode, cost, price, stock and reorder level) is
     * taken from the form and only falls back to the style's as a starting point.
     *
     * The picture is deliberately not copied: the style's photo is what the till
     * shows, and six copies of one file is six files to clean up.
     */
    private function columnsFor(Product $parent, string $label, array $attributes): array
    {
        $given = fn(string $key) => array_key_exists($key, $attributes)
            && $attributes[$key] !== null
            && $attributes[$key] !== '';

        /*
         * A variant is a physical thing you count, unless the style is a service
         * (a salon's "haircut" in short / long is still nothing to count). The
         * style's own track_stock is not asked: a shop that switched counting off
         * on a style did so because the style itself was never counted.
         */
        $tracks = !$parent->is_service;

        return [
            'parent_id' => $parent->id,
            'variant_label' => $label,
            'name' => $this->variantName((string) $parent->name, $label),
            'sku' => $given('sku') ? $attributes['sku'] : $this->skuFor($parent, $label),
            'barcode' => $given('barcode') ? $attributes['barcode'] : null,

            // Inherited unless overridden.
            'category_id' => $given('category_id') ? $attributes['category_id'] : $parent->category_id,
            'supplier_id' => $given('supplier_id') ? $attributes['supplier_id'] : $parent->supplier_id,
            'unit' => $given('unit') ? $attributes['unit'] : $parent->unit,
            'pack_size' => $parent->pack_size,

            // Its own money and its own stock.
            'cost_price' => $given('cost_price') ? $attributes['cost_price'] : $parent->cost_price,
            'sale_price' => $given('sale_price') ? $attributes['sale_price'] : $parent->sale_price,
            'stock_quantity' => $tracks ? (int) ($attributes['stock_quantity'] ?? 0) : 0,
            'alert_quantity' => $given('alert_quantity') ? (int) $attributes['alert_quantity'] : (int) $parent->alert_quantity,
            'reorder_quantity' => $given('reorder_quantity') ? (int) $attributes['reorder_quantity'] : (int) $parent->reorder_quantity,

            'is_service' => (bool) $parent->is_service,
            'track_stock' => $tracks,
            'serial_required' => $given('serial_required')
                ? (bool) $attributes['serial_required']
                : (bool) $parent->serial_required,
            'is_active' => $given('is_active') ? (bool) $attributes['is_active'] : true,
        ];
    }

    /** One space between words, no surrounding space; labels are compared on this. */
    private function cleanLabel(?string $label): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $label) ?? '');
    }

    private function labelTaken(Product $parent, string $label): bool
    {
        $wanted = Str::upper($this->cleanLabel($label));

        return $parent->variants()
            ->get(['id', 'variant_label'])
            ->contains(fn(Product $variant) => Str::upper($this->cleanLabel($variant->variant_label)) === $wanted);
    }

    private function skuTaken(string $sku): bool
    {
        // Archived rows keep their SKU, and the column is unique across them.
        return Product::withTrashed()->where('sku', $sku)->exists();
    }

    /**
     * Every stock change leaves a trail. (ProductController has the same two
     * lines for a product's opening stock; sharing them would mean a service
     * whose only job is to write one row.)
     */
    private function logStock(Product $product, string $type, int $quantity, string $reason, ?User $user = null): void
    {
        InventoryLog::create([
            'product_id' => $product->id,
            'user_id' => $user?->id ?? userId(),
            'type' => $type,
            'quantity' => $quantity,
            'reason' => $reason,
        ]);
    }
}
