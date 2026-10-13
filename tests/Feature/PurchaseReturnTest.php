<?php

use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\SupplierLedgerService;

beforeEach(function () {
    $this->user = User::factory()->manager()->create();
    $this->actingAs($this->user);
    $this->supplier = Supplier::factory()->create();
});

/** Record a delivery through the purchase form, so stock moves the normal way. */
function delivery(array $lines, array $extra = []): Purchase
{
    test()->post(route('purchases.store'), array_merge([
        'supplier_id' => test()->supplier->id,
        'invoice_number' => 'RTN-PO-' . uniqid(),
        'purchase_date' => '2026-10-01',
        'product_ids' => array_column($lines, 0),
        'quantities' => array_column($lines, 1),
        'cost_prices' => array_column($lines, 2),
    ], $extra))->assertSessionHas('success');

    return Purchase::latest('id')->first();
}

/** The form payload the return modal posts: items[<purchase_item_id>][quantity]. */
function returnPayload(Purchase $purchase, array $quantities, array $extra = []): array
{
    $items = $purchase->purchaseItems()->orderBy('id')->get();
    $payload = [];

    foreach (array_values($quantities) as $i => $quantity) {
        $payload[$items[$i]->id] = ['quantity' => $quantity];
    }

    return array_merge(['items' => $payload, 'reason' => 'Damaged on delivery'], $extra);
}

test('a partial return takes stock out, caps at what is left and a second return cannot exceed it', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $purchase = delivery([[$product->id, 10, 2.50]]);

    expect($product->fresh()->stock_quantity)->toBe(10);

    $this->post(route('purchases.returns.store', $purchase), returnPayload($purchase, [6]))
        ->assertRedirect(route('purchases.show', $purchase))
        ->assertSessionHas('success');

    expect($product->fresh()->stock_quantity)->toBe(4)
        ->and((float) PurchaseReturn::first()->total_amount)->toBe(15.0)
        ->and($purchase->purchaseItems()->first()->returned_quantity)->toBe(6)
        ->and(InventoryLog::where('type', 'OUT')->where('quantity', 6)->exists())->toBeTrue();

    // Five is one more than is left.
    $this->post(route('purchases.returns.store', $purchase), returnPayload($purchase, [5]))
        ->assertSessionHas('error');

    expect(PurchaseReturn::count())->toBe(1)->and($product->fresh()->stock_quantity)->toBe(4);

    // Four is exactly what is left.
    $this->post(route('purchases.returns.store', $purchase), returnPayload($purchase, [4]))
        ->assertSessionHas('success');

    expect($product->fresh()->stock_quantity)->toBe(0)
        ->and(PurchaseReturn::count())->toBe(2)
        // The two returns together credit back the whole line.
        ->and(round((float) PurchaseReturnItem::sum('amount'), 2))->toBe(25.0);
});

test('a return is credited at the cost on the purchase, not the cost the product has now', function () {
    $product = Product::factory()->create(['stock_quantity' => 0, 'cost_price' => 3]);
    $purchase = delivery([[$product->id, 4, 3.00]]);

    $product->update(['cost_price' => 50]);

    $this->post(route('purchases.returns.store', $purchase), returnPayload($purchase, [4]))
        ->assertSessionHas('success');

    expect((float) PurchaseReturn::first()->total_amount)->toBe(12.0)
        ->and((float) PurchaseReturnItem::first()->unit_cost)->toBe(3.0);
});

test('a return reduces what is owed the supplier', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $purchase = delivery([[$product->id, 10, 10]]);
    $ledger = app(SupplierLedgerService::class);

    expect($ledger->balance($this->supplier))->toBe(100.0);

    $this->post(route('purchases.returns.store', $purchase), returnPayload($purchase, [3]));

    expect($ledger->balance($this->supplier))->toBe(70.0)
        ->and((float) $purchase->fresh()->balance_due)->toBe(70.0);
});

