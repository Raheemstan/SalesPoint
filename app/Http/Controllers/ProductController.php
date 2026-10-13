<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\VariantService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /** Stock only means something for an item that is counted in and out. */
    private const STOCK_FIELDS = ['stock_quantity', 'alert_quantity', 'batch_no', 'expiry_date', 'reorder_quantity'];

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Product::class);

        $search = trim((string) $request->input('search', ''));
        $grouped = feature('variants');

        $products = Product::with(['category', 'supplier'])
            // One subquery for the page rather than one per row: isLowStock() and
            // hasVariants() both need to know, and a style must not be shown as
            // low on stock it does not own.
            ->withCount('variants')
            // The list groups variants under their style, so only top-level rows
            // are paginated and each carries its own children. With the feature
            // switched off there is no grouping to do and every row is listed
            // flat - otherwise a shop that turned it off would lose sight of the
            // variants it already has, which would still be selling at the till.
            ->when($grouped, fn($query) => $query->topLevel()->with([
                'variants' => fn($q) => $q->orderBy('variant_label')->orderBy('name'),
            ]))
            ->when($search !== '', function ($query) use ($search, $grouped) {
                // Grouped so the OR conditions can't leak out of the search.
                $query->where(function ($q) use ($search, $grouped) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");

                    // A variant's own SKU or barcode has to find it too, and what
                    // it finds is the style it sits under.
                    if ($grouped) {
                        $q->orWhereHas('variants', function ($v) use ($search) {
                            $v->where('variant_label', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%")
                                ->orWhere('barcode', 'like', "%{$search}%");
                        });
                    }
                });
            })
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        $categories = Category::orderBy('name')->get();
        $suppliers = Supplier::active()->orderBy('name')->get();

        return view('products.index', compact('products', 'categories', 'suppliers'));
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Product::class);

        $validated = $request->validate($this->rules($request));

        // products.sku is NOT NULL + unique, but the form lets it be left blank.
        $validated['sku'] = $validated['sku'] ?? 'SKU-' . Str::upper(Str::random(8));

        $attributes = $this->columns($validated, null);

        if ($request->hasFile('image')) {
            $attributes['image_path'] = $request->file('image')->store('products', 'public');
        }

        DB::transaction(function () use ($attributes) {
            $product = Product::create($attributes);

            if ($product->tracksStock() && $product->stock_quantity > 0) {
                $this->logStock($product, 'IN', $product->stock_quantity, 'Opening stock');
            }
        });

        return back()->with('success', 'Product added successfully.');
    }

    public function update(Request $request, Product $product, VariantService $variants)
    {
        Gate::authorize('update', $product);

        $validated = $request->validate($this->rules($request, $product));

        // Keep the existing SKU if the field was cleared.
        $validated['sku'] = $validated['sku'] ?? $product->sku;

        $attributes = $this->columns($validated, $product);
        $replaced = null;
        $previousName = $product->name;

        if ($request->hasFile('image')) {
            $replaced = $product->image_path;
            $attributes['image_path'] = $request->file('image')->store('products', 'public');
        }

        DB::transaction(function () use ($attributes, $product, $previousName, $variants) {
            $before = $product->stock_quantity;
            $product->update($attributes);

            // Stock edited by hand must leave a trail like every other movement.
            if ($product->tracksStock() && $product->stock_quantity !== $before) {
                $this->logStock($product, 'ADJUST', $product->stock_quantity - $before, 'Manual adjustment');
            }

            // Renaming a style renames the variants that still carry its name.
            if ($product->hasVariants()) {
                $variants->syncVariantNames($product, $previousName);
            }
        });

        // Only once the new path is safely saved, so a failed update never leaves
        // a product pointing at a file that has been deleted.
        $this->deleteImage($replaced);

        return back()->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product, VariantService $variants)
    {
        Gate::authorize('delete', $product);

        try {
            // A style with variants is refused rather than detaching or deleting
            // them - see VariantService::assertDeletable() for why.
            $variants->assertDeletable($product);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Soft delete: sales and purchases that reference the product keep working.
        // The image stays with it, so restoring the row restores the picture.
        $product->delete();

        return back()->with('success', 'Product deleted successfully.');
    }

    /** Remove a product's picture without touching anything else about it. */
    public function destroyImage(Product $product)
    {
        Gate::authorize('update', $product);

        $path = $product->image_path;

        if (!$path) {
            return back()->with('error', 'This product has no image.');
        }

        $product->update(['image_path' => null]);
        $this->deleteImage($path);

        AuditLog::record('Removed product image', 'products', $product->id);

        return back()->with('success', 'Image removed.');
    }

    /*
    |--------------------------------------------------------------------------
    | Variants
    |--------------------------------------------------------------------------
    |
    | A variant is a product with a parent (see App\Services\VariantService), so
    | there is nothing here for editing or deleting one: the ordinary product
    | update and delete already do it. Only creating needs its own door, because
    | that is where the rules live - one level only, inherited category and
    | supplier, a SKU built from the style's, and the style's own stock moved onto
    | its variants the first time one is added.
    |
    */

    /** Add a single variant to a style. */
    public function storeVariant(Request $request, Product $product, VariantService $variants)
    {
        Gate::authorize('create', Product::class);
        $this->assertVariantsEnabled();

        $data = $request->validate([
            'variant_label' => 'required|string|max:120',
            'sku' => 'nullable|string|max:100|unique:products,sku',
            'barcode' => 'nullable|string|max:100|unique:products,barcode',
            'cost_price' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'alert_quantity' => 'nullable|integer|min:0',
            'reorder_quantity' => 'nullable|integer|min:0|max:1000000',
        ]);

        try {
            $variant = $variants->addVariant($product, $data, $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Variant \"{$variant->variant_label}\" added ({$variant->sku}).");
    }

    /**
     * The bulk generator: two axes in, every missing combination out.
     *
     * This is what makes variants usable - nobody types in eighteen rows by
     * hand - and it is deliberately server-side. The browser could build the
     * same list, but then the SKUs, the "which of these already exist?" check
     * and the limits would all be decided somewhere they cannot be enforced.
     */
    public function generateVariants(Request $request, Product $product, VariantService $variants)
    {
        Gate::authorize('create', Product::class);
        $this->assertVariantsEnabled();

        $data = $request->validate([
            // The axis names are only there to label the boxes on screen.
            'axis_one_name' => 'nullable|string|max:40',
            'axis_one' => 'required|string|max:500',
            'axis_two_name' => 'nullable|string|max:40',
            'axis_two' => 'nullable|string|max:500',
            // Applied to every row created, falling back to the style's own.
            'cost_price' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'alert_quantity' => 'nullable|integer|min:0',
            'reorder_quantity' => 'nullable|integer|min:0|max:1000000',
        ]);

        $defaults = array_intersect_key($data, array_flip([
            'cost_price', 'sale_price', 'stock_quantity', 'alert_quantity', 'reorder_quantity',
        ]));

        try {
            $result = $variants->generate(
                $product,
                $data['axis_one'],
                $data['axis_two'] ?? null,
                $defaults,
                $request->user()
            );
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $created = $result['created']->count();
        $skipped = count($result['skipped']);

        if ($created === 0) {
            return back()->with('error', 'Nothing to generate: all ' . $skipped . ' of those combinations already exist.');
        }

        return back()->with('success', trim(
            "Created {$created} variant(s) of {$product->name}." .
            ($skipped ? " {$skipped} already existed and were left alone." : '')
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * A trade with no variants has no variant screens either, exactly as the
     * table-service controllers 404 when the business type has no tables.
     */
    private function assertVariantsEnabled(): void
    {
        abort_unless(feature('variants'), 404);
    }

    /**
     * The validated form turned into columns.
     *
     * Three things are deliberate here:
     *
     *  - A service (labour, a haircut, a delivery fee) has no stock, so the stock
     *    fields are *dropped*, not saved as zero. Saving zero would put the item
     *    on the low-stock list and refuse to sell it the moment stock checks were
     *    switched on anywhere.
     *  - Nor does a style that has variants: its stock lives on them (see
     *    VariantService::markAsParent), the form does not show the fields, and
     *    writing them would count the same goods twice.
     *  - A checkbox that was not ticked is simply absent from the request, which
     *    is indistinguishable from a form that never had the field. So a flag is
     *    only written when the request carried it (the form posts a hidden 0
     *    ahead of each box), and left to the column default when creating.
     */
    private function columns(array $validated, ?Product $product): array
    {
        $flags = ['is_service', 'track_stock', 'serial_required', 'is_active'];
        $attributes = array_diff_key($validated, array_flip(array_merge($flags, ['image'])));

        foreach ($flags as $flag) {
            if (array_key_exists($flag, $validated)) {
                $attributes[$flag] = (bool) $validated[$flag];
            }
        }

        // What the item *is* may not be in this request at all: a trade with the
        // service toggle switched off never renders the field, so fall back to
        // what the product already says about itself.
        $service = (bool) ($attributes['is_service'] ?? $product?->is_service ?? false);

        if ($service) {
            // Nothing to count, so nothing to count with.
            $attributes = array_diff_key($attributes, array_flip(self::STOCK_FIELDS));
            $attributes['track_stock'] = false;
        } elseif ($product?->hasVariants()) {
            // The variants carry the stock; the style carries none.
            $attributes = array_diff_key($attributes, array_flip(self::STOCK_FIELDS));
        }

        // alert_quantity is NOT NULL (default 5): leave it out rather than send null.
        if (($attributes['alert_quantity'] ?? null) === null) {
            unset($attributes['alert_quantity']);
        }

        foreach (['unit', 'pack_size', 'reorder_quantity'] as $key) {
            // Same story: these columns have defaults and are NOT NULL.
            if (array_key_exists($key, $attributes) && ($attributes[$key] === null || $attributes[$key] === '')) {
                unset($attributes[$key]);
            }
        }

        if (!$product) {
            /*
             * A new row has to carry these explicitly rather than lean on the
             * column defaults: the model in memory would otherwise have them as
             * null, and anything asking tracksStock() right after creating it -
             * the opening-stock log, for one - would get the wrong answer.
             */
            $attributes['is_service'] = $service;
            $attributes['track_stock'] = $attributes['track_stock'] ?? !$service;
            $attributes['is_active'] = $attributes['is_active'] ?? true;
        }

        return $attributes;
    }

    private function deleteImage(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function logStock(Product $product, string $type, int $quantity, string $reason): void
    {
        InventoryLog::create([
            'product_id' => $product->id,
            'user_id' => userId(),
            'type' => $type,
            'quantity' => $quantity,
            'reason' => $reason,
        ]);
    }

    private function rules(Request $request, ?Product $product = null): array
    {
        /*
         * A service has no stock level to give, so the form does not show the
         * field and must not be told off for leaving it out. When the request is
         * silent on the matter, what the product already is decides. A trade that
         * counts nothing at all (feature('stock') off) never shows the field
         * either, and nor does a style whose stock sits on its variants, so the
         * rule has to agree with the form on all three counts.
         */
        $service = $request->has('is_service')
            ? $request->boolean('is_service')
            : (bool) $product?->is_service;

        $stockless = $service || !feature('stock') || (bool) $product?->hasVariants();

        $id = $product?->id;

        return [
            'name'           => 'required|string|max:255',
            'sku'            => 'nullable|string|max:100|unique:products,sku,' . $id,
            'barcode'        => 'nullable|string|max:100|unique:products,barcode,' . $id,
            // products.category_id is NOT NULL, so it can't be optional.
            'category_id'    => 'required|exists:categories,id',
            'supplier_id'    => 'nullable|exists:suppliers,id',
            'cost_price'     => 'required|numeric|min:0',
            'sale_price'     => 'required|numeric|min:0',
            'unit'           => 'nullable|string|max:20',
            'pack_size'      => 'nullable|numeric|min:0.001|max:999999',
            'is_service'     => 'nullable|boolean',
            'track_stock'    => 'nullable|boolean',
            /*
             * Only the label is editable here. A product's *parent* is not: a
             * variant is created through the variant screen, where the rules that
             * cannot be expressed as validation (one level only, inherited
             * category and supplier, a SKU built from the style's) are applied.
             * Leaving parent_id out of this form means they cannot be sidestepped
             * by posting one.
             */
            'variant_label'  => 'nullable|string|max:120',
            'serial_required' => 'nullable|boolean',
            'stock_quantity' => [$stockless ? 'nullable' : 'required', 'integer', 'min:0'],
            'alert_quantity' => 'nullable|integer|min:0',
            'batch_no'       => 'nullable|string|max:100',
            'expiry_date'    => 'nullable|date',
            'reorder_quantity' => 'nullable|integer|min:0|max:1000000',
            'is_active'      => 'nullable|boolean',
            // Mime types are checked as well as the extension: an .png that is
            // really a script must not be stored under the public disk.
            'image'          => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
        ];
    }
}
