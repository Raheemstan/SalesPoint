<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->manager()->create();
    $this->actingAs($this->user);
    $this->category = Category::factory()->create();
});

function productData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sugar',
        'sku' => 'SUG-1',
        'barcode' => null,
        'category_id' => test()->category->id,
        'cost_price' => 10,
        'sale_price' => 15,
        'stock_quantity' => 20,
    ], $overrides);
}

test('a product can be created without a SKU (column is NOT NULL, so one is generated)', function () {
    $this->post(route('products.store'), productData(['sku' => null]))->assertSessionHas('success');

    expect(Product::first()->sku)->toStartWith('SKU-');
});

test('a category is required (column is NOT NULL; it was "nullable" in validation)', function () {
    $this->post(route('products.store'), productData(['category_id' => null]))->assertSessionHasErrors('category_id');

    expect(Product::count())->toBe(0);
});

test('a blank alert quantity falls back to the DB default instead of crashing', function () {
    $this->post(route('products.store'), productData(['alert_quantity' => '']))->assertSessionHas('success');

    expect(Product::first()->alert_quantity)->toBe(5);
});

test('opening stock and manual stock edits leave an inventory trail', function () {
    $this->post(route('products.store'), productData(['stock_quantity' => 20]));
    $product = Product::first();
    expect(InventoryLog::where('type', 'IN')->where('quantity', 20)->exists())->toBeTrue();

    $this->put(route('products.update', $product->id), productData(['stock_quantity' => 14]))->assertSessionHas('success');

    expect($product->fresh()->stock_quantity)->toBe(14);
    $log = InventoryLog::where('type', 'ADJUST')->first();
    expect($log->quantity)->toBe(-6);

    // editing other fields without touching stock must not log anything
    $this->put(route('products.update', $product->id), productData(['stock_quantity' => 14, 'sale_price' => 99]));
    expect(InventoryLog::where('type', 'ADJUST')->count())->toBe(1);
});

test('clearing the SKU on edit keeps the existing one', function () {
    $this->post(route('products.store'), productData());
    $product = Product::first();

    $this->put(route('products.update', $product->id), productData(['sku' => null]))->assertSessionHasNoErrors();

    expect($product->fresh()->sku)->toBe('SUG-1');
});

test('deleting a product that has sales archives it instead of blowing up on the foreign key', function () {
    $product = Product::factory()->create(['category_id' => $this->category->id]);
    $sale = Sale::create([
        'invoice_number' => 'INV-X', 'user_id' => $this->user->id, 'total_amount' => 1, 'grand_total' => 1,
        'payment_method' => 'cash', 'paid_amount' => 1, 'sale_date' => now(),
    ]);
    $sale->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => 1, 'total' => 1]);

    $this->delete(route('products.destroy', $product->id))->assertSessionHas('success');

    expect(Product::find($product->id))->toBeNull()
        ->and(Product::withTrashed()->find($product->id))->not->toBeNull()
        ->and($sale->items()->first()->product->id)->toBe($product->id);
});

test('a deleted product disappears from POS search and cannot be sold', function () {
    $product = Product::factory()->create(['category_id' => $this->category->id, 'name' => 'Ghost', 'stock_quantity' => 5]);
    $product->delete();

    expect($this->getJson(route('pos.search', ['q' => 'Ghost']))->json())->toHaveCount(0);
    $this->postJson(route('pos.add', $product->id))->assertNotFound();
});

test('the product search does not leak OR conditions', function () {
    Product::factory()->create(['category_id' => $this->category->id, 'name' => 'Salt']);
    Product::factory()->create(['category_id' => $this->category->id, 'name' => 'Pepper']);

    $this->get(route('products.index', ['search' => 'Salt']))->assertSee('Salt')->assertDontSee('Pepper');
});

test('a category that still has products cannot be deleted (it used to cascade-delete them)', function () {
    $product = Product::factory()->create(['category_id' => $this->category->id]);

    $this->delete(route('categories.destroy', $this->category->id))->assertSessionHas('error');

    expect(Category::find($this->category->id))->not->toBeNull()
        ->and(Product::find($product->id))->not->toBeNull();

    $empty = Category::factory()->create();
    $this->delete(route('categories.destroy', $empty->id))->assertSessionHas('success');
    expect(Category::find($empty->id))->toBeNull();
});

test('updating an expense redirects with a success message (regression: it answered with an empty page)', function () {
    $expense = Expense::create(['name' => 'Rent', 'amount' => 100, 'expense_date' => '2026-10-01', 'user_id' => $this->user->id]);

    $this->put(route('expenses.update', $expense->id), [
        'name' => 'Rent (revised)', 'amount' => 150, 'expense_date' => '2026-10-01',
    ])->assertRedirect(route('expenses.index'))->assertSessionHas('success');

    expect($expense->fresh()->name)->toBe('Rent (revised)');
});

