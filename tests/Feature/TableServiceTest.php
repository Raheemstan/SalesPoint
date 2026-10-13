<?php

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Table;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Models\User;
use App\Services\TableOrderService;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Table service only exists for a trade that has tables. The restaurant
    // preset switches on tables, kitchen, tips and service items together.
    Setting::set('business_type', 'restaurant');

    $this->server = User::factory()->cashier()->create(['name' => 'Ada']);
    $this->manager = User::factory()->manager()->create(['name' => 'Bola']);
    $this->table = Table::factory()->create(['name' => 'T1', 'seats' => 4]);

    $this->actingAs($this->server);
});

function menuItem(float $price = 2500, string $name = 'Jollof rice', int $stock = 50): Product
{
    return Product::factory()->create([
        'name' => $name,
        'sale_price' => $price,
        'stock_quantity' => $stock,
        'is_active' => true,
    ]);
}

/** An open order on the shared table, with $lines of [[Product, qty], ...]. */
function orderWith(array $lines = [], ?User $as = null): TableOrder
{
    $service = app(TableOrderService::class);
    $order = $service->open(test()->table, $as ?? test()->server, 2);

    foreach ($lines as [$product, $qty]) {
        $service->addItem($order, $product, $qty);
    }

    return $order->fresh();
}

/*
|--------------------------------------------------------------------------
| Feature gating - a pharmacy must never see a table screen
|--------------------------------------------------------------------------
*/

test('every table route 404s when the shop has no tables', function () {
    // A trade with no tables at all.
    Setting::set('business_type', 'pharmacy');

    $order = TableOrder::factory()->create(['table_id' => $this->table->id, 'user_id' => $this->server->id]);
    $item = TableOrderItem::factory()->create(['table_order_id' => $order->id]);

    $this->get(route('tables.index'))->assertNotFound();
    $this->post(route('table-orders.store', $this->table), ['guests' => 2])->assertNotFound();
    $this->get(route('table-orders.show', $order))->assertNotFound();
    $this->post(route('table-orders.items.store', $order), ['product_id' => 1, 'quantity' => 1])->assertNotFound();
    $this->patch(route('table-orders.items.update', [$order, $item]), ['quantity' => 2])->assertNotFound();
    $this->delete(route('table-orders.items.destroy', [$order, $item]))->assertNotFound();
    $this->post(route('table-orders.kitchen', $order))->assertNotFound();
    $this->post(route('table-orders.transfer', $order), ['table_id' => $this->table->id])->assertNotFound();
    $this->post(route('table-orders.settle', $order))->assertNotFound();

    $this->actingAs($this->manager);
    $this->post(route('tables.store'), ['name' => 'T2', 'seats' => 2])->assertNotFound();
    $this->put(route('tables.update', $this->table), ['name' => 'T2', 'seats' => 2])->assertNotFound();
    $this->delete(route('tables.destroy', $this->table))->assertNotFound();
    $this->delete(route('table-orders.destroy', $order), ['reason' => 'nope'])->assertNotFound();

    expect(TableOrder::count())->toBe(1);
});

test('the floor view works for a restaurant', function () {
    $this->get(route('tables.index'))->assertOk()->assertSee('T1');
});

test('a shop that is not a restaurant can still switch tables on', function () {
    // feature() reads the per-install override before the preset: a bar that was
    // set up as a retail shop is not boxed in by that choice.
    Setting::set('business_type', 'retail');
    $this->get(route('tables.index'))->assertNotFound();

    Setting::set('feature_tables', '1');
    $this->get(route('tables.index'))->assertOk();
});

