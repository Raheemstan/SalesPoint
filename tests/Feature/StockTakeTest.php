<?php

use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockTake;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->counter = User::factory()->manager()->create(['name' => 'Counting Manager']);
    $this->reviewer = User::factory()->manager()->create(['name' => 'Reviewing Manager']);
    $this->admin = User::factory()->admin()->create();
});

/** A sheet counted by $this->counter, with one line per product given. */
function sheetWithCounts(array $counts): StockTake
{
    test()->actingAs(test()->counter)->post(route('stock-takes.store'), [])->assertRedirect();

    $sheet = StockTake::latest('id')->first();

    $payload = [];
    foreach ($counts as $productId => $quantity) {
        $payload[$sheet->items()->where('product_id', $productId)->value('id')] = $quantity;
    }

    test()->actingAs(test()->counter)
        ->put(route('stock-takes.update', $sheet), ['counts' => $payload, 'action' => 'submit'])
        ->assertRedirect(route('stock-takes.show', $sheet));

    return $sheet->fresh();
}

test('a manager can create a count sheet, and every page of it renders', function () {
    $product = Product::factory()->create(['name' => 'Sugar', 'stock_quantity' => 12]);

    $this->actingAs($this->counter);

    $this->get(route('stock-takes.index'))->assertOk();
    $this->get(route('stock-takes.create'))->assertOk()->assertSee('countable');

    $this->post(route('stock-takes.store'), ['note' => 'Month end'])->assertSessionHas('success');

    $sheet = StockTake::first();
    expect($sheet->items)->toHaveCount(1)->and($sheet->user_id)->toBe($this->counter->id);

    $this->get(route('stock-takes.show', $sheet))->assertOk()->assertSee('Sugar')->assertSee($sheet->reference);
    $this->get(route('stock-takes.index'))->assertOk()->assertSee($sheet->reference);
});

test('counts are saved with their variance and nothing moves yet', function () {
    $product = Product::factory()->create(['stock_quantity' => 10, 'cost_price' => 2]);

    $sheet = sheetWithCounts([$product->id => 7]);

    expect($sheet->status)->toBe(StockTake::STATUS_SUBMITTED)
        ->and($sheet->items()->first()->variance)->toBe(-3)
        ->and($product->fresh()->stock_quantity)->toBe(10)
        ->and(InventoryLog::count())->toBe(0);
});

test('approving a sheet moves the stock and writes both an inventory log and an adjustment', function () {
    $product = Product::factory()->create(['stock_quantity' => 10, 'cost_price' => 2.5]);

    $sheet = sheetWithCounts([$product->id => 7]);

    $this->actingAs($this->reviewer)
        ->post(route('stock-takes.approve', $sheet))
        ->assertRedirect(route('stock-takes.show', $sheet))
        ->assertSessionHas('success');

    expect($product->fresh()->stock_quantity)->toBe(7)
        ->and($sheet->fresh()->status)->toBe(StockTake::STATUS_APPROVED)
        ->and($sheet->fresh()->approved_by)->toBe($this->reviewer->id);

    $adjustment = StockAdjustment::first();
    expect($adjustment->type)->toBe(StockAdjustment::TYPE_DECREASE)
        ->and($adjustment->quantity)->toBe(3)
        ->and($adjustment->reason)->toBe('count')
        ->and($adjustment->stock_take_id)->toBe($sheet->id);

    expect(InventoryLog::where('type', 'OUT')->where('quantity', 3)->exists())->toBeTrue();

    // The approval itself is audited.
    expect(\App\Models\AuditLog::where('table_name', 'stock_takes')
        ->where('action', 'like', 'Approved stock-take%')->exists())->toBeTrue();

    // The settled sheet reads back as history, with no count boxes on it.
    $this->get(route('stock-takes.show', $sheet))->assertOk()
        ->assertSee('Approved')
        ->assertSee('Corrections made by this count')
        ->assertDontSee('name="counts[', false);
});

test('approving twice is refused and the correction is applied only once', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);
    $sheet = sheetWithCounts([$product->id => 6]);

    $this->actingAs($this->reviewer)->post(route('stock-takes.approve', $sheet))->assertSessionHas('success');

    // The policy closes the door first: an approved sheet is no longer editable.
    $this->actingAs($this->reviewer)->post(route('stock-takes.approve', $sheet))->assertForbidden();
    $this->actingAs($this->admin)->post(route('stock-takes.approve', $sheet))->assertForbidden();

    expect($product->fresh()->stock_quantity)->toBe(6)
        ->and(StockAdjustment::count())->toBe(1)
        ->and(InventoryLog::count())->toBe(1);
});