test('expenses can be created and deleted, and filters keep their query string across pages', function () {
    $this->post(route('expenses.store'), ['name' => 'Fuel', 'amount' => 40, 'expense_date' => '2026-10-02'])
        ->assertRedirect(route('expenses.index'))->assertSessionHas('success');

    $expense = Expense::first();
    $this->delete(route('expenses.destroy', $expense->id))->assertSessionHas('success');
    expect(Expense::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The catalogue fields the shared product columns added
|--------------------------------------------------------------------------
*/

test('the new catalogue fields are saved', function () {
    $supplier = Supplier::factory()->create();

    $this->post(route('products.store'), productData([
        'supplier_id' => $supplier->id,
        'unit' => 'bottle',
        'pack_size' => 12,
        'batch_no' => 'B-204',
        'expiry_date' => '2027-03-01',
        'reorder_quantity' => 24,
        'alert_quantity' => 3,
    ]))->assertSessionHas('success');

    $product = Product::first();

    expect($product->supplier_id)->toBe($supplier->id)
        ->and($product->unit)->toBe('bottle')
        ->and((float) $product->pack_size)->toBe(12.0)
        ->and($product->batch_no)->toBe('B-204')
        ->and($product->expiry_date->toDateString())->toBe('2027-03-01')
        ->and($product->reorder_quantity)->toBe(24)
        ->and($product->is_active)->toBeTrue()
        ->and($product->track_stock)->toBeTrue()
        ->and($product->is_service)->toBeFalse();

    // A form that does not carry the flags must not quietly switch them off.
    $this->put(route('products.update', $product), productData(['sale_price' => 20]))->assertSessionHas('success');
    expect($product->fresh()->is_active)->toBeTrue();

    // An unticked box posts its hidden 0, which does switch it off.
    $this->put(route('products.update', $product), productData(['is_active' => '0']))->assertSessionHas('success');
    expect($product->fresh()->is_active)->toBeFalse();

    $this->post(route('products.store'), productData(['sku' => 'BAD-1', 'supplier_id' => 9999]))
        ->assertSessionHasErrors('supplier_id');
});

test('a service is saved with no stock at all, rather than a zero that would look low', function () {
    $this->post(route('products.store'), productData([
        'name' => 'Haircut',
        'sku' => 'CUT-1',
        'is_service' => '1',
        'stock_quantity' => null,
    ]))->assertSessionHas('success');

    $service = Product::where('sku', 'CUT-1')->first();

    expect($service->is_service)->toBeTrue()
        ->and($service->track_stock)->toBeFalse()
        ->and($service->tracksStock())->toBeFalse()
        ->and($service->isLowStock())->toBeFalse()
        ->and((float) $service->stock_value)->toBe(0.0)
        // Nothing was counted in, so there is nothing to log.
        ->and(InventoryLog::count())->toBe(0);

    // Stock fields posted anyway are ignored, not stored.
    $this->post(route('products.store'), productData([
        'name' => 'Delivery fee',
        'sku' => 'DEL-1',
        'is_service' => '1',
        'stock_quantity' => 50,
        'batch_no' => 'B-1',
        'expiry_date' => '2027-01-01',
        'reorder_quantity' => 9,
    ]))->assertSessionHas('success');

    $fee = Product::where('sku', 'DEL-1')->first();

    expect($fee->stock_quantity)->toBe(0)
        ->and($fee->batch_no)->toBeNull()
        ->and($fee->expiry_date)->toBeNull()
        ->and($fee->reorder_quantity)->toBe(0)
        ->and(InventoryLog::count())->toBe(0);
});

test('turning a stocked product into a service leaves its stock alone but stops counting it', function () {
    $this->post(route('products.store'), productData(['stock_quantity' => 20]));
    $product = Product::first();

    $this->put(route('products.update', $product), productData(['is_service' => '1', 'stock_quantity' => null]))
        ->assertSessionHas('success');

    expect($product->fresh()->is_service)->toBeTrue()
        ->and($product->fresh()->tracksStock())->toBeFalse()
        // Nothing physically moved, so no adjustment is logged and the figure stands.
        ->and($product->fresh()->stock_quantity)->toBe(20)
        ->and(InventoryLog::where('type', 'ADJUST')->count())->toBe(0);
});

test('a product picture is stored on the public disk, replaced cleanly and can be removed', function () {
    Storage::fake('public');

    $this->post(route('products.store'), productData(['image' => UploadedFile::fake()->image('sugar.jpg')]))
        ->assertSessionHas('success');

    $product = Product::first();
    $first = $product->image_path;

    expect($first)->not->toBeNull()->and($first)->toStartWith('products/');
    Storage::disk('public')->assertExists($first);

    // Replacing it saves the new file and clears up the old one.
    $this->put(route('products.update', $product), productData(['image' => UploadedFile::fake()->image('sugar-2.png')]))
        ->assertSessionHas('success');

    $second = $product->fresh()->image_path;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);

    $this->delete(route('products.image.destroy', $product))->assertSessionHas('success');

    expect($product->fresh()->image_path)->toBeNull();
    Storage::disk('public')->assertMissing($second);

    // Nothing to remove the second time round.
    $this->delete(route('products.image.destroy', $product))->assertSessionHas('error');

    // The list shows the thumbnail while there is one.
    $this->put(route('products.update', $product), productData(['image' => UploadedFile::fake()->image('again.jpg')]));
    $this->get(route('products.index'))->assertOk()->assertSee($product->fresh()->image_path);
});

test('only a real image of a sane size is accepted', function () {
    Storage::fake('public');

    $this->post(route('products.store'), productData(['image' => UploadedFile::fake()->create('invoice.pdf', 40, 'application/pdf')]))
        ->assertSessionHasErrors('image');

    $this->post(route('products.store'), productData(['image' => UploadedFile::fake()->image('huge.jpg')->size(4096)]))
        ->assertSessionHasErrors('image');

    expect(Product::count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('the product form only offers the fields the trade needs', function () {
    // Retail (the default): units on, expiry and services off.
    $this->get(route('products.index'))->assertOk()
        ->assertSee('Units per pack bought')
        ->assertDontSee('Expiry date')
        ->assertDontSee('This is a service');

    Setting::set('business_type', 'pharmacy');
    $this->get(route('products.index'))->assertOk()->assertSee('Expiry date');

    Setting::set('business_type', 'salon');
    $this->get(route('products.index'))->assertOk()->assertSee('This is a service');

    // A per-install override beats the preset either way.
    Setting::set('feature_expiry', '1');
    $this->get(route('products.index'))->assertOk()->assertSee('Expiry date');
});

test('an expiring product is flagged in the list', function () {
    Setting::set('business_type', 'pharmacy');

    Product::factory()->create([
        'category_id' => $this->category->id, 'name' => 'Old Syrup',
        'expiry_date' => now()->subDay(), 'stock_quantity' => 4,
    ]);
    Product::factory()->create([
        'category_id' => $this->category->id, 'name' => 'Fresh Syrup',
        'expiry_date' => now()->addYears(2), 'stock_quantity' => 4,
    ]);

    $this->get(route('products.index'))->assertOk()->assertSee('Expired')->assertDontSee('Expires');
});

test('a cashier cannot change a product or strip its picture', function () {
    Storage::fake('public');
    $this->post(route('products.store'), productData(['image' => UploadedFile::fake()->image('x.jpg')]));
    $product = Product::first();

    $this->actingAs(User::factory()->cashier()->create());

    $this->put(route('products.update', $product), productData(['name' => 'Hacked']))->assertForbidden();
    $this->delete(route('products.destroy', $product))->assertForbidden();
    $this->delete(route('products.image.destroy', $product))->assertForbidden();

    expect($product->fresh()->name)->toBe('Sugar')
        ->and($product->fresh()->image_path)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Serial / IMEI tracking (the per-unit flag; the capture itself is in SerialTest)
|--------------------------------------------------------------------------
*/

test('the per-unit serial flag is saved, and only offered to a trade that records serials', function () {
    // Retail: nothing is individually identified, so the field is not there.
    $this->get(route('products.index'))->assertOk()->assertDontSee('Record a serial / IMEI');

    Setting::set('business_type', 'electronics');

    $this->get(route('products.index'))->assertOk()->assertSee('Record a serial / IMEI');

    $this->post(route('products.store'), productData(['serial_required' => '1']))->assertSessionHas('success');

    $product = Product::first();

    expect($product->serial_required)->toBeTrue()
        ->and($product->requiresSerials())->toBeTrue();

    // An unticked box posts its hidden 0.
    $this->put(route('products.update', $product), productData(['serial_required' => '0']))->assertSessionHas('success');
    expect($product->fresh()->serial_required)->toBeFalse();

    // A trade that does not record serials never asks for one, whatever the flag says.
    $product->update(['serial_required' => true]);
    Setting::set('business_type', 'retail');
    expect($product->fresh()->requiresSerials())->toBeFalse();
});

