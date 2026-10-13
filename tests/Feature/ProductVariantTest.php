<?php

use App\Http\Controllers\ProductController;
use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Services\StockTakeService;
use App\Services\VariantService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Variants: one style in many sizes and colours
|--------------------------------------------------------------------------
|
| A variant is a product with a parent, which is the whole point: the rest of
| the app - the till, sale_items, inventory logs, stock-takes, the reorder list,
| purchases, reports - needs no code at all to handle one. These tests hold that
| claim up, and pin the two rules the shape does need: a style is not itself
| sellable, and variants are one level deep.
|
*/

beforeEach(function () {
    // Fashion: stock and variants on.
    Setting::set('business_type', 'fashion');

    $this->manager = User::factory()->manager()->create();
    $this->actingAs($this->manager);
    $this->category = Category::factory()->create();
    $this->variants = app(VariantService::class);

    registerVariantRoutes();
});

/**
 * The two routes the coordinator still has to register in routes/web.php (they
 * are listed in the summary). Declared here so the controller can be exercised
 * today; skipped once they exist for real.
 *
 * refreshNameLookups() because ->name() is called after the route has been added
 * to the collection, so the name index does not know about it yet - the same
 * call Laravel's own RouteServiceProvider makes once routes/web.php is loaded.
 */
function registerVariantRoutes(): void
{
    if (Route::has('products.variants.store')) {
        return;
    }

    Route::middleware(['web', 'auth', 'role:admin|manager'])->group(function () {
        Route::post('/products/{product}/variants', [ProductController::class, 'storeVariant'])
            ->name('products.variants.store');
        Route::post('/products/{product}/variants/generate', [ProductController::class, 'generateVariants'])
            ->name('products.variants.generate');
    });

    Route::getRoutes()->refreshNameLookups();
}

function tShirt(array $overrides = []): Product
{
    return Product::factory()->create(array_merge([
        'name' => 'T-Shirt',
        'sku' => 'TSH',
        'barcode' => null,
        'category_id' => test()->category->id,
        'cost_price' => 10,
        'sale_price' => 25,
        'stock_quantity' => 0,
    ], $overrides));
}

test('a style with variants is left out of the sellable, stocked and low-stock scopes', function () {
    // Built by hand with tracking still on - a CSV import can do exactly this -
    // so it is scopeSellable() doing the work and not just the column.
    $style = tShirt(['track_stock' => true, 'stock_quantity' => 2, 'alert_quantity' => 5]);
    $red = Product::factory()->variantOf($style, 'Red')->create(['stock_quantity' => 50, 'alert_quantity' => 5]);
    $blue = Product::factory()->variantOf($style, 'Blue')->create(['stock_quantity' => 1, 'alert_quantity' => 5]);
    $plain = tShirt(['name' => 'Cap', 'sku' => 'CAP', 'stock_quantity' => 30]);

    expect(Product::sellable()->pluck('id')->all())->not->toContain($style->id)
        ->and(Product::sellable()->pluck('id')->all())->toContain($red->id, $blue->id, $plain->id)
        ->and(Product::stocked()->pluck('id')->all())->not->toContain($style->id)
        // Running low is a question about the variant on the shelf, not the style.
        ->and(Product::lowStock()->pluck('id')->all())->toContain($blue->id)
        ->and(Product::lowStock()->pluck('id')->all())->not->toContain($style->id);

    // Archiving the last variant hands the style back its own life.
    $red->delete();
    $blue->delete();

    expect(Product::sellable()->pluck('id')->all())->toContain($style->id)
        ->and($style->fresh()->hasVariants())->toBeFalse();
});

test('a style shows the total stock of its variants, not its own', function () {
    $style = tShirt(['stock_quantity' => 0]);
    $this->variants->addVariant($style, ['variant_label' => 'Red / S', 'stock_quantity' => 4, 'cost_price' => 10]);
    $this->variants->addVariant($style->fresh(), ['variant_label' => 'Red / M', 'stock_quantity' => 6, 'cost_price' => 10]);

    $style = $style->fresh();

    expect($style->totalStock())->toBe(10)
        ->and($style->totalStockValue())->toBe(100.0)
        // Its own row is worth nothing: the goods are counted on the variants.
        ->and($style->stock_value)->toBe(0.0)
        ->and($style->isLowStock())->toBeFalse();
});

test('the product list groups variants under their style', function () {
    $style = tShirt();
    $this->variants->generate($style, 'S, M', 'Red', ['stock_quantity' => 3]);

    $response = $this->get(route('products.index'))->assertOk();

    $response->assertSee('T-Shirt')
        ->assertSee('2 variants')
        ->assertSee('across 2 variants')
        // The panel under the row carries each variant's own label and SKU.
        ->assertSee('S / Red')
        ->assertSee('TSH-S-RED')
        ->assertSee('Generate variants');

    // The variants are not listed again as rows of their own.
    expect($response->viewData('products')->pluck('id')->all())->toBe([$style->id]);
});

