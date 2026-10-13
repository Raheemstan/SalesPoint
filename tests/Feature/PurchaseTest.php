<?php

use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->manager()->create());
    $this->supplier = Supplier::create(['name' => 'Acme', 'phone' => '0800', 'address' => 'Lagos']);
});

function purchasePayload($supplier, array $lines, array $extra = []): array
{
    return array_merge([
        'supplier_id' => $supplier->id,
        'invoice_number' => 'PO-' . uniqid(),
        'purchase_date' => '2026-10-01',
        'product_ids' => array_column($lines, 0),
        'quantities' => array_column($lines, 1),
        'cost_prices' => array_column($lines, 2),
    ], $extra);
}

test('recording a purchase adds stock, totals correctly and logs an IN movement per line', function () {
    $a = Product::factory()->create(['stock_quantity' => 5]);
    $b = Product::factory()->create(['stock_quantity' => 0]);

    $this->post(route('purchases.store'), purchasePayload($this->supplier, [[$a->id, 10, 2.50], [$b->id, 4, 10]]))
        ->assertRedirect(route('purchases.index'))
        ->assertSessionHas('success');

    expect($a->fresh()->stock_quantity)->toBe(15)->and($b->fresh()->stock_quantity)->toBe(4);
    expect((float) Purchase::first()->total_amount)->toBe(65.0);
    expect(InventoryLog::where('type', 'IN')->count())->toBe(2);
});

test('validation errors reach the form instead of a generic failure (regression)', function () {
    $this->post(route('purchases.store'), ['supplier_id' => $this->supplier->id])
        ->assertSessionHasErrors(['invoice_number', 'purchase_date', 'product_ids']);

    $p = Product::factory()->create();
    $this->post(route('purchases.store'), purchasePayload($this->supplier, [[$p->id, 0, 5]]))
        ->assertSessionHasErrors('quantities.0');

    expect(Purchase::count())->toBe(0);
});

test('mismatched item arrays are rejected rather than crashing with an undefined index', function () {
    $p = Product::factory()->create();

    $payload = purchasePayload($this->supplier, [[$p->id, 1, 5]]);
    $payload['quantities'] = [];

    $this->post(route('purchases.store'), $payload)->assertSessionHasErrors();
    expect(Purchase::count())->toBe(0);
});

test('a duplicate invoice number is rejected', function () {
    $p = Product::factory()->create();
    $payload = purchasePayload($this->supplier, [[$p->id, 1, 5]], ['invoice_number' => 'DUP-1']);

    $this->post(route('purchases.store'), $payload)->assertSessionHas('success');
    $this->post(route('purchases.store'), $payload)->assertSessionHasErrors('invoice_number');
});

test('deleting a purchase takes the stock back out (regression: it used a non-existent `stock` column and a broken audit insert)', function () {
    $p = Product::factory()->create(['stock_quantity' => 0]);
    $this->post(route('purchases.store'), purchasePayload($this->supplier, [[$p->id, 10, 5]]));
    $purchase = Purchase::first();

    $this->delete(route('purchases.destroy', $purchase->id))
        ->assertRedirect(route('purchases.index'))
        ->assertSessionHas('success');

    expect($p->fresh()->stock_quantity)->toBe(0);
    expect(Purchase::count())->toBe(0);
    expect(InventoryLog::where('type', 'OUT')->count())->toBe(1);
});

test('a purchase cannot be deleted once its stock has been sold', function () {
    $p = Product::factory()->create(['stock_quantity' => 0]);
    $this->post(route('purchases.store'), purchasePayload($this->supplier, [[$p->id, 10, 5]]));
    $p->decrement('stock_quantity', 8); // 8 of the 10 sold

    $this->delete(route('purchases.destroy', Purchase::first()->id))->assertSessionHas('error');

    expect(Purchase::count())->toBe(1)->and($p->fresh()->stock_quantity)->toBe(2);
});

test('the index filters actually filter (the form field names did not match the controller)', function () {
    $p = Product::factory()->create();
    $other = Supplier::create(['name' => 'Other', 'phone' => '1', 'address' => 'x']);

    $this->post(route('purchases.store'), purchasePayload($this->supplier, [[$p->id, 1, 1]], ['invoice_number' => 'AAA-1', 'purchase_date' => '2026-01-05']));
    $this->post(route('purchases.store'), purchasePayload($other, [[$p->id, 1, 1]], ['invoice_number' => 'BBB-2', 'purchase_date' => '2026-03-05']));

    $this->get(route('purchases.index', ['search' => 'AAA']))->assertSee('AAA-1')->assertDontSee('BBB-2');
    $this->get(route('purchases.index', ['from_date' => '2026-02-01']))->assertSee('BBB-2')->assertDontSee('AAA-1');
    $this->get(route('purchases.index', ['to_date' => '2026-02-01']))->assertSee('AAA-1')->assertDontSee('BBB-2');
    $this->get(route('purchases.index', ['supplier_id' => $other->id]))->assertSee('BBB-2')->assertDontSee('AAA-1');
});

