<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| The reports added on top of the original four
|--------------------------------------------------------------------------
|
| By cashier, by category, by product, stock valuation, receivables, payables
| and expiry - plus the CSV export every one of them carries, and the empty
| state every one of them has to render on a shop that has no data yet.
|
| The conventions under test throughout: revenue excludes tax and is net of
| refunds, cost comes from the snapshot taken at the moment of sale, a voided
| sale is not a transaction, and a service is never counted as stock.
|
*/

beforeEach(function () {
    $this->manager = User::factory()->manager()->create(['name' => 'Mariam Manager']);
    $this->actingAs($this->manager);
});

/** A sale with sensible defaults, timestamped where the test wants it. */
function reportSale(array $attrs = [], ?string $when = null, ?User $user = null): Sale
{
    $user ??= test()->manager;

    $sale = Sale::create(array_merge([
        'invoice_number' => 'INV-' . uniqid(),
        'user_id' => $user->id,
        'total_amount' => 1000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'grand_total' => 1000,
        'payment_method' => 'cash',
        'paid_amount' => 1000,
        'change_due' => 0,
        'sale_date' => $when ?? now()->toDateTimeString(),
        'status' => Sale::STATUS_COMPLETED,
    ], $attrs));

    if ($when) {
        $sale->forceFill(['created_at' => $when, 'updated_at' => $when])->save();
    }

    return $sale;
}

/** Parse a streamed CSV response into rows. */
function csvRows($response): array
{
    return array_map('str_getcsv', array_filter(explode("\n", trim($response->streamedContent()))));
}

/**
 * Switch the shop's trade. Named differently from ShellTest's own helper
 * because Pest loads every test file into one namespace.
 */
function reportsUseBusinessType(string $type): void
{
    \App\Models\Setting::updateOrCreate(['key' => 'business_type'], ['value' => $type]);
    \Illuminate\Support\Facades\Cache::forget('settings_cache');
}

/*
|--------------------------------------------------------------------------
| Sector awareness
|--------------------------------------------------------------------------
|
| A trade that keeps no stock must not be shown a stock figure, a reorder link
| or a valuation: the sidebar hides those screens for it, and a dashboard tile
| that links somewhere the nav refuses to go is worse than no tile at all.
|
*/

test('a trade that keeps no stock gets no stock tiles, and no link to the reorder list', function () {
    reportsUseBusinessType('services');

    $response = $this->get(route('dashboard'))->assertOk();

    $response->assertDontSee(route('reorder.index'))
        ->assertDontSee(route('reports.valuation'))
        ->assertDontSee('Low stock')
        ->assertDontSee('Running low')
        // Something it can act on takes the place of the stock tiles.
        ->assertSee('Takings this period');

    expect($response->viewData('lowStockCount'))->toBe(0)
        ->and($response->viewData('lowStock'))->toBeEmpty()
        ->and($response->viewData('stockAtCost'))->toBe(0.0);
});

test('a trade that keeps stock does get the stock tiles', function () {
    // Retail is the default and tracks stock.
    Product::factory()->create(['stock_quantity' => 1, 'alert_quantity' => 10]);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('reorder.index'), false)
        ->assertSee('Low stock');
});

test('stock valuation and stock movement explain themselves to a trade with no stock', function () {
    // "services" is the one preset in config/sectors.php with stock switched off;
    // a salon still sells products alongside its services, so it keeps stock.
    reportsUseBusinessType('services');

    foreach (['reports.valuation', 'reports.movement'] as $route) {
        $response = $this->get(route($route))->assertOk();

        expect($response->viewData('relevant'))->toBeFalse();
        $response->assertSee('Not relevant to this business type');
    }
});

/*
|--------------------------------------------------------------------------
| Empty states
|--------------------------------------------------------------------------
*/

test('every report renders an empty state on a shop with no data at all', function () {
    $pages = [
        'reports.cashiers' => 'Nothing rung up in this period',
        'reports.categories' => 'Nothing sold in this period',
        'reports.products' => 'Nothing sold in this period',
        'reports.valuation' => 'No stocked',
        'reports.receivables' => 'Nobody owes anything',
        'reports.payables' => 'Nothing owed to anyone',
        'reports.daily' => 'Nothing taken in this period',
        'reports.movement' => 'No stock movement in this period',
        'reports.sales' => 'in this period',
        'reports.profit_loss' => 'Nothing happened in this period',
    ];

    foreach ($pages as $route => $expected) {
        $this->get(route($route))->assertOk()->assertSee($expected, false);
    }
});