test('the person who counted cannot approve their own sheet, but another manager or an admin can', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);
    $sheet = sheetWithCounts([$product->id => 8]);

    // four eyes: the counter is refused even though they are a manager
    $this->actingAs($this->counter)->post(route('stock-takes.approve', $sheet))->assertForbidden();
    expect($product->fresh()->stock_quantity)->toBe(10);

    // the sheet also tells them why, rather than just hiding the button
    $this->actingAs($this->counter)->get(route('stock-takes.show', $sheet))
        ->assertSee('approved by somebody other than the person who counted');

    $this->actingAs($this->reviewer)->post(route('stock-takes.approve', $sheet))->assertSessionHas('success');
    expect($product->fresh()->stock_quantity)->toBe(8);

    // and an admin may approve somebody else's sheet
    $second = sheetWithCounts([$product->id => 5]);
    $this->actingAs($this->admin)->post(route('stock-takes.approve', $second))->assertSessionHas('success');
    expect($product->fresh()->stock_quantity)->toBe(5);
});

test('a service never appears on a count sheet, in the reorder list or in the adjustment form', function () {
    $service = Product::factory()->create([
        'name' => 'Haircut', 'is_service' => true, 'stock_quantity' => 0, 'alert_quantity' => 5,
    ]);
    $good = Product::factory()->create(['name' => 'Shampoo', 'stock_quantity' => 1, 'alert_quantity' => 5]);

    $this->actingAs($this->counter);

    $this->post(route('stock-takes.store'), []);
    $sheet = StockTake::first();
    expect($sheet->items()->where('product_id', $service->id)->exists())->toBeFalse()
        ->and($sheet->items()->where('product_id', $good->id)->exists())->toBeTrue();

    $this->get(route('stock-takes.show', $sheet))->assertSee('Shampoo')->assertDontSee('Haircut');
    $this->get(route('reorder.index'))->assertOk()->assertSee('Shampoo')->assertDontSee('Haircut');
    $this->get(route('adjustments.index'))->assertOk()->assertDontSee('Haircut');
});

test('an empty sheet is discarded and a counted one is cancelled rather than deleted', function () {
    $product = Product::factory()->create(['stock_quantity' => 5]);

    $this->actingAs($this->counter);

    $this->post(route('stock-takes.store'), []);
    $empty = StockTake::latest('id')->first();
    $this->delete(route('stock-takes.destroy', $empty))->assertSessionHas('success');
    expect(StockTake::find($empty->id))->toBeNull();

    $counted = sheetWithCounts([$product->id => 4]);
    $this->actingAs($this->counter)->delete(route('stock-takes.destroy', $counted))->assertSessionHas('success');
    expect($counted->fresh()->status)->toBe(StockTake::STATUS_CANCELLED)
        ->and($product->fresh()->stock_quantity)->toBe(5);

    // a cancelled sheet can no longer be approved
    $this->actingAs($this->reviewer)->post(route('stock-takes.approve', $counted))->assertForbidden();
});

test('an approved sheet cannot be re-counted or deleted', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);
    $sheet = sheetWithCounts([$product->id => 9]);
    $this->actingAs($this->reviewer)->post(route('stock-takes.approve', $sheet));

    $itemId = $sheet->items()->first()->id;

    $this->actingAs($this->counter)->put(route('stock-takes.update', $sheet), ['counts' => [$itemId => 1]])->assertForbidden();
    $this->actingAs($this->admin)->delete(route('stock-takes.destroy', $sheet))->assertForbidden();

    expect($product->fresh()->stock_quantity)->toBe(9);
});

test('counts posted for another sheet are ignored rather than trusted', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    $this->actingAs($this->counter);
    $this->post(route('stock-takes.store'), []);
    $mine = StockTake::latest('id')->first();
    $this->post(route('stock-takes.store'), []);
    $other = StockTake::latest('id')->first();

    $foreignItem = $other->items()->first()->id;

    $this->put(route('stock-takes.update', $mine), ['counts' => [$foreignItem => 3]])->assertSessionHas('success');

    expect($other->items()->first()->counted_quantity)->toBeNull();
});

test('a cashier is refused everywhere in stock control', function () {
    $product = Product::factory()->create(['stock_quantity' => 5]);
    $sheet = sheetWithCounts([$product->id => 4]);

    $this->actingAs(User::factory()->cashier()->create());

    foreach ([
        'stock-takes.index', 'stock-takes.create', 'adjustments.index',
        'reorder.index', 'products.import.form', 'products.import.template', 'products.export',
    ] as $route) {
        $this->get(route($route))->assertForbidden();
    }

    $this->get(route('stock-takes.show', $sheet))->assertForbidden();
    $this->post(route('stock-takes.store'), [])->assertForbidden();
    $this->put(route('stock-takes.update', $sheet), ['counts' => []])->assertForbidden();
    $this->post(route('stock-takes.approve', $sheet))->assertForbidden();
    $this->delete(route('stock-takes.destroy', $sheet))->assertForbidden();
    $this->post(route('adjustments.store'), [
        'product_id' => $product->id, 'type' => 'decrease', 'quantity' => 1, 'reason' => 'theft',
    ])->assertForbidden();

    expect($product->fresh()->stock_quantity)->toBe(5)->and(StockAdjustment::count())->toBe(0);
});