test('stock shown after a purchase is not stale (the product list used to be cached for an hour)', function () {
    $p = Product::factory()->create(['stock_quantity' => 1, 'name' => 'Fresh Widget']);
    $this->get(route('purchases.index'))->assertOk();

    $p->update(['stock_quantity' => 99]);
    expect(cache()->has('products.all'))->toBeFalse();
});

test('the purchase page renders', function () {
    $p = Product::factory()->create();
    $this->post(route('purchases.store'), purchasePayload($this->supplier, [[$p->id, 1, 5]]));

    $this->get(route('purchases.show', Purchase::first()->id))->assertOk();
});

test('suppliers can be added inline, even with no address (column is NOT NULL)', function () {
    $this->post(route('suppliers.store'), ['name' => 'No Address Ltd', 'phone' => '0803'])->assertSessionHas('success');

    expect(Supplier::where('name', 'No Address Ltd')->value('address'))->toBe('');
});

/*
|--------------------------------------------------------------------------
| The lifecycle: ordered, received, cancelled
|--------------------------------------------------------------------------
|
| Stock must only move when the goods are actually in. Everything below is
| about the difference between raising an order and taking a delivery.
|
*/

test('a purchase recorded as ordered moves no stock, and receiving it does', function () {
    $p = Product::factory()->create(['stock_quantity' => 5]);
    $lines = [[$p->id, 10, 2.00]];

    $this->post(route('purchases.store'), purchasePayload($this->supplier, $lines, [
        'invoice_number' => 'ORD-1',
        'status' => 'ordered',
    ]))->assertSessionHas('success');

    $purchase = Purchase::first();

    expect($purchase->status)->toBe('ordered')
        ->and($purchase->received_at)->toBeNull()
        ->and($p->fresh()->stock_quantity)->toBe(5)
        ->and(InventoryLog::count())->toBe(0);

    // Receiving it is what puts the goods on the shelf.
    $this->put(route('purchases.update', $purchase), purchasePayload($this->supplier, $lines, [
        'invoice_number' => 'ORD-1',
        'status' => 'received',
    ]))->assertRedirect(route('purchases.show', $purchase))->assertSessionHas('success');

    expect($p->fresh()->stock_quantity)->toBe(15)
        ->and($purchase->fresh()->status)->toBe('received')
        ->and($purchase->fresh()->received_at)->not->toBeNull()
        ->and(InventoryLog::where('type', 'IN')->where('quantity', 10)->count())->toBe(1);
});

test('editing a received purchase down takes the difference back out of stock', function () {
    $p = Product::factory()->create(['stock_quantity' => 0]);
    $this->post(route('purchases.store'), purchasePayload($this->supplier, [[$p->id, 10, 5]], ['invoice_number' => 'EDIT-1']));

    expect($p->fresh()->stock_quantity)->toBe(10);

    $this->put(route('purchases.update', Purchase::first()), purchasePayload($this->supplier, [[$p->id, 7, 5]], ['invoice_number' => 'EDIT-1']))
        ->assertSessionHas('success');

    expect($p->fresh()->stock_quantity)->toBe(7)
        ->and((float) Purchase::first()->total_amount)->toBe(35.0)
        // 3 taken back out, not 7 added on top of the 10 already there.
        ->and(InventoryLog::where('type', 'OUT')->where('quantity', 3)->exists())->toBeTrue();
});

test('an edit reconciles each line: more, less, removed and added', function () {
    $a = Product::factory()->create(['stock_quantity' => 0]);
    $b = Product::factory()->create(['stock_quantity' => 0]);
    $c = Product::factory()->create(['stock_quantity' => 0]);

    $this->post(route('purchases.store'), purchasePayload($this->supplier, [[$a->id, 10, 1], [$b->id, 4, 2]], ['invoice_number' => 'MIX-1']));

    $this->put(route('purchases.update', Purchase::first()), purchasePayload($this->supplier, [[$a->id, 12, 1], [$c->id, 3, 5]], ['invoice_number' => 'MIX-1']))
        ->assertSessionHas('success');

    expect($a->fresh()->stock_quantity)->toBe(12)  // 10 -> 12: two more in
        ->and($b->fresh()->stock_quantity)->toBe(0) // line dropped: all four back out
        ->and($c->fresh()->stock_quantity)->toBe(3) // new line: three in
        ->and((float) Purchase::first()->total_amount)->toBe(27.0)
        ->and(Purchase::first()->purchaseItems()->count())->toBe(2);
});