test('the kitchen ticket is gated separately from tables', function () {
    // A cafe may run tables with no kitchen to send anything to.
    Setting::set('feature_kitchen', '0');
    $order = orderWith([[menuItem(), 1]]);

    $this->post(route('table-orders.kitchen', $order))->assertNotFound();
    expect($order->items()->first()->sent_to_kitchen_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The floor view
|--------------------------------------------------------------------------
*/

test('the floor view shows the running total, the server and the guest count', function () {
    Setting::set('tax_rate', '7.5');
    $order = orderWith([[menuItem(2000), 2]]);
    $order->update(['guests' => 5]);

    $response = $this->get(route('tables.index'));

    $response->assertOk()
        ->assertSee('T1')
        ->assertSee('Ada')                      // the server who opened it
        ->assertSee('5 guests')                 // sector term, pluralised
        ->assertSee(money(4300), false);        // 4000 + 7.5% tax, through SaleCalculator
});

test('a free table offers to open an order and an occupied one links to it', function () {
    $free = Table::factory()->create(['name' => 'T2']);
    $order = orderWith([[menuItem(), 1]]);

    $this->get(route('tables.index'))->assertOk()
        ->assertSee(route('table-orders.show', $order), false)
        ->assertSee(route('table-orders.store', $free), false);
});

test('the floor view greets a brand new restaurant with nothing to trip over', function () {
    Table::query()->delete();

    $this->get(route('tables.index'))->assertOk()->assertSee('No tables yet');
});

/*
|--------------------------------------------------------------------------
| Opening orders
|--------------------------------------------------------------------------
*/

test('a server opens an order on a free table', function () {
    $response = $this->post(route('table-orders.store', $this->table), ['guests' => 3, 'note' => 'Birthday']);

    $order = TableOrder::first();
    $response->assertRedirect(route('table-orders.show', $order));

    expect($order->guests)->toBe(3)
        ->and($order->note)->toBe('Birthday')
        ->and($order->user_id)->toBe($this->server->id)
        ->and($order->isOpen())->toBeTrue();
});

test('opening an order on an occupied table is refused', function () {
    orderWith();

    $this->post(route('table-orders.store', $this->table), ['guests' => 2])
        ->assertSessionHas('error', fn ($message) => str_contains($message, 'already has an open order'));

    expect(TableOrder::count())->toBe(1);
});

test('the guest count is validated', function () {
    $this->post(route('table-orders.store', $this->table), ['guests' => 0])->assertSessionHasErrors('guests');
    $this->post(route('table-orders.store', $this->table), [])->assertSessionHasErrors('guests');
    $this->post(route('table-orders.store', $this->table), ['guests' => 'lots'])->assertSessionHasErrors('guests');

    expect(TableOrder::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Adding to an order
|--------------------------------------------------------------------------
*/

test('adding an item snapshots the price, and a later rise does not change the bill', function () {
    $dish = menuItem(2500);
    $order = orderWith();

    $this->post(route('table-orders.items.store', $order), ['product_id' => $dish->id, 'quantity' => 2]);

    $dish->update(['sale_price' => 9999]);

    $this->get(route('table-orders.show', $order))->assertOk()->assertSee(money(5000), false);
    expect(app(TableOrderService::class)->totals($order->fresh())['grand_total'])->toBe(5000.0);
});

test('the browser cannot name its own price', function () {
    $dish = menuItem(2500);
    $order = orderWith();

    // A poked-in price is simply ignored: the column is not in the rules and the
    // service reads sale_price from the database.
    $this->post(route('table-orders.items.store', $order), [
        'product_id' => $dish->id,
        'quantity' => 1,
        'price' => 1,
    ]);

    expect((float) $order->items()->first()->price)->toBe(2500.0);
});

test('adding validates the item, the quantity and the course', function () {
    $order = orderWith();

    $this->post(route('table-orders.items.store', $order), ['quantity' => 1])->assertSessionHasErrors('product_id');
    $this->post(route('table-orders.items.store', $order), ['product_id' => 99999, 'quantity' => 1])
        ->assertSessionHasErrors('product_id');
    $this->post(route('table-orders.items.store', $order), ['product_id' => menuItem()->id, 'quantity' => 0])
        ->assertSessionHasErrors('quantity');
    $this->post(route('table-orders.items.store', $order), [
        'product_id' => menuItem()->id, 'quantity' => 1, 'course' => 'brunch',
    ])->assertSessionHasErrors('course');

    expect($order->items()->count())->toBe(0);
});

test('a line belonging to another order cannot be reached through this one', function () {
    $mine = orderWith([[menuItem(), 1]]);
    $other = app(TableOrderService::class)->open(Table::factory()->create(), $this->server, 2);
    $theirLine = app(TableOrderService::class)->addItem($other, menuItem(), 1);

    $this->patch(route('table-orders.items.update', [$mine, $theirLine]), ['quantity' => 99])->assertNotFound();
    $this->delete(route('table-orders.items.destroy', [$mine, $theirLine]))->assertNotFound();

    expect($theirLine->fresh()->quantity)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The kitchen
|--------------------------------------------------------------------------
*/

test('sending to the kitchen prints a ticket with only the new lines', function () {
    $service = app(TableOrderService::class);
    $order = orderWith([[menuItem(1000, 'Pepper soup'), 1]]);
    $service->sendToKitchen($order);

    $service->addItem($order->fresh(), menuItem(3000, 'Grilled fish'), 2);

    $response = $this->post(route('table-orders.kitchen', $order));

    $response->assertOk()
        ->assertSee('Grilled fish')
        ->assertDontSee('Pepper soup')      // already cooked; not printed twice
        ->assertSee('T1')
        ->assertSee('Ada')
        // A prep ticket carries no money: the kitchen does not price anything.
        ->assertDontSee(money(3000), false);
});

test('the ticket shows notes and courses, which is why it exists', function () {
    $order = orderWith();
    app(TableOrderService::class)->addItem($order, menuItem(1000, 'Steak'), 1, 'no pepper', 'main');

    $this->post(route('table-orders.kitchen', $order))->assertOk()
        ->assertSee('no pepper')
        ->assertSee('main', false);
});

test('a second send sends nothing', function () {
    $order = orderWith([[menuItem(), 1]]);

    $this->post(route('table-orders.kitchen', $order))->assertOk();
    $stamp = $order->items()->first()->sent_to_kitchen_at;

    $this->post(route('table-orders.kitchen', $order))
        ->assertRedirect()
        ->assertSessionHas('warning', fn ($m) => str_contains($m, 'already gone to the kitchen'));

    expect($order->items()->first()->sent_to_kitchen_at->toDateTimeString())->toBe($stamp->toDateTimeString());
});

/*
|--------------------------------------------------------------------------
| Editing kitchen-sent lines - the documented rule
|--------------------------------------------------------------------------
*/

test('a line the kitchen has cooked cannot be edited', function () {
    $order = orderWith([[menuItem(), 2]]);
    $this->post(route('table-orders.kitchen', $order));
    $item = $order->items()->first();

    $this->patch(route('table-orders.items.update', [$order, $item]), ['quantity' => 1])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'already gone to the kitchen'));

    expect($item->fresh()->quantity)->toBe(2);
});

test('removing a cooked line needs a reason and is audited', function () {
    $order = orderWith([[menuItem(1500, 'Suya'), 2]]);
    $this->post(route('table-orders.kitchen', $order));
    $item = $order->items()->first();

    // No reason: refused, and nothing is removed.
    $this->delete(route('table-orders.items.destroy', [$order, $item]))
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'the food has been made'));
    expect(TableOrderItem::count())->toBe(1);

    $this->delete(route('table-orders.items.destroy', [$order, $item]), ['reason' => 'Sent back cold'])
        ->assertSessionHas('success');

    expect(TableOrderItem::count())->toBe(0);
    expect(AuditLog::where('table_name', 'table_order_items')->first()->action)
        ->toContain('Sent back cold')->toContain('Suya');
});

test('an unsent line is removed with no reason and no audit noise', function () {
    $order = orderWith([[menuItem(), 1]]);
    $item = $order->items()->first();

    $this->delete(route('table-orders.items.destroy', [$order, $item]))->assertSessionHas('success');

    expect(TableOrderItem::count())->toBe(0)
        ->and(AuditLog::where('table_name', 'table_order_items')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Transfers
|--------------------------------------------------------------------------
*/

test('an order moves to a free table', function () {
    $order = orderWith([[menuItem(), 1]]);
    $target = Table::factory()->create(['name' => 'Terrace 3']);

    $this->post(route('table-orders.transfer', $order), ['table_id' => $target->id])
        ->assertSessionHas('success');

    expect($order->fresh()->table_id)->toBe($target->id);
});

test('a transfer to an occupied table is refused', function () {
    $order = orderWith([[menuItem(), 1]]);
    $busy = Table::factory()->create(['name' => 'T2']);
    app(TableOrderService::class)->open($busy, $this->manager, 2);

    $this->post(route('table-orders.transfer', $order), ['table_id' => $busy->id])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'occupied'));

    expect($order->fresh()->table_id)->toBe($this->table->id);
});

/*
|--------------------------------------------------------------------------
| Settling - the handover to the till
|--------------------------------------------------------------------------
*/

test('settling builds a session cart the till accepts and redirects to it', function () {
    $dish = menuItem(2500, 'Jollof rice');
    $drink = menuItem(1200, 'Star');
    $order = orderWith([[$dish, 2], [$drink, 3]]);

    $response = $this->post(route('table-orders.settle', $order));

    $response->assertRedirect(route('pos.index'));

    // Exactly the shape POSController::resolveCart() reads.
    $cart = session('cart');
    expect($cart)->toHaveCount(2)
        ->and($cart[$dish->id])->toMatchArray([
            'product_id' => $dish->id,
            'name' => 'Jollof rice',
            'price' => 2500.0,
            'quantity' => 2,
            'discount_type' => null,
            'discount_value' => null,
        ])
        ->and($cart[$drink->id]['quantity'])->toBe(3)
        // the baton that lets the sale be linked back
        ->and(session('table_order_id'))->toBe($order->id);

    // And the till really does accept it: this call runs resolveCart().
    $this->get(route('pos.index'))->assertOk()->assertSee('Jollof rice');

    // Still open: no money has been taken yet.
    expect($order->fresh()->isOpen())->toBeTrue();
});

test('an empty order cannot be settled', function () {
    $order = orderWith();

    $this->post(route('table-orders.settle', $order))
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'nothing to settle'));

    expect(session('cart'))->toBeNull()->and(session('table_order_id'))->toBeNull();
});

test('a discount left over at the till does not follow the table bill', function () {
    $order = orderWith([[menuItem(2000), 1]]);

    $this->withSession(['cart_discount' => ['type' => 'percent', 'value' => 50]])
        ->post(route('table-orders.settle', $order));

    expect(session('cart_discount'))->toBeNull();
});

test('the order customer is attached to the session for the till', function () {
    $guest = Customer::factory()->create();
    $order = orderWith([[menuItem(), 1]]);
    $order->update(['customer_id' => $guest->id]);

    $this->post(route('table-orders.settle', $order->fresh()));

    expect(session('cart_customer'))->toBe($guest->id);
});

test('completing the sale at the till marks the order settled and links the sale', function () {
    $dish = menuItem(2500, 'Jollof rice', stock: 10);
    $order = orderWith([[$dish, 2]]);

    $this->post(route('table-orders.settle', $order))->assertRedirect(route('pos.index'));

    // Payment goes through the one checkout the shop has.
    $this->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 5000]);

    $sale = Sale::first();
    expect($sale)->not->toBeNull()->and((float) $sale->grand_total)->toBe(5000.0);

    $closed = $order->fresh();
    expect($closed->status)->toBe(TableOrder::STATUS_SETTLED)
        ->and($closed->sale_id)->toBe($sale->id)
        ->and($closed->settled_at)->not->toBeNull()
        // the baton is handed back, so the next sale is a fresh one
        ->and(session('table_order_id'))->toBeNull();

    expect(AuditLog::where('action', 'like', 'Settled%')->exists())->toBeTrue();
});

test('settling through the till is what takes the stock off', function () {
    // The reason settling goes through the till rather than around it.
    $dish = menuItem(2500, 'Jollof rice', stock: 10);
    $order = orderWith([[$dish, 3]]);

    expect($dish->fresh()->stock_quantity)->toBe(10)
        ->and(InventoryLog::count())->toBe(0);

    $this->post(route('table-orders.settle', $order));
    $this->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 10000]);

    expect($dish->fresh()->stock_quantity)->toBe(7)
        ->and(InventoryLog::where('product_id', $dish->id)->where('type', 'OUT')->count())->toBe(1);
});