test('an adjustment moves the stock once, with a reason, a log and an audit entry', function () {
    $product = Product::factory()->create(['stock_quantity' => 20, 'cost_price' => 4]);

    $this->actingAs($this->counter)->post(route('adjustments.store'), [
        'product_id' => $product->id,
        'type' => 'decrease',
        'quantity' => 3,
        'reason' => 'damage',
        'note' => 'dropped a crate',
    ])->assertRedirect(route('adjustments.index'))->assertSessionHas('success');

    expect($product->fresh()->stock_quantity)->toBe(17);

    $adjustment = StockAdjustment::first();
    expect($adjustment->reason)->toBe('damage')
        ->and($adjustment->quantity)->toBe(3)
        ->and((float) $adjustment->cost_price)->toBe(4.0)
        ->and($adjustment->stock_take_id)->toBeNull()
        ->and($adjustment->user_id)->toBe($this->counter->id)
        ->and($adjustment->value)->toBe(-12.0);

    expect(InventoryLog::where('type', 'OUT')->where('quantity', 3)->exists())->toBeTrue()
        ->and(\App\Models\AuditLog::where('table_name', 'stock_adjustments')->exists())->toBeTrue();

    $this->get(route('adjustments.index'))->assertOk()->assertSee('dropped a crate');
});

test('an adjustment cannot push the stock below zero', function () {
    $product = Product::factory()->create(['stock_quantity' => 2]);

    $this->actingAs($this->counter)->post(route('adjustments.store'), [
        'product_id' => $product->id, 'type' => 'decrease', 'quantity' => 3, 'reason' => 'theft',
    ])->assertSessionHas('error');

    expect($product->fresh()->stock_quantity)->toBe(2)
        ->and(StockAdjustment::count())->toBe(0)
        ->and(InventoryLog::count())->toBe(0);

    // the boundary itself is allowed
    $this->post(route('adjustments.store'), [
        'product_id' => $product->id, 'type' => 'decrease', 'quantity' => 2, 'reason' => 'theft',
    ])->assertSessionHas('success');

    expect($product->fresh()->stock_quantity)->toBe(0);
});

test('an item that does not track stock cannot be adjusted, and the inputs are whitelisted', function () {
    $service = Product::factory()->create(['is_service' => true, 'stock_quantity' => 0]);

    $this->actingAs($this->counter);

    $this->post(route('adjustments.store'), [
        'product_id' => $service->id, 'type' => 'increase', 'quantity' => 5, 'reason' => 'correction',
    ])->assertSessionHas('error');

    $product = Product::factory()->create(['stock_quantity' => 5]);

    $this->post(route('adjustments.store'), [
        'product_id' => $product->id, 'type' => 'sideways', 'quantity' => 1, 'reason' => 'damage',
    ])->assertSessionHasErrors('type');

    $this->post(route('adjustments.store'), [
        'product_id' => $product->id, 'type' => 'decrease', 'quantity' => 1, 'reason' => 'because',
    ])->assertSessionHasErrors('reason');

    $this->post(route('adjustments.store'), [
        'product_id' => $product->id, 'type' => 'decrease', 'quantity' => 0, 'reason' => 'damage',
    ])->assertSessionHasErrors('quantity');

    expect(StockAdjustment::count())->toBe(0);
});

test('the reorder list groups by supplier, suggests a quantity and prices the order', function () {
    $acme = Supplier::create(['name' => 'Acme Drinks', 'phone' => '0800', 'address' => 'Lagos']);

    // At its reorder level, so it counts as low. reorder_quantity wins when set.
    $withQuantity = Product::factory()->create([
        'name' => 'Cola', 'supplier_id' => $acme->id, 'stock_quantity' => 5,
        'alert_quantity' => 5, 'reorder_quantity' => 24, 'cost_price' => 10,
    ]);

    // No reorder_quantity: suggest enough to reach twice the reorder level (20 - 2 = 18).
    $noQuantity = Product::factory()->create([
        'name' => 'Water', 'supplier_id' => null, 'stock_quantity' => 2,
        'alert_quantity' => 10, 'reorder_quantity' => 0, 'cost_price' => 1,
    ]);

    $healthy = Product::factory()->create(['name' => 'Juice', 'stock_quantity' => 99, 'alert_quantity' => 5]);

    $response = $this->actingAs($this->counter)->get(route('reorder.index'))->assertOk();

    $response->assertSee('Cola')->assertSee('Water')->assertDontSee('Juice')
        ->assertSee('Acme Drinks')->assertSee('No supplier set')
        // the fields PurchaseController@storeFromReorder will receive
        ->assertSee('name="product_ids[]"', false)
        ->assertSee('name="quantities[' . $withQuantity->id . ']"', false)
        ->assertSee('name="cost_prices[' . $withQuantity->id . ']"', false)
        ->assertSee('value="24"', false)
        ->assertSee('value="18"', false);

    // filtering to the unassigned group
    $this->get(route('reorder.index', ['supplier_id' => 0]))->assertSee('Water')->assertDontSee('Cola');
    $this->get(route('reorder.index', ['supplier_id' => $acme->id]))->assertSee('Cola')->assertDontSee('Water');
});

