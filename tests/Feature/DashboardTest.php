<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| The dashboard
|--------------------------------------------------------------------------
|
| Two things are being protected here above all else:
|
|   - it renders on a completely empty database. A shop's first morning is the
|     one time every average has a zero denominator and every panel has nothing
|     to show, and it is also the first impression the product ever makes.
|
|   - a cashier cannot see profit, margin, or anyone else's takings. The route
|     is open to all roles, so the gate is in the controller rather than in
|     middleware, which means it needs a test rather than a code review.
|
*/

/** A sale with sensible defaults, timestamped where the test wants it. */
function dashSale(User $user, array $attrs = [], ?string $when = null): Sale
{
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

test('the dashboard renders on a completely empty database without dividing by zero', function () {
    // No products, no sales, no customers, no shifts, no settings: the state a
    // shop is in the first time it signs in.
    $this->actingAs(User::factory()->manager()->create());

    $response = $this->get(route('dashboard'))->assertOk();

    expect($response->viewData('today'))->toMatchArray([
        'takings' => 0.0,
        'transactions' => 0,
        'basket' => 0.0,
    ]);

    expect($response->viewData('profit')['margin'])->toBe(0.0)
        ->and($response->viewData('periodTotal'))->toBe(0.0)
        ->and($response->viewData('lowStockCount'))->toBe(0);

    // The empty states, not a wall of zeroes with no explanation.
    $response->assertSee('No sales in this period')
        ->assertSee('Nothing is running low');
});

test('an empty dashboard renders for a cashier too', function () {
    $this->actingAs(User::factory()->cashier()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('No shift open')
        ->assertSee('No sales yet today');
});

test('takings today are net of refunds, and the average basket divides by real transactions', function () {
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);

    dashSale($manager, ['grand_total' => 1000]);
    $second = dashSale($manager, ['grand_total' => 500]);

    Refund::create([
        'sale_id' => $second->id,
        'user_id' => $manager->id,
        'total_amount' => 200,
        'tax_amount' => 0,
        'method' => 'cash',
        'reason' => 'Changed their mind',
    ]);

    $today = $this->get(route('dashboard'))->assertOk()->viewData('today');

    expect($today['takings'])->toBe(1300.0)        // 1500 taken, 200 handed back
        ->and($today['transactions'])->toBe(2)
        ->and($today['basket'])->toBe(650.0);      // 1300 / 2
});

test('a voided sale is not counted as a transaction and nets out of takings', function () {
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);

    dashSale($manager, ['grand_total' => 1000]);
    $voided = dashSale($manager, ['grand_total' => 400, 'status' => Sale::STATUS_VOIDED]);

    // A void writes a refund for the whole sale, which is what cancels it.
    Refund::create([
        'sale_id' => $voided->id,
        'user_id' => $manager->id,
        'total_amount' => 400,
        'tax_amount' => 0,
        'method' => 'cash',
        'reason' => 'Rung up twice',
        'is_void' => true,
    ]);

    $today = $this->get(route('dashboard'))->assertOk()->viewData('today');

    expect($today['transactions'])->toBe(1)
        ->and($today['takings'])->toBe(1000.0);
});

test('gross profit on the dashboard excludes tax and uses the cost snapshotted at the sale', function () {
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);

    $product = Product::factory()->create(['cost_price' => 40, 'sale_price' => 100]);

    // 10 x 100 = 1000 of goods, plus 75 of tax on top.
    $sale = dashSale($manager, ['total_amount' => 1000, 'tax_amount' => 75, 'grand_total' => 1075]);
    $sale->items()->create([
        'product_id' => $product->id,
        'quantity' => 10,
        'price' => 100,
        'cost_price' => 40,
        'total' => 1000,
        'tax_amount' => 75,
        'paid_total' => 1075,
    ]);

    $profit = $this->get(route('dashboard'))->assertOk()->viewData('profit');

    expect($profit['revenue'])->toBe(1000.0)       // tax excluded
        ->and($profit['cogs'])->toBe(400.0)        // 10 x 40, the snapshotted cost
        ->and($profit['gross_profit'])->toBe(600.0)
        ->and($profit['margin'])->toBe(60.0);

    // Re-cost the product today; the sale already made must not move.
    $product->update(['cost_price' => 90]);

    expect($this->get(route('dashboard'))->viewData('profit')['gross_profit'])->toBe(600.0);
});