test('the till still refuses an order that outruns the stock', function () {
    // A table can be ordered past the stock level during service; the till is
    // where that is caught, which is another reason to settle through it.
    $dish = menuItem(1000, 'Last beer', stock: 2);
    $order = orderWith([[$dish, 5]]);

    $this->post(route('table-orders.settle', $order));
    $this->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 10000])
        ->assertSessionHas('error');

    expect(Sale::count())->toBe(0)
        ->and($order->fresh()->isOpen())->toBeTrue()
        ->and($dish->fresh()->stock_quantity)->toBe(2);
});

test('a closed order cannot be settled or added to again', function () {
    $dish = menuItem(2500, 'Jollof', stock: 10);
    $order = orderWith([[$dish, 1]]);
    $this->post(route('table-orders.settle', $order));
    $this->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 5000]);

    expect($order->fresh()->isSettled())->toBeTrue();

    $this->post(route('table-orders.items.store', $order), ['product_id' => $dish->id, 'quantity' => 1])
        ->assertForbidden();
    $this->post(route('table-orders.settle', $order))->assertForbidden();
    $this->post(route('table-orders.kitchen', $order))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Tips and the service charge
|--------------------------------------------------------------------------
*/

test('a percentage service charge is carried into the handover', function () {
    Setting::set('tax_rate', '0');
    $order = orderWith([[menuItem(2000), 5]]);   // 10,000

    $this->post(route('table-orders.settle', $order), [
        'service_charge_type' => 'percent',
        'service_charge_value' => 10,
    ])->assertRedirect(route('pos.index'));

    expect(session('cart_service_charge'))->toMatchArray([
        'type' => 'percent',
        'value' => 10.0,
        'amount' => 1000.0,
    ]);
    expect(session('cart_service_charge')['label'])->toBe('Service charge (10%)');
});

