<?php

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Table;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Models\User;
use App\Services\TableOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| tests/Pest.php only binds the Laravel test case to the Feature suite, so a
| unit test that touches the database asks for it here.
*/
uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(TableOrderService::class);
    $this->server = User::factory()->cashier()->create();

    // AuditLog::record() needs somebody to attribute the entry to.
    $this->actingAs($this->server);

    $this->table = Table::factory()->create(['name' => 'T1']);
});

/** A menu item with a known price and plenty of stock. */
function dish(float $price, string $name = 'Jollof rice', int $stock = 50): Product
{
    return Product::factory()->create([
        'name' => $name,
        'sale_price' => $price,
        'stock_quantity' => $stock,
    ]);
}

function openOrder(?Table $table = null, int $guests = 2): TableOrder
{
    return test()->service->open($table ?? test()->table, test()->server, $guests);
}

/*
|--------------------------------------------------------------------------
| Opening
|--------------------------------------------------------------------------
*/

test('opening an order stamps the table, the server and the time', function () {
    $order = openOrder(guests: 4);

    expect($order->status)->toBe(TableOrder::STATUS_OPEN)
        ->and($order->table_id)->toBe($this->table->id)
        ->and($order->user_id)->toBe($this->server->id)
        ->and($order->guests)->toBe(4)
        ->and($order->opened_at)->not->toBeNull()
        ->and($order->reference)->toStartWith('TBL-');
});

test('references are unique even when several orders are opened in the same second', function () {
    $tables = Table::factory()->count(5)->create();

    $references = collect($tables)->map(fn ($table) => openOrder($table)->reference);

    expect($references->unique())->toHaveCount(5);
});

test('a table can only have one open order at a time', function () {
    openOrder();

    expect(fn () => openOrder())->toThrow(DomainException::class, 'already has an open order');
    expect(TableOrder::count())->toBe(1);
});

test('a settled or cancelled order frees the table again', function () {
    $first = openOrder();
    $this->service->cancel($first, 'Guests left');

    $second = openOrder();

    expect($second->id)->not->toBe($first->id)->and($second->isOpen())->toBeTrue();
});

test('a table that is out of service cannot be seated', function () {
    $retired = Table::factory()->inactive()->create(['name' => 'T9']);

    expect(fn () => openOrder($retired))->toThrow(DomainException::class, 'out of service');
});

test('an order needs at least one guest', function () {
    expect(fn () => openOrder(guests: 0))->toThrow(DomainException::class, 'at least one guest');
});

/*
|--------------------------------------------------------------------------
| Adding, and the price snapshot
|--------------------------------------------------------------------------
*/

test('adding an item snapshots the price from the database', function () {
    $order = openOrder();
    $dish = dish(2500);

    $item = $this->service->addItem($order, $dish, 2);

    expect((float) $item->price)->toBe(2500.0)->and($item->quantity)->toBe(2);
});

test('a menu price rise after an item was ordered does not change the order total', function () {
    $order = openOrder();
    $dish = dish(2500);
    $this->service->addItem($order, $dish, 2);

    // The kitchen re-prices mid-service. The guests at this table already agreed
    // to the old price, so their bill must not move.
    $dish->update(['sale_price' => 4000]);

    expect($this->service->totals($order->fresh())['grand_total'])->toBe(5000.0);
});

test('identical unsent lines are merged rather than repeated', function () {
    $order = openOrder();
    $dish = dish(1000);

    $this->service->addItem($order, $dish, 1);
    $this->service->addItem($order, $dish, 2);

    expect($order->items()->count())->toBe(1)
        ->and($order->items()->first()->quantity)->toBe(3);
});

test('lines with different notes or courses stay separate', function () {
    $order = openOrder();
    $dish = dish(1000);

    $this->service->addItem($order, $dish, 1, 'no onions');
    $this->service->addItem($order, $dish, 1, 'extra pepper');
    $this->service->addItem($order, $dish, 1, 'no onions', 'main');

    expect($order->items()->count())->toBe(3);
});

test('a new round is never merged into a line the kitchen has already cooked', function () {
    $order = openOrder();
    $dish = dish(1000);
    $this->service->addItem($order, $dish, 1);
    $this->service->sendToKitchen($order);

    $this->service->addItem($order, $dish, 1);

    expect($order->items()->count())->toBe(2)
        ->and($order->items()->whereNull('sent_to_kitchen_at')->count())->toBe(1);
});

