<?php

use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\VariantService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| tests/Pest.php only binds the Laravel test case to the Feature suite, so a
| unit test that touches the database asks for it here.
*/
uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(VariantService::class);
    // Signed in because every write here leaves an audit and inventory trail.
    $this->user = User::factory()->manager()->create();
    $this->actingAs($this->user);
    $this->category = Category::factory()->create();
});

/** A style: the grouping row that variants hang off. */
function aStyle(array $overrides = []): Product
{
    return Product::factory()->create(array_merge([
        'name' => 'T-Shirt',
        'sku' => 'TSH',
        'barcode' => null,
        'category_id' => test()->category->id,
        'cost_price' => 10,
        'sale_price' => 25,
        'stock_quantity' => 0,
        'alert_quantity' => 4,
        'reorder_quantity' => 12,
    ], $overrides));
}

test('a variant inherits the style category, supplier and unit', function () {
    $supplier = Supplier::factory()->create();
    $style = aStyle(['supplier_id' => $supplier->id, 'unit' => 'piece']);

    $variant = $this->service->addVariant($style, ['variant_label' => 'Red / XL', 'stock_quantity' => 3]);

    expect($variant->parent_id)->toBe($style->id)
        ->and($variant->variant_label)->toBe('Red / XL')
        // The name carries the style's, so the till and a receipt read sensibly.
        ->and($variant->name)->toBe('T-Shirt - Red / XL')
        ->and($variant->category_id)->toBe($style->category_id)
        ->and($variant->supplier_id)->toBe($supplier->id)
        ->and($variant->unit)->toBe('piece')
        // Its own money and its own stock, defaulting to the style's prices.
        ->and((float) $variant->cost_price)->toBe(10.0)
        ->and((float) $variant->sale_price)->toBe(25.0)
        ->and($variant->stock_quantity)->toBe(3)
        ->and($variant->alert_quantity)->toBe(4)
        ->and($variant->reorder_quantity)->toBe(12)
        ->and($variant->sku)->toBe('TSH-RED-XL')
        ->and($variant->is_active)->toBeTrue()
        ->and($variant->tracksStock())->toBeTrue();
});

test('a variant may override what it would inherit', function () {
    $supplier = Supplier::factory()->create();
    $other = Category::factory()->create();
    $style = aStyle(['supplier_id' => $supplier->id]);
    $otherSupplier = Supplier::factory()->create();

    $variant = $this->service->addVariant($style, [
        'variant_label' => 'Blue / S',
        'category_id' => $other->id,
        'supplier_id' => $otherSupplier->id,
        'unit' => 'pack',
        'sku' => 'MY-OWN-SKU',
        'barcode' => '5901234123457',
        'cost_price' => 11.5,
        'sale_price' => 30,
    ]);

    expect($variant->category_id)->toBe($other->id)
        ->and($variant->supplier_id)->toBe($otherSupplier->id)
        ->and($variant->unit)->toBe('pack')
        ->and($variant->sku)->toBe('MY-OWN-SKU')
        ->and($variant->barcode)->toBe('5901234123457')
        ->and((float) $variant->cost_price)->toBe(11.5)
        ->and((float) $variant->sale_price)->toBe(30.0);
});

test('a variant cannot have variants of its own', function () {
    $style = aStyle();
    $variant = $this->service->addVariant($style, ['variant_label' => 'Red / XL']);

    expect(fn() => $this->service->addVariant($variant, ['variant_label' => 'Red / XL / Cotton']))
        ->toThrow(DomainException::class, 'one level deep');

    expect(fn() => $this->service->generate($variant, 'Cotton, Linen'))
        ->toThrow(DomainException::class, 'one level deep');

    expect(Product::where('parent_id', $variant->id)->count())->toBe(0);
});

test('the style stock is moved onto its variants, and the move is logged', function () {
    $style = aStyle(['stock_quantity' => 20]);

    $this->service->addVariant($style, ['variant_label' => 'Red / XL', 'stock_quantity' => 12]);

    $style->refresh();

    expect($style->stock_quantity)->toBe(0)
        // Tracking stays ON, at zero. Switching it off would tell the till it may
        // sell a style without counting anything (see markAsParent).
        ->and($style->track_stock)->toBeTrue()
        ->and($style->tracksStock())->toBeTrue()
        // It still never runs low, because it owns no stock to run low on.
        ->and($style->isLowStock())->toBeFalse()
        ->and((float) $style->stock_value)->toBe(0.0);

    $moved = InventoryLog::where('product_id', $style->id)->where('type', 'ADJUST')->first();

    expect($moved)->not->toBeNull()
        ->and($moved->quantity)->toBe(-20)
        ->and($moved->reason)->toBe('Stock moved onto variants');

    // Doing it again must not log a second move.
    $this->service->addVariant($style->fresh(), ['variant_label' => 'Blue / S']);
    expect(InventoryLog::where('product_id', $style->id)->where('type', 'ADJUST')->count())->toBe(1);
});

test('opening stock on a new variant leaves an inventory trail', function () {
    $style = aStyle();

    $variant = $this->service->addVariant($style, ['variant_label' => 'Red / XL', 'stock_quantity' => 7]);

    $log = InventoryLog::where('product_id', $variant->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->type)->toBe('IN')
        ->and($log->quantity)->toBe(7)
        ->and($log->reason)->toBe('Opening stock')
        ->and($log->user_id)->toBe($this->user->id);

    // Nothing counted in means nothing to log.
    $empty = $this->service->addVariant($style->fresh(), ['variant_label' => 'Blue / S']);
    expect(InventoryLog::where('product_id', $empty->id)->count())->toBe(0);
});