test('a fixed tip is carried into the handover', function () {
    $order = orderWith([[menuItem(2000), 1]]);

    $this->post(route('table-orders.settle', $order), [
        'service_charge_type' => 'fixed',
        'service_charge_value' => 500,
    ]);

    expect(session('cart_service_charge')['amount'])->toBe(500.0)
        ->and(session('cart_service_charge')['type'])->toBe('fixed');
});

test('no tip leaves no service charge in the session', function () {
    $order = orderWith([[menuItem(2000), 1]]);

    $this->withSession(['cart_service_charge' => ['amount' => 999.0]])
        ->post(route('table-orders.settle', $order), ['service_charge_type' => 'none']);

    expect(session('cart_service_charge'))->toBeNull();
});

test('a nonsensical service charge is refused and nothing is handed over', function () {
    $order = orderWith([[menuItem(2000), 1]]);

    $this->post(route('table-orders.settle', $order), [
        'service_charge_type' => 'percent',
        'service_charge_value' => 150,
    ])->assertSessionHasErrors('service_charge_value');

    $this->post(route('table-orders.settle', $order), [
        'service_charge_type' => 'cheeky',
        'service_charge_value' => 10,
    ])->assertSessionHasErrors('service_charge_type');

    expect(session('table_order_id'))->toBeNull();
});