test('a line added after a price change is not folded into the cheaper one', function () {
    $order = openOrder();
    $dish = dish(1000);
    $this->service->addItem($order, $dish, 1);

    $dish->update(['sale_price' => 1500]);
    $this->service->addItem($order, $dish, 1);

    expect($order->items()->count())->toBe(2)
        ->and($this->service->totals($order->fresh())['grand_total'])->toBe(2500.0);
});

test('an archived menu item cannot be ordered', function () {
    $order = openOrder();
    $dish = dish(1000);
    $dish->update(['is_active' => false]);

    expect(fn () => $this->service->addItem($order, $dish, 1))
        ->toThrow(DomainException::class, 'not on the menu');
});

test('a quantity below one is refused', function () {
    $order = openOrder();

    expect(fn () => $this->service->addItem($order, dish(1000), 0))
        ->toThrow(DomainException::class, 'at least 1');
});

test('an unknown course is refused', function () {
    $order = openOrder();

    expect(fn () => $this->service->addItem($order, dish(1000), 1, null, 'elevenses'))
        ->toThrow(DomainException::class, 'course the kitchen recognises');
});

test('nothing can be added to a closed order', function () {
    $order = openOrder();
    $this->service->cancel($order, 'Walked out');

    expect(fn () => $this->service->addItem($order->fresh(), dish(1000), 1))
        ->toThrow(DomainException::class, 'closed');
});

/*
|--------------------------------------------------------------------------
| Totals
|--------------------------------------------------------------------------
*/

test('the running total goes through the shared calculator, including tax', function () {
    Setting::set('tax_rate', '7.5');
    $order = openOrder();
    $this->service->addItem($order, dish(2000), 2);

    $totals = $this->service->totals($order->fresh());

    expect($totals['subtotal'])->toBe(4000.0)
        ->and($totals['tax'])->toBe(300.0)
        ->and($totals['grand_total'])->toBe(4300.0);
});