test('the generator creates every combination of two axes', function () {
    $style = aStyle();

    $result = $this->service->generate($style, 'S, M, L', 'Red, Blue', ['stock_quantity' => 2]);

    expect($result['created'])->toHaveCount(6)
        ->and($result['skipped'])->toBeEmpty()
        ->and($style->variants()->pluck('variant_label')->sort()->values()->all())->toBe([
            'L / Blue', 'L / Red', 'M / Blue', 'M / Red', 'S / Blue', 'S / Red',
        ]);

    // Each row is a real, sellable product with its own stock.
    expect($style->variants()->sum('stock_quantity'))->toBe(12)
        ->and($style->fresh()->totalStock())->toBe(12)
        ->and(InventoryLog::where('type', 'IN')->where('quantity', 2)->count())->toBe(6);
});

test('one axis on its own is a list of variants, not nothing', function () {
    $style = aStyle();

    $result = $this->service->generate($style, 'S, M, L');

    expect($result['created'])->toHaveCount(3)
        ->and($style->variants()->pluck('variant_label')->sort()->values()->all())->toBe(['L', 'M', 'S']);
});

test('the generator skips combinations that already exist and leaves them alone', function () {
    $style = aStyle();

    // Already selling the small red one, with its own price and stock.
    $existing = $this->service->addVariant($style, [
        'variant_label' => 's / red',          // typed in a hurry: case must not matter
        'sale_price' => 99,
        'stock_quantity' => 40,
    ]);

    $result = $this->service->generate($style->fresh(), 'S, M', 'Red, Blue', ['sale_price' => 25, 'stock_quantity' => 1]);

    expect($result['created'])->toHaveCount(3)
        ->and($result['skipped'])->toBe(['S / Red'])
        ->and($style->variants()->count())->toBe(4);

    // The row that was already selling keeps its price and its stock.
    expect((float) $existing->fresh()->sale_price)->toBe(99.0)
        ->and($existing->fresh()->stock_quantity)->toBe(40);

    // Running it again now has nothing left to do.
    expect($this->service->generate($style->fresh(), 'S, M', 'Red, Blue')['created'])->toHaveCount(0);
});

test('generated SKUs come from the style SKU and never clash', function () {
    $style = aStyle(['sku' => 'TSH']);

    // Somebody has already used the SKU the generator would want for "S".
    Product::factory()->create(['sku' => 'TSH-S', 'category_id' => $this->category->id]);

    $this->service->generate($style, 'S, M');

    $skus = $style->variants()->pluck('sku', 'variant_label')->all();

    expect($skus['M'])->toBe('TSH-M')
        ->and($skus['S'])->toBe('TSH-S-2')
        ->and(array_unique(array_values($skus)))->toHaveCount(2);

    // An archived product keeps its SKU, and the column is unique across them.
    $gone = Product::factory()->create(['sku' => 'TSH-XL', 'category_id' => $this->category->id]);
    $gone->delete();

    $this->service->generate($style->fresh(), 'XL');

    expect($style->variants()->where('variant_label', 'XL')->first()->sku)->toBe('TSH-XL-2');
});

test('the generator refuses a blank axis and an unreasonable number of combinations', function () {
    $style = aStyle();

    expect(fn() => $this->service->generate($style, '  ,  '))
        ->toThrow(DomainException::class, 'at least one value');

    $twenty = implode(',', range(1, 20));
    $seven = implode(',', range(1, 7));

    expect(fn() => $this->service->generate($style, $twenty, $seven))
        ->toThrow(DomainException::class, 'is the most in one go');

    expect($style->variants()->count())->toBe(0);
});

test('a label is required, and the same label cannot be used twice on one style', function () {
    $style = aStyle();

    expect(fn() => $this->service->addVariant($style, ['variant_label' => '   ']))
        ->toThrow(DomainException::class, 'needs a label');

    $this->service->addVariant($style, ['variant_label' => 'Red / XL']);

    expect(fn() => $this->service->addVariant($style->fresh(), ['variant_label' => 'red  /  xl']))
        ->toThrow(DomainException::class, 'already has');

    expect($style->variants()->count())->toBe(1);
});

test('a style with variants cannot be deleted', function () {
    $style = aStyle();
    $variant = $this->service->addVariant($style, ['variant_label' => 'Red / XL']);

    expect(fn() => $this->service->assertDeletable($style->fresh()))
        ->toThrow(DomainException::class, 'cannot be deleted while it does');

    // A variant itself is just a product and goes as one.
    $this->service->assertDeletable($variant);
    $variant->delete();

    // With nothing under it, the style is deletable (and sellable) again.
    $this->service->assertDeletable($style->fresh());
    expect(Product::sellable()->pluck('id'))->toContain($style->id);
});

test('renaming a style renames the variants that still carry its name', function () {
    $style = aStyle(['name' => 'T-Shirt']);
    $this->service->generate($style, 'S, M');

    $renamedByHand = $style->variants()->where('variant_label', 'M')->first();
    $renamedByHand->update(['name' => 'Special edition tee']);

    $style->update(['name' => 'Cotton T-Shirt']);
    $this->service->syncVariantNames($style, 'T-Shirt');

    expect($style->variants()->where('variant_label', 'S')->first()->name)->toBe('Cotton T-Shirt - S')
        // One that was given its own name is left alone.
        ->and($renamedByHand->fresh()->name)->toBe('Special edition tee');
});

test('axis values are split on commas and new lines, with blanks and repeats dropped', function () {
    expect($this->service->splitValues(" S , M ,, M \n L "))->toBe(['S', 'M', 'L'])
        ->and($this->service->splitValues(null))->toBe([])
        ->and($this->service->combinations(['S'], ['Red', 'Blue']))->toBe(['S / Red', 'S / Blue'])
        ->and($this->service->labelFor(['Red', '', 'XL']))->toBe('Red / XL');
});