test('the tip is worked out server side, not taken from the browser', function () {
    Setting::set('tax_rate', '0');
    $order = orderWith([[menuItem(2000), 1]]);

    // "10% is 50,000, trust me."
    $this->post(route('table-orders.settle', $order), [
        'service_charge_type' => 'percent',
        'service_charge_value' => 10,
        'amount' => 50000,
    ]);

    expect(session('cart_service_charge')['amount'])->toBe(200.0);
});

test('the tip controls only appear when the trade takes tips', function () {
    $order = orderWith([[menuItem(2000), 1]]);

    $this->get(route('table-orders.show', $order))->assertOk()->assertSee('Tip or service charge');

    Setting::set('feature_tips', '0');
    $this->get(route('table-orders.show', $order))->assertOk()->assertDontSee('Tip or service charge');
});

test('a shop that does not take tips cannot be given one by a crafted request', function () {
    // The gate is server-side, not merely a hidden form field.
    Setting::set('feature_tips', '0');
    $order = orderWith([[menuItem(2000), 1]]);

    $this->post(route('table-orders.settle', $order), [
        'service_charge_type' => 'percent',
        'service_charge_value' => 10,
    ])->assertRedirect(route('pos.index'));

    expect(session('cart_service_charge'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Cancelling
|--------------------------------------------------------------------------
*/

test('a manager cancels an order with a reason and no stock is ever taken', function () {
    $dish = menuItem(2500, 'Jollof', stock: 10);
    $order = orderWith([[$dish, 3]]);
    $this->post(route('table-orders.kitchen', $order));     // the food was cooked

    $this->actingAs($this->manager)
        ->delete(route('table-orders.destroy', $order), ['reason' => 'Guests walked out'])
        ->assertRedirect(route('tables.index'));

    expect($order->fresh()->status)->toBe(TableOrder::STATUS_CANCELLED)
        // Nothing was sold, so nothing left the shelves and no sale exists.
        ->and($dish->fresh()->stock_quantity)->toBe(10)
        ->and(InventoryLog::count())->toBe(0)
        ->and(Sale::count())->toBe(0);

    expect(AuditLog::where('action', 'like', '%Guests walked out%')->exists())->toBeTrue();
});

test('cancelling needs a reason', function () {
    $order = orderWith([[menuItem(), 1]]);

    $this->actingAs($this->manager)
        ->delete(route('table-orders.destroy', $order))
        ->assertSessionHasErrors('reason');

    expect($order->fresh()->isOpen())->toBeTrue();
});

test('a cancelled table can be seated again', function () {
    $order = orderWith([[menuItem(), 1]]);
    $this->actingAs($this->manager)->delete(route('table-orders.destroy', $order), ['reason' => 'Walked out']);

    $this->actingAs($this->server)
        ->post(route('table-orders.store', $this->table), ['guests' => 2])
        ->assertRedirect();

    expect(TableOrder::open()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Who may work on whose table - the documented rule
|--------------------------------------------------------------------------
*/

test('any server may pick up a colleague s table', function () {
    /*
     * The documented decision (see TableOrderPolicy): a table order is NOT
     * scoped to the server who opened it. A runner, the bar, or whoever walks
     * past when the guests ask must all be able to serve the table.
     */
    $order = orderWith([[menuItem(), 1]], as: $this->server);
    $colleague = User::factory()->cashier()->create(['name' => 'Chidi']);

    $this->actingAs($colleague);

    $this->get(route('table-orders.show', $order))->assertOk();
    $this->post(route('table-orders.items.store', $order), ['product_id' => menuItem()->id, 'quantity' => 1])
        ->assertSessionHas('success');
    $this->post(route('table-orders.kitchen', $order))->assertOk();
    $this->post(route('table-orders.transfer', $order), ['table_id' => Table::factory()->create()->id])
        ->assertSessionHas('success');

    expect($order->items()->count())->toBe(2);
});

test('a manager sees and settles every table', function () {
    $order = orderWith([[menuItem(2500, 'Jollof', stock: 10), 1]], as: $this->server);

    $this->actingAs($this->manager);

    $this->get(route('tables.index'))->assertOk()->assertSee('Ada');
    $this->get(route('table-orders.show', $order))->assertOk();
    $this->post(route('table-orders.settle', $order))->assertRedirect(route('pos.index'));
});

test('a server cannot cancel an order, a manager can', function () {
    $order = orderWith([[menuItem(), 1]]);

    $this->delete(route('table-orders.destroy', $order), ['reason' => 'I would rather not'])
        ->assertForbidden();
    expect($order->fresh()->isOpen())->toBeTrue();

    $this->actingAs($this->manager)
        ->delete(route('table-orders.destroy', $order), ['reason' => 'Guests left'])
        ->assertRedirect();
    expect($order->fresh()->status)->toBe(TableOrder::STATUS_CANCELLED);
});

/*
|--------------------------------------------------------------------------
| Managing the tables themselves
|--------------------------------------------------------------------------
*/

test('only managers may add, rename or remove tables', function () {
    // Enforced by the route middleware as well as the policy.
    $this->post(route('tables.store'), ['name' => 'T2', 'seats' => 2])->assertForbidden();
    $this->put(route('tables.update', $this->table), ['name' => 'T9', 'seats' => 2])->assertForbidden();
    $this->delete(route('tables.destroy', $this->table))->assertForbidden();

    expect(Table::count())->toBe(1)->and($this->table->fresh()->name)->toBe('T1');
});

test('a manager adds a table', function () {
    $this->actingAs($this->manager)
        ->post(route('tables.store'), [
            'name' => 'Terrace 3', 'area' => 'Terrace', 'seats' => 6, 'sort_order' => 2, 'is_active' => '1',
        ])
        ->assertRedirect(route('tables.index'));

    $table = Table::where('name', 'Terrace 3')->first();
    expect($table)->not->toBeNull()
        ->and($table->area)->toBe('Terrace')
        ->and($table->seats)->toBe(6)
        ->and($table->is_active)->toBeTrue();
});

test('table names are unique, and renaming a table to its own name is allowed', function () {
    Table::factory()->create(['name' => 'T2']);
    $this->actingAs($this->manager);

    $this->post(route('tables.store'), ['name' => 'T2', 'seats' => 2])->assertSessionHasErrors('name');
    $this->put(route('tables.update', $this->table), ['name' => 'T2', 'seats' => 4])->assertSessionHasErrors('name');

    $this->put(route('tables.update', $this->table), ['name' => 'T1', 'seats' => 6, 'is_active' => '1'])
        ->assertSessionHasNoErrors();

    expect($this->table->fresh()->seats)->toBe(6);
});

test('an unticked in-service box really does retire the table', function () {
    $this->actingAs($this->manager)
        ->put(route('tables.update', $this->table), ['name' => 'T1', 'seats' => 4]);

    expect($this->table->fresh()->is_active)->toBeFalse();

    // And a retired table cannot be seated.
    $this->actingAs($this->server)
        ->post(route('table-orders.store', $this->table), ['guests' => 2])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'out of service'));
});

test('a table with guests at it cannot be retired or deleted', function () {
    orderWith([[menuItem(), 1]]);
    $this->actingAs($this->manager);

    $this->put(route('tables.update', $this->table), ['name' => 'T1', 'seats' => 4])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'open order'));
    expect($this->table->fresh()->is_active)->toBeTrue();

    $this->delete(route('tables.destroy', $this->table))
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'Settle or cancel it first'));
    expect(Table::count())->toBe(1);
});

test('a table with past orders is retired rather than deleted', function () {
    $order = orderWith([[menuItem(), 1]]);
    app(TableOrderService::class)->cancel($order, 'Walked out');

    $this->actingAs($this->manager)
        ->delete(route('tables.destroy', $this->table))
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'Take it out of service instead'));

    expect(Table::count())->toBe(1);
});