test('an empty order totals zero rather than failing', function () {
    expect($this->service->totals(openOrder())['grand_total'])->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| Editing and removing
|--------------------------------------------------------------------------
*/

test('an unsent line can be edited freely', function () {
    $order = openOrder();
    $item = $this->service->addItem($order, dish(1000), 1);

    $this->service->updateItem($item, 3, 'well done', 'main');

    expect($item->fresh()->quantity)->toBe(3)
        ->and($item->fresh()->note)->toBe('well done')
        ->and($item->fresh()->course)->toBe('main');
});

test('a line the kitchen has cooked cannot be edited at all', function () {
    $order = openOrder();
    $item = $this->service->addItem($order, dish(1000), 2);
    $this->service->sendToKitchen($order);

    expect(fn () => $this->service->updateItem($item->fresh(), 1))
        ->toThrow(DomainException::class, 'already gone to the kitchen');

    expect($item->fresh()->quantity)->toBe(2);
});

test('an unsent line is removed without ceremony', function () {
    $order = openOrder();
    $item = $this->service->addItem($order, dish(1000), 1);

    $this->service->removeItem($item, null);

    expect(TableOrderItem::count())->toBe(0)
        ->and(AuditLog::where('table_name', 'table_order_items')->count())->toBe(0);
});

test('removing a line the kitchen has cooked needs a reason and is audited', function () {
    $order = openOrder();
    $item = $this->service->addItem($order, dish(1500, 'Suya'), 2);
    $this->service->sendToKitchen($order);
    $sent = $item->fresh();

    expect(fn () => $this->service->removeItem($sent, null))
        ->toThrow(DomainException::class, 'the food has been made');
    expect(fn () => $this->service->removeItem($sent, '   '))
        ->toThrow(DomainException::class, 'the food has been made');

    // Still there: a refused removal must not remove anything.
    expect(TableOrderItem::count())->toBe(1);

    $this->service->removeItem($sent, 'Sent back cold');

    expect(TableOrderItem::count())->toBe(0);

    $entry = AuditLog::where('table_name', 'table_order_items')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->action)->toContain('Sent back cold')
        ->and($entry->action)->toContain('Suya')
        ->and($entry->user_id)->toBe($this->server->id);
});

/*
|--------------------------------------------------------------------------
| The kitchen
|--------------------------------------------------------------------------
*/

test('sending to the kitchen stamps only the lines that have not been sent', function () {
    $order = openOrder();
    $first = $this->service->addItem($order, dish(1000, 'Starter'), 1);

    $sent = $this->service->sendToKitchen($order);
    expect($sent)->toHaveCount(1);

    $second = $this->service->addItem($order->fresh(), dish(2000, 'Main'), 1);

    $again = $this->service->sendToKitchen($order->fresh());

    expect($again)->toHaveCount(1)
        ->and($again->first()->id)->toBe($second->id)
        // the first line keeps its original stamp
        ->and($first->fresh()->sent_to_kitchen_at->toDateTimeString())
            ->toBe($sent->first()->sent_to_kitchen_at->toDateTimeString());
});

test('a second send with nothing new sends nothing', function () {
    $order = openOrder();
    $this->service->addItem($order, dish(1000), 1);
    $this->service->sendToKitchen($order);

    expect($this->service->sendToKitchen($order->fresh()))->toBeEmpty();
});

test('sending an order with nothing on it sends nothing', function () {
    expect($this->service->sendToKitchen(openOrder()))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Transfers
|--------------------------------------------------------------------------
*/

test('an order moves to a free table and the move is audited', function () {
    $order = openOrder();
    $target = Table::factory()->create(['name' => 'T2']);

    $this->service->transfer($order, $target);

    expect($order->fresh()->table_id)->toBe($target->id);

    $entry = AuditLog::where('table_name', 'table_orders')->latest('id')->first();
    expect($entry->action)->toContain('T1')->and($entry->action)->toContain('T2');
});

test('an order cannot be moved onto an occupied table', function () {
    $order = openOrder();
    $other = Table::factory()->create(['name' => 'T2']);
    openOrder($other);

    expect(fn () => $this->service->transfer($order, $other))
        ->toThrow(DomainException::class, 'occupied');

    expect($order->fresh()->table_id)->toBe($this->table->id);
});

test('an order cannot be moved onto its own table or a retired one', function () {
    $order = openOrder();

    expect(fn () => $this->service->transfer($order, $this->table))
        ->toThrow(DomainException::class, 'already on that table');

    $retired = Table::factory()->inactive()->create();
    expect(fn () => $this->service->transfer($order, $retired))
        ->toThrow(DomainException::class, 'out of service');
});

/*
|--------------------------------------------------------------------------
| Cancelling and settling
|--------------------------------------------------------------------------
*/

test('cancelling needs a reason, is audited, and moves no stock', function () {
    $order = openOrder();
    $dish = dish(1000, 'Rice', 50);
    $this->service->addItem($order, $dish, 3);
    $this->service->sendToKitchen($order);

    expect(fn () => $this->service->cancel($order->fresh(), '  '))
        ->toThrow(DomainException::class, 'why the order is being cancelled');

    $this->service->cancel($order->fresh(), 'Guests walked out');

    expect($order->fresh()->status)->toBe(TableOrder::STATUS_CANCELLED)
        // The whole point: an order is intentions, not stock. Nothing was taken.
        ->and($dish->fresh()->stock_quantity)->toBe(50)
        ->and(InventoryLog::count())->toBe(0);

    $entry = AuditLog::where('table_name', 'table_orders')->latest('id')->first();
    expect($entry->action)->toContain('Guests walked out');
});

test('a closed order cannot be cancelled twice', function () {
    $order = openOrder();
    $this->service->cancel($order, 'Mistake');

    expect(fn () => $this->service->cancel($order->fresh(), 'Again'))
        ->toThrow(DomainException::class, 'closed');
});

test('marking an order settled records which sale paid for it', function () {
    $order = openOrder();

    // SaleFactory has no defaults, so the required columns are given here.
    $sale = Sale::create([
        'invoice_number' => 'INV-TBL-0001',
        'user_id' => $this->server->id,
        'total_amount' => 5000,
        'grand_total' => 5000,
        'payment_method' => 'cash',
        'paid_amount' => 5000,
        'sale_date' => now(),
        'status' => Sale::STATUS_COMPLETED,
    ]);

    $this->service->markSettled($order, $sale->id);

    expect($order->fresh()->status)->toBe(TableOrder::STATUS_SETTLED)
        ->and($order->fresh()->sale_id)->toBe($sale->id)
        ->and($order->fresh()->settled_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The handover cart
|--------------------------------------------------------------------------
*/

test('the handover cart has exactly the shape the till expects', function () {
    $order = openOrder();
    $dish = dish(2500, 'Jollof');
    $this->service->addItem($order, $dish, 2);

    $cart = $this->service->cartFor($order->fresh());

    expect($cart)->toHaveKey($dish->id)
        ->and($cart[$dish->id])->toHaveKeys([
            'product_id', 'name', 'price', 'quantity', 'discount_type', 'discount_value',
        ])
        ->and($cart[$dish->id]['product_id'])->toBe($dish->id)
        ->and($cart[$dish->id]['name'])->toBe('Jollof')
        ->and($cart[$dish->id]['price'])->toBe(2500.0)
        ->and($cart[$dish->id]['quantity'])->toBe(2)
        ->and($cart[$dish->id]['discount_type'])->toBeNull();
});

test('two rounds of the same drink add up instead of overwriting each other', function () {
    // The cart is keyed by product id, so a second line of the same product has
    // to be added to the first - otherwise a whole round is served free.
    $order = openOrder();
    $beer = dish(1200, 'Star');

    $this->service->addItem($order, $beer, 2);
    $this->service->sendToKitchen($order);          // locks the first line
    $this->service->addItem($order->fresh(), $beer, 3);

    $cart = $this->service->cartFor($order->fresh());

    expect($cart)->toHaveCount(1)
        ->and($cart[$beer->id]['quantity'])->toBe(5);
});

test('a menu item deleted mid-service drops out of the handover rather than breaking it', function () {
    $order = openOrder();
    $keep = dish(1000, 'Rice');
    $gone = dish(500, 'Water');
    $this->service->addItem($order, $keep, 1);
    $this->service->addItem($order, $gone, 1);

    $gone->delete();

    $cart = $this->service->cartFor($order->fresh());

    expect($cart)->toHaveCount(1)->and($cart)->toHaveKey($keep->id);
});

/*
|--------------------------------------------------------------------------
| The service charge - the one sum this feature owns
|--------------------------------------------------------------------------
*/

test('a percentage service charge is a percentage of the bill', function () {
    expect($this->service->serviceCharge(10000, 'percent', 10))->toBe(1000.0)
        ->and($this->service->serviceCharge(4350, 'percent', 12.5))->toBe(543.75)
        ->and($this->service->serviceCharge(3333.33, 'percent', 7.5))->toBe(250.0);
});

test('a fixed service charge is taken as given', function () {
    expect($this->service->serviceCharge(10000, 'fixed', 2500))->toBe(2500.0)
        // A generous tip on a small bill is still a tip.
        ->and($this->service->serviceCharge(500, 'fixed', 5000))->toBe(5000.0);
});

test('no charge, a zero charge and an empty value all come to nothing', function () {
    expect($this->service->serviceCharge(10000, null, null))->toBe(0.0)
        ->and($this->service->serviceCharge(10000, 'none', 10))->toBe(0.0)
        ->and($this->service->serviceCharge(10000, 'percent', 0))->toBe(0.0)
        ->and($this->service->serviceCharge(10000, 'percent', ''))->toBe(0.0)
        ->and($this->service->serviceCharge(10000, '', 10))->toBe(0.0);
});

test('a nonsensical service charge is refused rather than guessed at', function () {
    expect(fn () => $this->service->serviceCharge(10000, 'percent', 150))
        ->toThrow(DomainException::class, 'cannot exceed 100%');

    expect(fn () => $this->service->serviceCharge(10000, 'percent', -5))
        ->toThrow(DomainException::class, 'cannot be negative');

    expect(fn () => $this->service->serviceCharge(10000, 'double-it', 5))
        ->toThrow(DomainException::class, 'percentage or a fixed amount');
});

test('a percentage of an empty bill is nothing, not a negative', function () {
    expect($this->service->serviceCharge(0, 'percent', 10))->toBe(0.0);
});