test('a report with rows but none inside the chosen range still renders its empty state', function () {
    $product = Product::factory()->create();
    $sale = reportSale([], '2026-01-15 10:00:00');
    $sale->items()->create([
        'product_id' => $product->id, 'quantity' => 1, 'price' => 100,
        'cost_price' => 60, 'total' => 100, 'paid_total' => 100,
    ]);

    // A range nowhere near the one sale.
    $range = ['start_date' => '2026-06-01', 'end_date' => '2026-06-30'];

    $this->get(route('reports.products', $range))->assertOk()->assertSee('Nothing sold in this period');
    $this->get(route('reports.categories', $range))->assertOk()->assertSee('Nothing sold in this period');
    $this->get(route('reports.cashiers', $range))->assertOk()->assertSee('Nothing rung up in this period');
});

/*
|--------------------------------------------------------------------------
| By cashier
|--------------------------------------------------------------------------
*/

test('takings by cashier are per person, net of refunds on their own sales, with discounts shown', function () {
    $ada = User::factory()->cashier()->create(['name' => 'Ada Cashier']);
    $bola = User::factory()->cashier()->create(['name' => 'Bola Cashier']);

    // Ada: 2 sales, one heavily discounted.
    reportSale(['grand_total' => 1000, 'total_amount' => 1000], null, $ada);
    reportSale(['grand_total' => 800, 'total_amount' => 1000, 'discount_amount' => 200], null, $ada);

    // Bola: one sale, 100 of which came back.
    $bolasSale = reportSale(['grand_total' => 500, 'total_amount' => 500], null, $bola);
    Refund::create([
        'sale_id' => $bolasSale->id, 'user_id' => $this->manager->id, 'total_amount' => 100,
        'tax_amount' => 0, 'method' => 'cash', 'reason' => 'One item back',
    ]);

    $response = $this->get(route('reports.cashiers'))->assertOk();
    $rows = $response->viewData('rows')->keyBy('name');

    expect($rows['Ada Cashier']['takings'])->toBe(1800.0)
        ->and($rows['Ada Cashier']['transactions'])->toBe(2)
        ->and($rows['Ada Cashier']['basket'])->toBe(900.0)
        ->and($rows['Ada Cashier']['discounts'])->toBe(200.0)
        // 200 off 2,000 of goods
        ->and($rows['Ada Cashier']['discount_rate'])->toBe(10.0);

    expect($rows['Bola Cashier']['takings'])->toBe(400.0)     // 500 less the 100 back
        ->and($rows['Bola Cashier']['refunded'])->toBe(100.0)
        // The refund was processed by the manager, not by Bola.
        ->and($rows['Bola Cashier']['refunds_count'])->toBe(0);

    expect($rows['Mariam Manager']['refunds_count'])->toBe(1)
        ->and($rows['Mariam Manager']['refunds_value'])->toBe(100.0);

    expect($response->viewData('totals')['takings'])->toBe(2200.0);
});