test('the reorder list uses the shop-wide threshold when an item has no level of its own', function () {
    \App\Models\Setting::create(['key' => 'low_stock_threshold', 'value' => '8']);
    cache()->forget('settings_cache');

    $low = Product::factory()->create(['name' => 'Rice', 'stock_quantity' => 8, 'alert_quantity' => 0]);
    $fine = Product::factory()->create(['name' => 'Beans', 'stock_quantity' => 9, 'alert_quantity' => 0]);

    $this->actingAs($this->counter)->get(route('reorder.index'))->assertSee('Rice')->assertDontSee('Beans');
});

test('a sheet can be limited to one category', function () {
    $drinks = Category::factory()->create(['name' => 'Drinks']);
    $inside = Product::factory()->create(['name' => 'Cola', 'category_id' => $drinks->id, 'stock_quantity' => 3]);
    $outside = Product::factory()->create(['name' => 'Bread', 'stock_quantity' => 3]);

    $this->actingAs($this->counter)
        ->post(route('stock-takes.store'), ['category_id' => $drinks->id])
        ->assertSessionHas('success');

    $sheet = StockTake::first();
    expect($sheet->items)->toHaveCount(1)->and($sheet->items->first()->product_id)->toBe($inside->id);
});

test('a sheet with nothing to count explains itself instead of being created', function () {
    Product::factory()->create(['is_service' => true]);

    $this->actingAs($this->counter)->post(route('stock-takes.store'), [])->assertSessionHas('error');

    expect(StockTake::count())->toBe(0);
});

/*
| The endpoint belongs to PurchaseController, not to us, but the shape of what
| our form posts is ours - so this proves the two halves actually meet.
*/
test('the reorder form posts a shape the purchase-order endpoint accepts', function () {
    $acme = Supplier::create(['name' => 'Acme', 'phone' => '0800', 'address' => 'Lagos']);
    $cola = Product::factory()->create(['supplier_id' => $acme->id, 'stock_quantity' => 1, 'alert_quantity' => 5, 'cost_price' => 10]);
    $water = Product::factory()->create(['supplier_id' => $acme->id, 'stock_quantity' => 0, 'alert_quantity' => 5, 'cost_price' => 2]);

    $this->actingAs($this->counter)->post(route('reorder.purchase-order'), [
        'supplier_id' => $acme->id,
        'product_ids' => [$cola->id],                 // only Cola was ticked
        'quantities' => [$cola->id => 24, $water->id => 10],
        'cost_prices' => [$cola->id => '10.00', $water->id => '2.00'],
    ])->assertSessionHasNoErrors();

    $purchase = \App\Models\Purchase::latest('id')->first();

    expect($purchase)->not->toBeNull()
        ->and($purchase->purchaseItems)->toHaveCount(1)
        ->and($purchase->purchaseItems->first()->product_id)->toBe($cola->id)
        ->and($purchase->purchaseItems->first()->quantity)->toBe(24);
})->skip(!method_exists(App\Http\Controllers\PurchaseController::class, 'storeFromReorder'),
    'PurchaseController@storeFromReorder is not written yet.');

test('the migration rolls back and reapplies cleanly', function () {
    $migration = require base_path('database/migrations/2026_10_14_000003_create_stock_control_tables.php');

    $migration->down();

    expect(Schema::hasTable('stock_adjustments'))->toBeFalse()
        ->and(Schema::hasTable('stock_take_items'))->toBeFalse()
        ->and(Schema::hasTable('stock_takes'))->toBeFalse()
        // Nothing the rest of the app depends on goes with them.
        ->and(Schema::hasTable('products'))->toBeTrue()
        ->and(Schema::hasTable('inventory_logs'))->toBeTrue();

    $migration->up();

    expect(Schema::hasTable('stock_takes'))->toBeTrue()
        ->and(Schema::hasColumn('stock_take_items', 'system_quantity'))->toBeTrue()
        ->and(Schema::hasColumn('stock_adjustments', 'stock_take_id'))->toBeTrue();
});