test('a cashier sees their own takings and no-one else, and never profit or margin', function () {
    $cashier = User::factory()->cashier()->create(['name' => 'Ada Cashier']);
    $other = User::factory()->cashier()->create(['name' => 'Bola Cashier']);

    dashSale($cashier, ['grand_total' => 700]);
    dashSale($other, ['grand_total' => 9000, 'invoice_number' => 'SOMEONE-ELSE']);

    $response = $this->actingAs($cashier)->get(route('dashboard'))->assertOk();

    expect($response->viewData('role'))->toBe('cashier')
        ->and($response->viewData('today')['takings'])->toBe(700.0);

    // No whole-shop figures reach the view at all, so none can leak into the page.
    $passed = array_keys($response->original->getData());

    foreach (['profit', 'owed', 'topProfit', 'topQuantity', 'methods', 'openShifts', 'lowStockCount'] as $key) {
        expect($passed)->not->toContain($key);
    }

    $response->assertDontSee('Gross profit')
        ->assertDontSee('margin')
        ->assertDontSee('Owed by')
        ->assertDontSee('SOMEONE-ELSE');
});

test('a cashier only sees their own sales from today', function () {
    $cashier = User::factory()->cashier()->create();

    dashSale($cashier, ['invoice_number' => 'TODAY-MINE']);
    dashSale($cashier, ['invoice_number' => 'YESTERDAY-MINE'], now()->subDay()->toDateTimeString());

    $this->actingAs($cashier)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('TODAY-MINE')
        ->assertDontSee('YESTERDAY-MINE');
});

test('the cashier dashboard shows their open drawer, summarised by ShiftService', function () {
    $cashier = User::factory()->cashier()->create();
    $shift = Shift::factory()->open()->create(['user_id' => $cashier->id, 'opening_float' => 5000]);

    dashSale($cashier, ['grand_total' => 1200, 'paid_amount' => 1500, 'change_due' => 300, 'shift_id' => $shift->id]);

    $response = $this->actingAs($cashier)->get(route('dashboard'))->assertOk();

    expect($response->viewData('shift')->id)->toBe($shift->id)
        // float 5000 + the 1200 that stayed in the drawer
        ->and($response->viewData('shiftSummary')['methods']['cash']['expected'])->toBe(6200.0);

    $response->assertSee('Cash expected in the drawer');
});

test('the manager dashboard compares the period with the one before it', function () {
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);

    // Inside a 7-day window, and inside the 7 days before it.
    dashSale($manager, ['grand_total' => 300], now()->subDays(2)->toDateTimeString());
    dashSale($manager, ['grand_total' => 100], now()->subDays(9)->toDateTimeString());

    $response = $this->get(route('dashboard', ['days' => 7]))->assertOk();

    expect($response->viewData('periodTotal'))->toBe(300.0)
        ->and($response->viewData('previousTotal'))->toBe(100.0)
        ->and($response->viewData('trend')['labels'])->toHaveCount(7)
        ->and($response->viewData('previousValues'))->toHaveCount(7);
});

test('the trend window is limited to the offered periods', function () {
    $this->actingAs(User::factory()->manager()->create());

    $this->get(route('dashboard', ['days' => 3650]))->assertSessionHasErrors('days');
    $this->get(route('dashboard', ['days' => 90]))->assertOk();
});

test('today by payment method falls back to sales.payment_method while sale_payments does not exist', function () {
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);

    // paid 1200, 200 change: 1000 actually stayed in the drawer.
    dashSale($manager, ['payment_method' => 'cash', 'grand_total' => 1000, 'paid_amount' => 1200, 'change_due' => 200]);
    dashSale($manager, ['payment_method' => 'transfer', 'grand_total' => 450, 'paid_amount' => 450]);

    $methods = $this->get(route('dashboard'))->assertOk()->viewData('methods');

    expect($methods['cash']['taken'])->toBe(1000.0)
        ->and($methods['transfer']['taken'])->toBe(450.0)
        ->and($methods['pos']['taken'])->toBe(0.0);
});