test('the bulk generator creates exactly the missing combinations', function () {
    $style = tShirt();

    $this->post(route('products.variants.store', $style), [
        'variant_label' => 'S / Red',
        'sale_price' => 99,
        'stock_quantity' => 7,
    ])->assertSessionHas('success');

    $this->post(route('products.variants.generate', $style), [
        'axis_one_name' => 'Size',
        'axis_one' => 'S, M, L',
        'axis_two_name' => 'Colour',
        'axis_two' => 'Red, Blue',
        'sale_price' => 25,
        'stock_quantity' => 2,
    ])->assertSessionHas('success');

    $labels = $style->variants()->pluck('variant_label')->sort()->values()->all();

    expect($labels)->toBe(['L / Blue', 'L / Red', 'M / Blue', 'M / Red', 'S / Blue', 'S / Red'])
        // Generated SKUs all come from the style's and are unique.
        ->and($style->variants()->pluck('sku')->unique())->toHaveCount(6)
        ->and($style->variants()->pluck('sku')->filter(fn($sku) => str_starts_with($sku, 'TSH-')))->toHaveCount(6)
        // The one that already existed kept its price and its stock.
        ->and((float) $style->variants()->where('variant_label', 'S / Red')->first()->sale_price)->toBe(99.0)
        ->and($style->variants()->where('variant_label', 'S / Red')->first()->stock_quantity)->toBe(7);

    // Every new variant counted its opening stock in.
    expect(InventoryLog::where('type', 'IN')->where('quantity', 2)->count())->toBe(5);

    // Nothing left to generate.
    $this->post(route('products.variants.generate', $style), ['axis_one' => 'S, M, L', 'axis_two' => 'Red, Blue'])
        ->assertSessionHas('error');

    expect($style->variants()->count())->toBe(6);
});

test('a new variant needs a label, and the same one cannot be used twice', function () {
    $style = tShirt();

    $this->post(route('products.variants.store', $style), ['stock_quantity' => 1])
        ->assertSessionHasErrors('variant_label');

    $this->post(route('products.variants.store', $style), ['variant_label' => 'Red / XL'])
        ->assertSessionHas('success');

    $this->post(route('products.variants.store', $style), ['variant_label' => 'Red / XL'])
        ->assertSessionHas('error');

    expect($style->variants()->count())->toBe(1);
});

test('a variant cannot be given variants of its own', function () {
    $style = tShirt();
    $variant = $this->variants->addVariant($style, ['variant_label' => 'Red / XL']);

    $this->post(route('products.variants.store', $variant), ['variant_label' => 'Cotton'])
        ->assertSessionHas('error');

    $this->post(route('products.variants.generate', $variant), ['axis_one' => 'Cotton, Linen'])
        ->assertSessionHas('error');

    expect(Product::where('parent_id', $variant->id)->count())->toBe(0);
});

test('a style cannot be deleted while it still has variants', function () {
    $style = tShirt();
    $variant = $this->variants->addVariant($style, ['variant_label' => 'Red / XL', 'stock_quantity' => 5]);

    $this->delete(route('products.destroy', $style))->assertSessionHas('error');

    expect(Product::find($style->id))->not->toBeNull()
        ->and($style->variants()->count())->toBe(1);

    // The variant itself is only a product, and goes as one.
    $this->delete(route('products.destroy', $variant))->assertSessionHas('success');
    expect(Product::find($variant->id))->toBeNull();

    // With nothing under it, the style can go too.
    $this->delete(route('products.destroy', $style))->assertSessionHas('success');
    expect(Product::find($style->id))->toBeNull();
});

test('a variant is sold at the till like any other product, with no special handling', function () {
    $style = tShirt();
    $variant = $this->variants->addVariant($style, [
        'variant_label' => 'Red / XL',
        'stock_quantity' => 5,
        'sale_price' => 25,
    ]);

    checkout(cartOf([[$variant, 2]]))->assertRedirect();

    $sale = Sale::latest('id')->first();

    expect($sale)->not->toBeNull()
        ->and($variant->fresh()->stock_quantity)->toBe(3)
        ->and($sale->items->first()->product_id)->toBe($variant->id)
        // The style was untouched: it has no stock of its own to lose.
        ->and($style->fresh()->stock_quantity)->toBe(0)
        ->and(InventoryLog::where('product_id', $variant->id)->where('type', 'OUT')->exists())->toBeTrue();
});

test('a style is never counted on a stock-take or put on the reorder list', function () {
    $style = tShirt(['track_stock' => true, 'stock_quantity' => 1, 'alert_quantity' => 5, 'name' => 'Grouping style']);
    Product::factory()->variantOf($style, 'Red')->create([
        'name' => 'Grouping style - Red', 'stock_quantity' => 1, 'alert_quantity' => 5,
    ]);

    $sheet = app(StockTakeService::class)->createSheet($this->manager);

    expect($sheet->items()->pluck('product_id')->all())->not->toContain($style->id)
        ->and($sheet->items()->count())->toBe(1);

    $this->get(route('reorder.index'))->assertOk()
        ->assertSee('Grouping style - Red')
        ->assertDontSee('<td class="px-4 py-2">Grouping style</td>', false);

    expect(Product::lowStock()->pluck('id')->all())->not->toContain($style->id);
});