test('a table that was never used can be deleted', function () {
    $spare = Table::factory()->create(['name' => 'Spare']);

    $this->actingAs($this->manager)
        ->delete(route('tables.destroy', $spare))
        ->assertRedirect(route('tables.index'));

    expect(Table::where('name', 'Spare')->exists())->toBeFalse();
    expect(AuditLog::where('action', 'Deleted table Spare')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Guests must still sign in
|--------------------------------------------------------------------------
*/

test('a guest is sent to the login page, not to the floor', function () {
    auth()->logout();

    $this->get(route('tables.index'))->assertRedirect(route('login'));
    $this->post(route('table-orders.store', $this->table), ['guests' => 2])->assertRedirect(route('login'));
});

/*
|--------------------------------------------------------------------------
| The migration
|--------------------------------------------------------------------------
*/

test('the migration rolls back and reapplies cleanly', function () {
    /*
     * A down() that does not work is a migration a shop cannot undo. On SQLite
     * that means children before parents and an index dropped before the table
     * it sits on, so it is worth proving rather than assuming.
     */
    $migration = require base_path('database/migrations/2026_10_14_000008_create_table_service_tables.php');

    $migration->down();

    expect(Schema::hasTable('table_order_items'))->toBeFalse()
        ->and(Schema::hasTable('table_orders'))->toBeFalse()
        ->and(Schema::hasTable('tables'))->toBeFalse()
        // Nothing the rest of the app depends on goes with them.
        ->and(Schema::hasTable('sales'))->toBeTrue()
        ->and(Schema::hasTable('products'))->toBeTrue()
        ->and(Schema::hasTable('customers'))->toBeTrue();

    $migration->up();

    expect(Schema::hasTable('tables'))->toBeTrue()
        ->and(Schema::hasTable('table_orders'))->toBeTrue()
        ->and(Schema::hasTable('table_order_items'))->toBeTrue();

    // And it is really usable again, not merely present. The rollback took the
    // rows with it, so the fixture table has to be made again.
    $this->table = Table::factory()->create(['name' => 'T1']);

    $order = orderWith([[menuItem(1000), 2]]);
    expect($order->items()->count())->toBe(1)
        ->and(app(TableOrderService::class)->totals($order)['grand_total'])->toBe(2000.0);
});

test('a line goes when its order is deleted outright', function () {
    // table_order_items cascades: the lines have no meaning without the order.
    $order = orderWith([[menuItem(), 2]]);
    expect(TableOrderItem::count())->toBe(1);

    $order->delete();

    expect(TableOrderItem::count())->toBe(0);
});