test('goods cannot be returned against an order that never arrived', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $purchase = delivery([[$product->id, 5, 1]], ['status' => 'ordered']);

    $this->post(route('purchases.returns.store', $purchase), returnPayload($purchase, [1]))
        ->assertSessionHas('error');

    expect(PurchaseReturn::count())->toBe(0)->and($product->fresh()->stock_quantity)->toBe(0);

    // The show page offers no return button either.
    $this->get(route('purchases.show', $purchase))->assertOk()->assertDontSee('Return to supplier');
});

test('a return is refused once the goods have been sold on', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $purchase = delivery([[$product->id, 10, 5]]);
    $product->decrement('stock_quantity', 9);

    $this->post(route('purchases.returns.store', $purchase), returnPayload($purchase, [4]))
        ->assertSessionHas('error');

    expect(PurchaseReturn::count())->toBe(0)->and($product->fresh()->stock_quantity)->toBe(1);
});

test('a return needs a reason and at least one quantity', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $purchase = delivery([[$product->id, 5, 1]]);

    $this->post(route('purchases.returns.store', $purchase), returnPayload($purchase, [1], ['reason' => '']))
        ->assertSessionHasErrors('reason');

    $this->post(route('purchases.returns.store', $purchase), ['reason' => 'No items given'])
        ->assertSessionHasErrors('items');

    $this->post(route('purchases.returns.store', $purchase), returnPayload($purchase, [0]))
        ->assertSessionHas('error');

    expect(PurchaseReturn::count())->toBe(0)->and($product->fresh()->stock_quantity)->toBe(5);
});

test('a purchase with goods returned against it can no longer be edited', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $purchase = delivery([[$product->id, 10, 5]]);

    $this->post(route('purchases.returns.store', $purchase), returnPayload($purchase, [2]));

    $this->put(route('purchases.update', $purchase), [
        'supplier_id' => $this->supplier->id,
        'invoice_number' => $purchase->invoice_number,
        'purchase_date' => '2026-10-01',
        'product_ids' => [$product->id],
        'quantities' => [20],
        'cost_prices' => [5],
    ])->assertSessionHas('error');

    expect($purchase->fresh()->purchaseItems()->first()->quantity)->toBe(10)
        ->and($product->fresh()->stock_quantity)->toBe(8);

    // Nor deleted, since the return history has to stay.
    $this->delete(route('purchases.destroy', $purchase))->assertSessionHas('error');
    expect(Purchase::count())->toBe(1);
});

test('the returns list shows everything and filters by supplier, date and reference', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $first = delivery([[$product->id, 10, 1]], ['invoice_number' => 'AAA-PO']);
    $this->post(route('purchases.returns.store', $first), returnPayload($first, [2]));

    $other = Supplier::factory()->create(['name' => 'Second Supplier']);
    $this->supplier = $other;
    $second = delivery([[$product->id, 10, 1]], ['invoice_number' => 'BBB-PO']);
    $this->post(route('purchases.returns.store', $second), returnPayload($second, [3]));

    $this->get(route('purchases.returns.index'))->assertOk()
        ->assertSee('AAA-PO')->assertSee('BBB-PO')->assertSee(money(5));

    $this->get(route('purchases.returns.index', ['supplier_id' => $other->id]))
        ->assertSee('BBB-PO')->assertDontSee('AAA-PO');

    $this->get(route('purchases.returns.index', ['search' => 'AAA']))
        ->assertSee('AAA-PO')->assertDontSee('BBB-PO');

    $this->get(route('purchases.returns.index', ['from' => now()->addDay()->toDateString()]))
        ->assertDontSee('AAA-PO')->assertDontSee('BBB-PO');

    $this->get(route('purchases.returns.index', ['from' => '2026-10-10', 'to' => '2026-10-01']))
        ->assertSessionHasErrors('to');
});

test('a cashier cannot return goods or read the returns list', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $purchase = delivery([[$product->id, 5, 1]]);

    $this->actingAs(User::factory()->cashier()->create());

    $this->get(route('purchases.returns.index'))->assertForbidden();
    $this->post(route('purchases.returns.store', $purchase), returnPayload($purchase, [1]))->assertForbidden();

    expect(PurchaseReturn::count())->toBe(0)->and($product->fresh()->stock_quantity)->toBe(5);
});