test('the top sellers panel ranks by quantity and by profit', function () {
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);

    $category = Category::factory()->create(['name' => 'Drinks']);
    $bulk = Product::factory()->create(['name' => 'Cheap Sachet', 'category_id' => $category->id]);
    $rich = Product::factory()->create(['name' => 'Fine Bottle', 'category_id' => $category->id]);

    $sale = dashSale($manager, ['total_amount' => 1300, 'grand_total' => 1300]);
    // Lots of units, almost no margin.
    $sale->items()->create(['product_id' => $bulk->id, 'quantity' => 50, 'price' => 6, 'cost_price' => 5, 'total' => 300, 'paid_total' => 300]);
    // Few units, all margin.
    $sale->items()->create(['product_id' => $rich->id, 'quantity' => 2, 'price' => 500, 'cost_price' => 100, 'total' => 1000, 'paid_total' => 1000]);

    $response = $this->get(route('dashboard'))->assertOk();

    expect($response->viewData('topQuantity')->first()['name'])->toBe('Cheap Sachet')
        ->and($response->viewData('topProfit')->first()['name'])->toBe('Fine Bottle')
        ->and($response->viewData('topProfit')->first()['gross_profit'])->toBe(800.0);
});

test('a product refunded after its sale reduces what it is credited with selling', function () {
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);

    $product = Product::factory()->create(['name' => 'Returned Thing']);

    $sale = dashSale($manager, ['total_amount' => 500, 'grand_total' => 500]);
    $item = $sale->items()->create([
        'product_id' => $product->id, 'quantity' => 5, 'price' => 100,
        'cost_price' => 60, 'total' => 500, 'paid_total' => 500,
    ]);

    $refund = Refund::create([
        'sale_id' => $sale->id, 'user_id' => $manager->id, 'total_amount' => 200,
        'tax_amount' => 0, 'method' => 'cash', 'reason' => 'Two came back',
    ]);
    RefundItem::create([
        'refund_id' => $refund->id, 'sale_item_id' => $item->id, 'product_id' => $product->id,
        'quantity' => 2, 'amount' => 200, 'tax_amount' => 0, 'cost_price' => 60, 'restocked' => true,
    ]);

    $top = $this->get(route('dashboard'))->assertOk()->viewData('topQuantity')->first();

    expect($top['quantity'])->toBe(3)             // 5 sold, 2 back
        ->and($top['revenue'])->toBe(300.0)
        ->and($top['cogs'])->toBe(180.0);         // 3 x 60: the restocked pair is no longer a cost
});

test('the owed tile counts outstanding balances and flags the overdue part', function () {
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);

    $customer = Customer::factory()->create();

    dashSale($manager, [
        'customer_id' => $customer->id, 'grand_total' => 1000, 'paid_amount' => 200,
        'balance_due' => 800, 'payment_status' => Sale::PAYMENT_PARTIAL,
        'due_date' => now()->subDays(10)->toDateString(),
    ]);

    dashSale($manager, [
        'customer_id' => $customer->id, 'grand_total' => 500, 'paid_amount' => 0,
        'balance_due' => 500, 'payment_status' => Sale::PAYMENT_UNPAID,
        'due_date' => now()->addDays(10)->toDateString(),
    ]);

    $owed = $this->get(route('dashboard'))->assertOk()->viewData('owed');

    expect($owed['owed'])->toBe(1300.0)
        ->and($owed['overdue'])->toBe(800.0)
        ->and($owed['customers'])->toBe(1);
});

test('low stock on the dashboard ignores services, which have no stock to run out of', function () {
    $this->actingAs(User::factory()->manager()->create());

    Product::factory()->create(['name' => 'Low Widget', 'stock_quantity' => 1, 'alert_quantity' => 10]);
    Product::factory()->create([
        'name' => 'A Haircut', 'is_service' => true, 'track_stock' => false,
        'stock_quantity' => 0, 'alert_quantity' => 10,
    ]);

    $response = $this->get(route('dashboard'))->assertOk();

    expect($response->viewData('lowStockCount'))->toBe(1);
    $response->assertSee('Low Widget')->assertDontSee('A Haircut');
});