test('searching finds a style by one of its variants SKU or label', function () {
    $style = tShirt(['name' => 'Linen Shirt', 'sku' => 'LIN']);
    $this->variants->addVariant($style, ['variant_label' => 'Ecru / 42', 'sku' => 'LIN-ECRU-42']);
    tShirt(['name' => 'Wool Hat', 'sku' => 'WOOL']);

    $this->get(route('products.index', ['search' => 'LIN-ECRU-42']))->assertOk()
        ->assertSee('Linen Shirt')
        ->assertDontSee('Wool Hat');

    $this->get(route('products.index', ['search' => 'Ecru']))->assertOk()
        ->assertSee('Linen Shirt')
        ->assertDontSee('Wool Hat');
});

test('none of the variant interface is shown to a trade that has no variants', function () {
    Setting::set('business_type', 'pharmacy');

    $style = tShirt();

    $this->get(route('products.index'))->assertOk()
        ->assertDontSee('Generate variants')
        ->assertDontSee('Add variants')
        ->assertDontSee('Variant label');

    // And the screens behind it are not there at all.
    $this->post(route('products.variants.store', $style), ['variant_label' => 'Red'])->assertNotFound();
    $this->post(route('products.variants.generate', $style), ['axis_one' => 'S, M'])->assertNotFound();

    expect(Product::whereNotNull('parent_id')->count())->toBe(0);

    // A per-install override beats the preset, as it does for every other feature.
    Setting::set('feature_variants', '1');
    $this->get(route('products.index'))->assertOk()->assertSee('Generate variants');
});

test('a cashier cannot add or generate variants', function () {
    $style = tShirt();

    $this->actingAs(User::factory()->cashier()->create());

    $this->post(route('products.variants.store', $style), ['variant_label' => 'Red / XL'])->assertForbidden();
    $this->post(route('products.variants.generate', $style), ['axis_one' => 'S, M'])->assertForbidden();

    expect($style->variants()->count())->toBe(0);
});

test('a style keeps its stock fields out of the way once it has variants', function () {
    $style = tShirt(['stock_quantity' => 9]);
    $this->variants->addVariant($style, ['variant_label' => 'Red / XL', 'stock_quantity' => 9]);

    // The form no longer asks for a stock figure, so updating without one is fine
    // and nothing it posts can put stock back onto the style.
    $this->put(route('products.update', $style), [
        'name' => 'T-Shirt (2026)',
        'sku' => 'TSH',
        'category_id' => $this->category->id,
        'cost_price' => 10,
        'sale_price' => 25,
        'stock_quantity' => 500,
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    expect($style->fresh()->stock_quantity)->toBe(0)
        ->and($style->fresh()->name)->toBe('T-Shirt (2026)')
        // Renaming the style renamed its variant with it.
        ->and($style->variants()->first()->name)->toBe('T-Shirt (2026) - Red / XL')
        ->and(InventoryLog::where('product_id', $style->id)->where('reason', 'Manual adjustment')->count())->toBe(0);
});

test('a style has no stock of its own, so the till cannot put one in a cart', function () {
    $style = tShirt(['stock_quantity' => 12]);
    $this->variants->addVariant($style, ['variant_label' => 'Red / XL', 'stock_quantity' => 12]);

    /*
     * The till is another agent's file, so this is what protects it today: a
     * style's stock is moved onto its variants, and POSController refuses to add
     * anything it has none of. The product list and the search box there still
     * offer the row until POSController asks for Product::sellable() - see the
     * summary for the two lines that need.
     */
    $this->postJson(route('pos.add', $style->id))->assertStatus(422);

    expect(session('cart', []))->toBeEmpty();
});

test('the migration rolls back and reapplies cleanly', function () {
    $migration = require base_path('database/migrations/2026_10_14_000009_add_product_variants_and_serials.php');

    // SQLite will not drop an indexed column while the index still exists, so
    // this is the test that the order in down() is right.
    $migration->down();

    expect(Schema::hasTable('sale_item_serials'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'parent_id'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'variant_label'))->toBeFalse()
        ->and(Schema::hasColumn('products', 'serial_required'))->toBeFalse()
        // Nothing the rest of the app depends on goes with them.
        ->and(Schema::hasColumn('products', 'stock_quantity'))->toBeTrue()
        ->and(Schema::hasTable('sale_items'))->toBeTrue();

    $migration->up();

    expect(Schema::hasColumn('products', 'parent_id'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'variant_label'))->toBeTrue()
        ->and(Schema::hasColumn('products', 'serial_required'))->toBeTrue()
        ->and(Schema::hasColumn('sale_item_serials', 'serial'))->toBeTrue();
});