test('an edit that would drive stock negative is refused', function () {
    $p = Product::factory()->create(['stock_quantity' => 0]);
    $this->post(route('purchases.store'), purchasePayload($this->supplier, [[$p->id, 10, 5]], ['invoice_number' => 'NEG-1']));
    $p->decrement('stock_quantity', 9); // 9 of the 10 sold, 1 left on the shelf

    $this->put(route('purchases.update', Purchase::first()), purchasePayload($this->supplier, [[$p->id, 2, 5]], ['invoice_number' => 'NEG-1']))
        ->assertSessionHas('error');

    // Nothing changed: not the stock, not the line.
    expect($p->fresh()->stock_quantity)->toBe(1)
        ->and(Purchase::first()->purchaseItems()->first()->quantity)->toBe(10)
        ->and((float) Purchase::first()->total_amount)->toBe(50.0);
});

test('cancelling a received purchase takes its stock back out, and deleting an order takes nothing', function () {
    $p = Product::factory()->create(['stock_quantity' => 0]);
    $lines = [[$p->id, 6, 1]];

    $this->post(route('purchases.store'), purchasePayload($this->supplier, $lines, ['invoice_number' => 'CAN-1']));
    $received = Purchase::first();

    $this->put(route('purchases.update', $received), purchasePayload($this->supplier, $lines, [
        'invoice_number' => 'CAN-1',
        'status' => 'cancelled',
    ]))->assertSessionHas('success');

    expect($p->fresh()->stock_quantity)->toBe(0)
        ->and($received->fresh()->received_at)->toBeNull();

    // An order that never arrived has nothing to take back out when it is deleted.
    $this->post(route('purchases.store'), purchasePayload($this->supplier, $lines, [
        'invoice_number' => 'CAN-2',
        'status' => 'ordered',
    ]));
    $ordered = Purchase::where('invoice_number', 'CAN-2')->first();

    $this->delete(route('purchases.destroy', $ordered))->assertSessionHas('success');

    expect($p->fresh()->stock_quantity)->toBe(0)
        ->and(InventoryLog::where('reason', 'like', '%CAN-2%')->count())->toBe(0);
});

test('a purchase records what was paid on delivery and what is still owed', function () {
    $p = Product::factory()->create(['stock_quantity' => 0]);

    $this->post(route('purchases.store'), purchasePayload($this->supplier, [[$p->id, 10, 5]], [
        'invoice_number' => 'PAY-1',
        'amount_paid' => 20,
    ]))->assertSessionHas('success');

    expect((float) Purchase::first()->amount_paid)->toBe(20.0)
        ->and((float) Purchase::first()->balance_due)->toBe(30.0);

    // More than the invoice cannot be recorded against it.
    $this->post(route('purchases.store'), purchasePayload($this->supplier, [[$p->id, 1, 5]], [
        'invoice_number' => 'PAY-2',
        'amount_paid' => 999,
    ]));

    $second = Purchase::where('invoice_number', 'PAY-2')->first();
    expect((float) $second->amount_paid)->toBe(5.0)->and((float) $second->balance_due)->toBe(0.0);
});

test('the edit page renders and an unknown status is rejected', function () {
    $p = Product::factory()->create();
    $this->post(route('purchases.store'), purchasePayload($this->supplier, [[$p->id, 1, 5]], ['invoice_number' => 'REN-1']));
    $purchase = Purchase::first();

    $this->get(route('purchases.create'))->assertOk();
    $this->get(route('purchases.edit', $purchase))->assertOk()->assertSee('REN-1');

    $this->put(route('purchases.update', $purchase), purchasePayload($this->supplier, [[$p->id, 1, 5]], [
        'invoice_number' => 'REN-1',
        'status' => 'delivered-ish',
    ]))->assertSessionHasErrors('status');
});