test('a voided sale shows as a void, not as a transaction, in the cashier report', function () {
    $ada = User::factory()->cashier()->create(['name' => 'Ada Cashier']);

    reportSale(['grand_total' => 600], null, $ada);
    reportSale(['grand_total' => 300, 'status' => Sale::STATUS_VOIDED], null, $ada);

    $rows = $this->get(route('reports.cashiers'))->assertOk()->viewData('rows')->keyBy('name');

    expect($rows['Ada Cashier']['transactions'])->toBe(1)
        ->and($rows['Ada Cashier']['voids'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| By category and by product
|--------------------------------------------------------------------------
*/

test('margin by category uses revenue excluding tax and the cost snapshotted at the sale', function () {
    $drinks = Category::factory()->create(['name' => 'Drinks']);
    $snacks = Category::factory()->create(['name' => 'Snacks']);

    $cola = Product::factory()->create(['name' => 'Cola', 'category_id' => $drinks->id, 'cost_price' => 60]);
    $crisps = Product::factory()->create(['name' => 'Crisps', 'category_id' => $snacks->id, 'cost_price' => 30]);

    // 1,000 of drinks with 75 tax on top, and 200 of snacks with 15 tax.
    $sale = reportSale(['total_amount' => 1200, 'tax_amount' => 90, 'grand_total' => 1290]);
    $sale->items()->create([
        'product_id' => $cola->id, 'quantity' => 10, 'price' => 100, 'cost_price' => 60,
        'total' => 1000, 'tax_amount' => 75, 'paid_total' => 1075,
    ]);
    $sale->items()->create([
        'product_id' => $crisps->id, 'quantity' => 4, 'price' => 50, 'cost_price' => 30,
        'total' => 200, 'tax_amount' => 15, 'paid_total' => 215,
    ]);

    $response = $this->get(route('reports.categories'))->assertOk();
    $rows = $response->viewData('rows')->keyBy('category');

    expect($rows['Drinks']['revenue'])->toBe(1000.0)       // 1,075 paid less 75 tax
        ->and($rows['Drinks']['cogs'])->toBe(600.0)
        ->and($rows['Drinks']['gross_profit'])->toBe(400.0)
        ->and($rows['Drinks']['margin'])->toBe(40.0);

    expect($rows['Snacks']['revenue'])->toBe(200.0)
        ->and($rows['Snacks']['gross_profit'])->toBe(80.0);

    // The total margin is computed from the totals, not averaged across rows.
    expect($response->viewData('totals'))->toMatchArray([
        'revenue' => 1200.0,
        'cogs' => 720.0,
        'gross_profit' => 480.0,
        'margin' => 40.0,
    ]);
});

test('re-costing a product does not move the margin on a sale already made', function () {
    $product = Product::factory()->create(['name' => 'Snapshot Widget', 'cost_price' => 40]);

    $sale = reportSale(['total_amount' => 500, 'grand_total' => 500]);
    $sale->items()->create([
        'product_id' => $product->id, 'quantity' => 5, 'price' => 100, 'cost_price' => 40,
        'total' => 500, 'paid_total' => 500,
    ]);

    $before = $this->get(route('reports.products'))->viewData('totals');

    // The supplier puts their price up; the product is re-costed today.
    $product->update(['cost_price' => 95]);

    $after = $this->get(route('reports.products'))->viewData('totals');

    expect($before['gross_profit'])->toBe(300.0)
        ->and($after['gross_profit'])->toBe(300.0)
        ->and($after['cogs'])->toBe(200.0);
});

test('margin by product can be sorted, and the sort is remembered', function () {
    $cheap = Product::factory()->create(['name' => 'Many Cheap']);
    $dear = Product::factory()->create(['name' => 'Few Dear']);

    $sale = reportSale(['total_amount' => 1300, 'grand_total' => 1300]);
    $sale->items()->create(['product_id' => $cheap->id, 'quantity' => 50, 'price' => 6, 'cost_price' => 5, 'total' => 300, 'paid_total' => 300]);
    $sale->items()->create(['product_id' => $dear->id, 'quantity' => 2, 'price' => 500, 'cost_price' => 100, 'total' => 1000, 'paid_total' => 1000]);

    expect($this->get(route('reports.products', ['sort' => 'quantity']))->viewData('rows')->first()['name'])
        ->toBe('Many Cheap');

    // No sort in the query string: the last one chosen is still in force.
    expect($this->get(route('reports.products'))->viewData('rows')->first()['name'])
        ->toBe('Many Cheap');

    expect($this->get(route('reports.products', ['sort' => 'gross_profit']))->viewData('rows')->first()['name'])
        ->toBe('Few Dear');

    $this->get(route('reports.products', ['sort' => 'nonsense']))->assertSessionHasErrors('sort');
});

test('a refund reverses revenue and, when the goods are restocked, the cost too', function () {
    $product = Product::factory()->create(['name' => 'Came Back']);

    $sale = reportSale(['total_amount' => 500, 'tax_amount' => 0, 'grand_total' => 500]);
    $item = $sale->items()->create([
        'product_id' => $product->id, 'quantity' => 5, 'price' => 100, 'cost_price' => 60,
        'total' => 500, 'paid_total' => 500,
    ]);

    $refund = Refund::create([
        'sale_id' => $sale->id, 'user_id' => $this->manager->id, 'total_amount' => 200,
        'tax_amount' => 0, 'method' => 'cash', 'reason' => 'Two back on the shelf',
    ]);

    RefundItem::create([
        'refund_id' => $refund->id, 'sale_item_id' => $item->id, 'product_id' => $product->id,
        'quantity' => 2, 'amount' => 200, 'tax_amount' => 0, 'cost_price' => 60, 'restocked' => true,
    ]);

    $row = $this->get(route('reports.products'))->viewData('rows')->first();

    expect($row['quantity'])->toBe(3)
        ->and($row['revenue'])->toBe(300.0)
        ->and($row['cogs'])->toBe(180.0);          // 3 x 60
});

test('goods written off instead of restocked keep their cost on the books', function () {
    $product = Product::factory()->create(['name' => 'Damaged Goods']);

    $sale = reportSale(['total_amount' => 500, 'grand_total' => 500]);
    $item = $sale->items()->create([
        'product_id' => $product->id, 'quantity' => 5, 'price' => 100, 'cost_price' => 60,
        'total' => 500, 'paid_total' => 500,
    ]);

    $refund = Refund::create([
        'sale_id' => $sale->id, 'user_id' => $this->manager->id, 'total_amount' => 200,
        'tax_amount' => 0, 'method' => 'cash', 'reason' => 'Arrived broken',
    ]);

    RefundItem::create([
        'refund_id' => $refund->id, 'sale_item_id' => $item->id, 'product_id' => $product->id,
        'quantity' => 2, 'amount' => 200, 'tax_amount' => 0, 'cost_price' => 60, 'restocked' => false,
    ]);

    $row = $this->get(route('reports.products'))->viewData('rows')->first();

    // Revenue goes back, but the 300 of cost stays: the goods are gone.
    expect($row['revenue'])->toBe(300.0)->and($row['cogs'])->toBe(300.0);
});

test('a report still names a product that was archived after it was sold', function () {
    $product = Product::factory()->create(['name' => 'Discontinued Line']);

    $sale = reportSale(['grand_total' => 100, 'total_amount' => 100]);
    $sale->items()->create([
        'product_id' => $product->id, 'quantity' => 1, 'price' => 100, 'cost_price' => 50,
        'total' => 100, 'paid_total' => 100,
    ]);

    $product->delete();

    $this->get(route('reports.products'))->assertOk()->assertSee('Discontinued Line');
});

/*
|--------------------------------------------------------------------------
| Stock valuation
|--------------------------------------------------------------------------
*/

test('a service is excluded from stock valuation, because it has no stock to value', function () {
    $goods = Category::factory()->create(['name' => 'Goods']);
    $labour = Category::factory()->create(['name' => 'Labour']);

    Product::factory()->create([
        'name' => 'Real Widget', 'category_id' => $goods->id,
        'stock_quantity' => 10, 'cost_price' => 40, 'sale_price' => 100,
    ]);

    // A haircut cannot run out, so valuing it would invent an asset.
    Product::factory()->create([
        'name' => 'A Haircut', 'category_id' => $labour->id, 'is_service' => true, 'track_stock' => false,
        'stock_quantity' => 999, 'cost_price' => 1000, 'sale_price' => 5000,
    ]);

    // Nor does an item that simply opts out of stock tracking.
    Product::factory()->create([
        'name' => 'Untracked Thing', 'category_id' => $goods->id, 'track_stock' => false,
        'stock_quantity' => 500, 'cost_price' => 10, 'sale_price' => 20,
    ]);

    $response = $this->get(route('reports.valuation'))->assertOk();

    expect($response->viewData('totals'))->toMatchArray([
        'products' => 1,
        'units' => 10,
        'cost' => 400.0,
        'retail' => 1000.0,
        'potential_profit' => 600.0,
        'margin' => 60.0,
    ]);

    expect($response->viewData('rows')->pluck('category')->all())->toBe(['Goods']);

    $response->assertSee('Real Widget')
        ->assertDontSee('A Haircut')
        ->assertDontSee('Untracked Thing');
});

test('the valuation detail list can be filtered by category, and remembers the choice', function () {
    $drinks = Category::factory()->create(['name' => 'Drinks']);
    $tools = Category::factory()->create(['name' => 'Tools']);

    Product::factory()->create(['name' => 'Bottled Water', 'category_id' => $drinks->id, 'stock_quantity' => 5]);
    Product::factory()->create(['name' => 'Claw Hammer', 'category_id' => $tools->id, 'stock_quantity' => 5]);

    $this->get(route('reports.valuation', ['category_id' => $drinks->id]))
        ->assertOk()
        ->assertSee('Bottled Water')
        ->assertDontSee('Claw Hammer');

    // Come back with no query string: the filter is still on.
    $this->get(route('reports.valuation'))->assertOk()->assertDontSee('Claw Hammer');

    // A blank choice clears it.
    $this->get(route('reports.valuation', ['category_id' => '']))->assertOk()->assertSee('Claw Hammer');
});

/*
|--------------------------------------------------------------------------
| Receivables
|--------------------------------------------------------------------------
*/

test('receivables ageing puts a 45-day-old debt in the 31-60 band', function () {
    $customer = Customer::factory()->create(['name' => 'Slow Payer']);

    reportSale([
        'customer_id' => $customer->id, 'grand_total' => 900, 'paid_amount' => 0,
        'balance_due' => 900, 'payment_status' => Sale::PAYMENT_UNPAID,
        'due_date' => now()->subDays(45)->toDateString(),
    ], now()->subDays(45)->toDateTimeString());

    $response = $this->get(route('reports.receivables'))->assertOk();
    $row = $response->viewData('rows')->first();

    expect($row['buckets'])->toMatchArray([
        'current' => 0.0,
        '1_30' => 0.0,
        '31_60' => 900.0,
        'over_60' => 0.0,
    ]);

    expect($row['owed'])->toBe(900.0)->and($row['overdue'])->toBeTrue();
});

test('receivables ageing sorts every band, and totals them', function () {
    $customer = Customer::factory()->create(['name' => 'Mixed Bag']);

    $owing = fn(int $daysOverdue, float $amount) => reportSale([
        'customer_id' => $customer->id, 'grand_total' => $amount, 'paid_amount' => 0,
        'balance_due' => $amount, 'payment_status' => Sale::PAYMENT_UNPAID,
        'due_date' => now()->subDays($daysOverdue)->toDateString(),
    ], now()->subDays(max($daysOverdue, 0))->toDateTimeString());

    $owing(-10, 100);    // falls due in ten days
    $owing(5, 200);
    $owing(40, 300);
    $owing(200, 400);

    $totals = $this->get(route('reports.receivables'))->assertOk()->viewData('totals');

    expect($totals)->toMatchArray([
        'owed' => 1000.0,
        'current' => 100.0,
        '1_30' => 200.0,
        '31_60' => 300.0,
        'over_60' => 400.0,
    ]);
});

test('an invoice with no due date is aged from the day it was raised, not parked in "not yet due"', function () {
    $customer = Customer::factory()->create();

    reportSale([
        'customer_id' => $customer->id, 'grand_total' => 500, 'paid_amount' => 0,
        'balance_due' => 500, 'payment_status' => Sale::PAYMENT_UNPAID,
        'due_date' => null,
    ], now()->subDays(45)->toDateTimeString());

    $totals = $this->get(route('reports.receivables'))->viewData('totals');

    expect($totals['31_60'])->toBe(500.0)->and($totals['current'])->toBe(0.0);
});

test('a paid-off and a voided credit sale both drop out of receivables', function () {
    $customer = Customer::factory()->create();

    reportSale(['customer_id' => $customer->id, 'grand_total' => 500, 'balance_due' => 0, 'payment_status' => Sale::PAYMENT_PAID]);
    reportSale([
        'customer_id' => $customer->id, 'grand_total' => 700, 'balance_due' => 700,
        'payment_status' => Sale::PAYMENT_UNPAID, 'status' => Sale::STATUS_VOIDED,
    ]);

    $this->get(route('reports.receivables'))
        ->assertOk()
        ->assertSee('Nobody owes anything');
});

test('receivables can be narrowed to the overdue, and the choice is remembered', function () {
    $late = Customer::factory()->create(['name' => 'Late Larry']);
    $ontime = Customer::factory()->create(['name' => 'Prompt Pat']);

    reportSale([
        'customer_id' => $late->id, 'grand_total' => 400, 'balance_due' => 400,
        'payment_status' => Sale::PAYMENT_UNPAID, 'due_date' => now()->subWeek()->toDateString(),
    ]);
    reportSale([
        'customer_id' => $ontime->id, 'grand_total' => 400, 'balance_due' => 400,
        'payment_status' => Sale::PAYMENT_UNPAID, 'due_date' => now()->addWeek()->toDateString(),
    ]);

    $this->get(route('reports.receivables', ['overdue_only' => '1']))
        ->assertOk()->assertSee('Late Larry')->assertDontSee('Prompt Pat');

    $this->get(route('reports.receivables'))->assertOk()->assertDontSee('Prompt Pat');
    $this->get(route('reports.receivables', ['overdue_only' => '0']))->assertOk()->assertSee('Prompt Pat');
});

/*
|--------------------------------------------------------------------------
| Payables
|--------------------------------------------------------------------------
*/

test('payables uses the supplier ledger balance and shows orders still to arrive separately', function () {
    $supplier = Supplier::factory()->create(['name' => 'Acme Wholesale', 'opening_balance' => 1000]);

    // Delivered and part-paid: 2,000 invoiced, 500 handed over.
    Purchase::factory()->create([
        'supplier_id' => $supplier->id, 'user_id' => $this->manager->id,
        'total_amount' => 2000, 'amount_paid' => 500, 'balance_due' => 1500,
        'purchase_date' => now()->subDays(40),
    ]);

    // Raised but not delivered: not a debt yet.
    Purchase::factory()->ordered()->create([
        'supplier_id' => $supplier->id, 'user_id' => $this->manager->id, 'total_amount' => 777,
    ]);

    SupplierPayment::factory()->create([
        'supplier_id' => $supplier->id, 'user_id' => $this->manager->id, 'amount' => 300,
    ]);

    $response = $this->get(route('reports.payables'))->assertOk();
    $row = $response->viewData('rows')->first();

    // 1,000 opening + 2,000 invoiced - 500 settled - 300 paid
    expect($row['balance'])->toBe(2200.0)
        ->and($row['on_order'])->toBe(777.0)
        // 1,500 unpaid on a delivery 40 days old
        ->and($row['buckets']['31_60'])->toBe(1500.0);

    $response->assertSee('Acme Wholesale');
});

test('a supplier with nothing owing and nothing on order is left off the payables page', function () {
    Supplier::factory()->create(['name' => 'Settled Supplies']);
    $owed = Supplier::factory()->create(['name' => 'Still Owed', 'opening_balance' => 50]);

    $response = $this->get(route('reports.payables'))->assertOk();

    expect($response->viewData('rows')->pluck('supplier.name')->all())->toBe(['Still Owed']);
    $response->assertDontSee('Settled Supplies');
});

test('a supplier paid more than they invoiced shows as in credit rather than as a negative debt', function () {
    $supplier = Supplier::factory()->create(['name' => 'Overpaid Ltd']);

    // Settled in full on delivery. amount_paid is set here rather than via the
    // factory's paid() state, which reads the definition's random total before
    // create()'s own override is merged in.
    Purchase::factory()->create([
        'supplier_id' => $supplier->id, 'user_id' => $this->manager->id,
        'total_amount' => 400, 'amount_paid' => 400, 'balance_due' => 0,
    ]);
    SupplierPayment::factory()->create([
        'supplier_id' => $supplier->id, 'user_id' => $this->manager->id, 'amount' => 150,
    ]);

    $response = $this->get(route('reports.payables'))->assertOk();

    expect($response->viewData('rows')->first()['balance'])->toBe(-150.0);
    $response->assertSee('in credit');
});

/*
|--------------------------------------------------------------------------
| Expiry
|--------------------------------------------------------------------------
*/

test('the expiry report explains itself instead of 404ing when the trade has no shelf life', function () {
    // Retail is the default business type, and it does not track expiry.
    $response = $this->get(route('reports.expiry'))->assertOk();

    expect($response->viewData('relevant'))->toBeFalse();
    $response->assertSee('Not relevant to this business type');
});

test('the expiry report bands stock by how soon it goes off, with the value at risk', function () {
    \App\Models\Setting::updateOrCreate(['key' => 'feature_expiry'], ['value' => '1']);
    \Illuminate\Support\Facades\Cache::forget('settings_cache');

    $make = fn(string $name, int $days, int $qty, float $cost) => Product::factory()->create([
        'name' => $name,
        'expiry_date' => now()->addDays($days)->toDateString(),
        'stock_quantity' => $qty,
        'cost_price' => $cost,
    ]);

    $make('Gone Off Milk', -3, 4, 50);      // expired
    $make('Friday Bread', 5, 10, 20);       // within a week
    $make('Month Yoghurt', 20, 2, 100);     // 8-30 days
    $make('Next Season', 120, 50, 10);      // outside a 30-day window

    // Something expired with nothing left on the shelf is not at risk.
    $make('Already Sold Out', -10, 0, 500);

    $response = $this->get(route('reports.expiry'))->assertOk();
    $buckets = $response->viewData('buckets');

    expect($buckets['expired']['items'])->toBe(1)
        ->and($buckets['expired']['value'])->toBe(200.0)
        ->and($buckets['week']['items'])->toBe(1)
        ->and($buckets['week']['value'])->toBe(200.0)
        ->and($buckets['month']['items'])->toBe(1)
        ->and($buckets['month']['value'])->toBe(200.0)
        ->and($response->viewData('totals')['value'])->toBe(600.0);

    $response->assertSee('Gone Off Milk')
        ->assertDontSee('Next Season')
        ->assertDontSee('Already Sold Out');

    // A wider window reaches it, and the window is remembered.
    expect($this->get(route('reports.expiry', ['days' => 200]))->viewData('totals')['items'])->toBe(4);
    expect($this->get(route('reports.expiry'))->viewData('days'))->toBe(200);

    $this->get(route('reports.expiry', ['days' => 5000]))->assertSessionHasErrors('days');
});

/*
|--------------------------------------------------------------------------
| Stock movement filters
|--------------------------------------------------------------------------
*/

test('stock movement can be filtered by product, direction and cause', function () {
    $widget = Product::factory()->create(['name' => 'Filter Widget']);
    $other = Product::factory()->create(['name' => 'Other Widget']);

    $log = fn(Product $p, string $type, int $qty, string $reason) => InventoryLog::create([
        'product_id' => $p->id, 'user_id' => $this->manager->id,
        'type' => $type, 'quantity' => $qty, 'reason' => $reason,
    ]);

    $log($widget, 'IN', 20, 'Purchase PO-1');
    $log($widget, 'OUT', 3, 'Sale INV-1');
    $log($widget, 'OUT', 2, 'Adjustment: damage - dropped a crate');
    $log($widget, 'OUT', 1, 'Adjustment: theft - gone from the shelf');
    $log($other, 'IN', 99, 'Purchase PO-2');

    // By product. Asserted on the reasons rather than the names, because every
    // product name is also in the filter's own dropdown.
    $this->get(route('reports.movement', ['product_id' => $widget->id]))
        ->assertOk()->assertSee('Purchase PO-1')->assertDontSee('Purchase PO-2');

    // By direction, within that product (the filter is remembered)
    $this->get(route('reports.movement', ['type' => 'IN']))
        ->assertOk()->assertSee('Purchase PO-1')->assertDontSee('Sale INV-1');

    // By cause: the structured reason a stock adjustment now carries
    $this->get(route('reports.movement', ['type' => '', 'source' => 'damage']))
        ->assertOk()->assertSee('dropped a crate')->assertDontSee('gone from the shelf');

    $this->get(route('reports.movement', ['source' => 'adjustment']))
        ->assertOk()->assertSee('dropped a crate')->assertSee('gone from the shelf');

    $this->get(route('reports.movement', ['source' => 'not-a-cause']))->assertSessionHasErrors('source');
});

test('the movement chart splits units in from units out', function () {
    $product = Product::factory()->create();

    InventoryLog::create(['product_id' => $product->id, 'user_id' => $this->manager->id, 'type' => 'IN', 'quantity' => 30, 'reason' => 'Purchase PO-1']);
    InventoryLog::create(['product_id' => $product->id, 'user_id' => $this->manager->id, 'type' => 'OUT', 'quantity' => 12, 'reason' => 'Sale INV-1']);

    $flow = $this->get(route('reports.movement'))->assertOk()->viewData('flow');

    expect(array_sum($flow['in']))->toBe(30)
        // Out is drawn below the baseline, so it is carried as a negative.
        ->and(array_sum($flow['out']))->toBe(-12)
        ->and($flow['labels'])->toHaveCount(count($flow['in']));
});

/*
|--------------------------------------------------------------------------
| The daily summary and its shifts
|--------------------------------------------------------------------------
*/

test('the daily summary lists the shifts for the period, summarised by ShiftService', function () {
    $cashier = User::factory()->cashier()->create(['name' => 'Zed Cashier']);

    $shift = Shift::factory()->closed(['cash' => 6200])->create([
        'user_id' => $cashier->id,
        'opening_float' => 5000,
        'counted_cash' => 6150,     // 50 short
    ]);

    reportSale(['grand_total' => 1200, 'paid_amount' => 1200, 'shift_id' => $shift->id], null, $cashier);

    $response = $this->get(route('reports.daily'))->assertOk();
    $rows = $response->viewData('shifts');

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['summary']['expected_total'])->toBe(6200.0)
        ->and($rows->first()['summary']['counted_total'])->toBe(6150.0)
        ->and($rows->first()['summary']['variance_total'])->toBe(-50.0);

    $response->assertSee('Zed Cashier')->assertSee('short');
});

test('the daily summary keeps its original today and this-month figures', function () {
    reportSale(['grand_total' => 250]);
    reportSale(['grand_total' => 9000], now()->subYear()->toDateTimeString());

    $data = $this->get(route('reports.daily'))->assertOk()->viewData('data');

    expect((float) $data['sales_today'])->toBe(250.0)
        ->and((float) $data['sales_this_month'])->toBe(250.0)
        ->and($data['transactions_today'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| CSV exports
|--------------------------------------------------------------------------
*/

test('every report exports a streamed CSV with a sane header row', function () {
    // Enough data that each export has at least one row to write.
    $category = Category::factory()->create(['name' => 'Exportables']);
    $product = Product::factory()->create([
        'name' => 'Export Widget', 'category_id' => $category->id,
        'stock_quantity' => 7, 'cost_price' => 20, 'sale_price' => 50,
        'expiry_date' => now()->addDays(10)->toDateString(),
    ]);

    $customer = Customer::factory()->create(['name' => 'Owing Olu']);
    $supplier = Supplier::factory()->create(['name' => 'Owed Oyo', 'opening_balance' => 500]);

    $sale = reportSale([
        'customer_id' => $customer->id, 'total_amount' => 500, 'grand_total' => 500,
        'paid_amount' => 100, 'balance_due' => 400, 'payment_status' => Sale::PAYMENT_PARTIAL,
    ]);
    $sale->items()->create([
        'product_id' => $product->id, 'quantity' => 10, 'price' => 50, 'cost_price' => 20,
        'total' => 500, 'paid_total' => 500,
    ]);

    InventoryLog::create([
        'product_id' => $product->id, 'user_id' => $this->manager->id,
        'type' => 'OUT', 'quantity' => 10, 'reason' => 'Sale ' . $sale->invoice_number,
    ]);

    \App\Models\Setting::updateOrCreate(['key' => 'feature_expiry'], ['value' => '1']);
    \Illuminate\Support\Facades\Cache::forget('settings_cache');

    $exports = [
        'reports.cashiers' => 'Takings',
        'reports.categories' => 'Revenue',
        'reports.products' => 'Revenue',
        'reports.valuation' => 'Value at cost',
        'reports.receivables' => 'Owed',
        'reports.payables' => 'Balance owed',
        'reports.expiry' => 'Value at risk',
        'reports.movement' => 'Reason',
        'reports.daily' => 'Takings',
        'reports.profit_loss' => 'Net profit',
    ];

    foreach ($exports as $route => $expectedColumn) {
        $response = $this->get(route($route, ['export' => 'csv']))->assertOk();

        expect($response->headers->get('content-type'))->toContain('text/csv')
            ->and($response->headers->get('content-disposition'))->toContain('attachment');

        $rows = csvRows($response);

        // toContain() is variadic in Pest, so it takes needles rather than a message.
        expect($rows[0])->toContain($expectedColumn)
            ->and(count($rows))->toBeGreaterThan(1, "{$route} CSV should have at least one data row");

        // Never a formatted money string in a CSV cell: a spreadsheet cannot add
        // up "<symbol>1,200.00".
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                expect($cell)->not->toContain(currency());
            }
        }
    }
});

test('a CSV export guards against a product name a spreadsheet would run as a formula', function () {
    $product = Product::factory()->create(['name' => '=1+1 Dodgy Widget', 'stock_quantity' => 3]);

    $sale = reportSale(['total_amount' => 100, 'grand_total' => 100]);
    $sale->items()->create([
        'product_id' => $product->id, 'quantity' => 1, 'price' => 100, 'cost_price' => 10,
        'total' => 100, 'paid_total' => 100,
    ]);

    $rows = csvRows($this->get(route('reports.products', ['export' => 'csv']))->assertOk());

    // Prefixed with an apostrophe, so the spreadsheet treats it as text.
    expect($rows[1][0])->toBe("'=1+1 Dodgy Widget");
});

/*
|--------------------------------------------------------------------------
| Role gating
|--------------------------------------------------------------------------
*/

test('a cashier cannot reach any of the new reports', function () {
    $this->actingAs(User::factory()->cashier()->create());

    foreach ([
        'reports.cashiers', 'reports.categories', 'reports.products',
        'reports.valuation', 'reports.receivables', 'reports.payables', 'reports.expiry',
    ] as $route) {
        $this->get(route($route))->assertForbidden();
        $this->get(route($route, ['export' => 'csv']))->assertForbidden();
    }
});

test('managers can reach all of the new reports', function () {
    foreach ([
        'reports.cashiers', 'reports.categories', 'reports.products',
        'reports.valuation', 'reports.receivables', 'reports.payables', 'reports.expiry',
    ] as $route) {
        $this->get(route($route))->assertOk();
    }
});

/*
|--------------------------------------------------------------------------
| Cost
|--------------------------------------------------------------------------
*/

test('the new reports run a fixed number of queries however many rows there are', function () {
    $category = Category::factory()->create();

    // Twenty products across ten sales: enough that an N+1 would show up loudly.
    $products = Product::factory()->count(20)->create(['category_id' => $category->id, 'stock_quantity' => 10]);

    for ($i = 0; $i < 10; $i++) {
        $sale = reportSale(['total_amount' => 1000, 'grand_total' => 1000]);

        foreach ($products as $product) {
            $sale->items()->create([
                'product_id' => $product->id, 'quantity' => 2, 'price' => 50,
                'cost_price' => 20, 'total' => 100, 'paid_total' => 100,
            ]);
        }
    }

    foreach (['reports.products', 'reports.categories', 'reports.cashiers', 'reports.valuation'] as $route) {
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $this->get(route($route))->assertOk();
        $count = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        expect($count)->toBeLessThan(25, "{$route} ran {$count} queries - something is N+1");
    }
});