test('an inactive supplier is no longer offered on the purchase form', function () {
    $this->supplier->update(['is_active' => false]);
    $live = Supplier::factory()->create(['name' => 'Still Trading']);

    $this->get(route('purchases.create'))->assertOk()
        ->assertSee('Still Trading')->assertDontSee('Acme');

    // It can still be edited on a purchase that already uses it, though.
    $p = Product::factory()->create();
    $this->post(route('purchases.store'), purchasePayload($this->supplier, [[$p->id, 1, 5]], ['invoice_number' => 'OLD-1']));

    $this->get(route('purchases.edit', Purchase::first()))->assertOk()->assertSee('Acme');
    expect($live->exists)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Purchase orders raised from the reorder list
|--------------------------------------------------------------------------
*/

test('a purchase order can be raised from the reorder list in any of the shapes it might post', function () {
    $p = Product::factory()->create(['stock_quantity' => 1, 'cost_price' => 12.50]);

    $shapes = [
        // rows: items[0][product_id] / items[0][quantity]
        ['items' => [['product_id' => $p->id, 'quantity' => 4]]],
        // keyed by product id, with a quantity field
        ['items' => [$p->id => ['quantity' => 4]]],
        // keyed by product id, bare quantity
        ['items' => [$p->id => 4]],
        // parallel arrays, like the purchase form itself
        ['product_ids' => [$p->id], 'quantities' => [4]],
        // a checkbox list plus a quantity map keyed by product id
        ['selected' => [$p->id], 'quantities' => [$p->id => 4]],
    ];

    foreach ($shapes as $i => $shape) {
        $this->post(route('reorder.purchase-order'), array_merge(['supplier_id' => $this->supplier->id], $shape))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $order = Purchase::latest('id')->first();

        expect($order->status)->toBe('ordered')
            ->and($order->invoice_number)->toStartWith('PO-')
            // Cost comes from the catalogue, so 4 x 12.50.
            ->and((float) $order->total_amount)->toBe(50.0)
            ->and($order->purchaseItems()->first()->quantity)->toBe(4);
    }

    // Nothing was delivered, so nothing moved.
    expect($p->fresh()->stock_quantity)->toBe(1)
        ->and(InventoryLog::count())->toBe(0)
        ->and(Purchase::count())->toBe(count($shapes));
});

test('the reorder payload is validated hard, and untouched rows are ignored', function () {
    $p = Product::factory()->create(['cost_price' => 10]);

    // No supplier, no rows.
    $this->post(route('reorder.purchase-order'), [])->assertSessionHasErrors('supplier_id');

    // A supplier but nothing selected.
    $this->post(route('reorder.purchase-order'), ['supplier_id' => $this->supplier->id])
        ->assertSessionHasErrors('lines');

    // Rows left at zero are rows the user did not pick.
    $this->post(route('reorder.purchase-order'), [
        'supplier_id' => $this->supplier->id,
        'items' => [$p->id => 0, 999999 => 0],
    ])->assertSessionHasErrors('lines');

    // Garbage is rejected rather than quietly dropped.
    foreach ([
        ['items' => [$p->id => 'plenty']],
        ['items' => [$p->id => -3]],
        ['items' => [999999 => 2]],
    ] as $payload) {
        $this->post(route('reorder.purchase-order'), array_merge(['supplier_id' => $this->supplier->id], $payload))
            ->assertSessionHasErrors();
    }

    expect(Purchase::count())->toBe(0);

    // A posted cost is honoured when it is a sane number, and duplicates merge.
    $this->post(route('reorder.purchase-order'), [
        'supplier_id' => $this->supplier->id,
        'items' => [
            ['product_id' => $p->id, 'quantity' => 2, 'cost_price' => 7.25],
            ['product_id' => $p->id, 'quantity' => 3, 'cost_price' => 7.25],
        ],
        'expected_at' => '2026-11-01',
    ])->assertSessionHas('success');

    $order = Purchase::first();
    expect($order->purchaseItems()->count())->toBe(1)
        ->and($order->purchaseItems()->first()->quantity)->toBe(5)
        ->and((float) $order->total_amount)->toBe(36.25)
        ->and($order->expected_at->toDateString())->toBe('2026-11-01');
});

test('a cashier cannot see or change any purchase', function () {
    $p = Product::factory()->create();
    $this->post(route('purchases.store'), purchasePayload($this->supplier, [[$p->id, 1, 5]], ['invoice_number' => 'CSH-1']));
    $purchase = Purchase::first();

    $this->actingAs(User::factory()->cashier()->create());

    $this->get(route('purchases.index'))->assertForbidden();
    $this->get(route('purchases.show', $purchase))->assertForbidden();
    $this->get(route('purchases.edit', $purchase))->assertForbidden();
    $this->put(route('purchases.update', $purchase), [])->assertForbidden();
    $this->delete(route('purchases.destroy', $purchase))->assertForbidden();
    $this->post(route('reorder.purchase-order'), [])->assertForbidden();

    expect(Purchase::count())->toBe(1);
});

