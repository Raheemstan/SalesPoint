<?php

use App\Models\Customer;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| What checkout now integrates with
|--------------------------------------------------------------------------
|
| Three things the till hands off to someone else, kept together because they
| are all "checkout asks another part of the system and does as it is told":
|
|   - CreditService  - selling on credit, and what is left owing
|   - ShiftService   - which drawer a sale belongs to, and whether one is needed
|   - Product        - an item with no stock must never be blocked for being out of it
*/

beforeEach(function () {
    $this->cashier = User::factory()->cashier()->create();
    $this->actingAs($this->cashier);
    $this->customer = Customer::factory()->create(['name' => 'Ada Trading Co']);
    // 500 each, so a cart of 4 comes to 2,000.
    $this->product = Product::factory()->create(['sale_price' => 500, 'stock_quantity' => 50]);
});

/** Ring up $qty of the product, tendering $tendered, on $customer's account. */
function tillCredit(int $qty, float $tendered, array $overrides = [])
{
    return checkout(cartOf([[test()->product, $qty]]), array_merge([
        'paid_amount' => $tendered,
        'customer_id' => test()->customer->id,
        'on_credit' => 1,
    ], $overrides));
}

// ---- credit ----

test('a sale taken wholly on account records the balance and reads as unpaid', function () {
    tillCredit(4, 0)->assertSessionMissing('error');

    $sale = Sale::sole();

    expect((float) $sale->grand_total)->toBe(2000.0)
        ->and((float) $sale->paid_amount)->toBe(0.0)
        ->and((float) $sale->balance_due)->toBe(2000.0)
        ->and($sale->payment_status)->toBe(Sale::PAYMENT_UNPAID)
        ->and($sale->customer_id)->toBe($this->customer->id)
        ->and($sale->isCredit())->toBeTrue()
        // No money arrived, so there is no tender row to pretend otherwise.
        ->and(SalePayment::where('sale_id', $sale->id)->count())->toBe(0);

    expect($this->customer->fresh()->outstanding)->toBe(2000.0);
});

test('a part payment records the deposit and leaves the rest owing', function () {
    tillCredit(4, 750)->assertSessionMissing('error');

    $sale = Sale::sole();

    expect((float) $sale->paid_amount)->toBe(750.0)
        ->and((float) $sale->balance_due)->toBe(1250.0)
        ->and($sale->payment_status)->toBe(Sale::PAYMENT_PARTIAL)
        ->and((float) $sale->change_due)->toBe(0.0);

    expect(SalePayment::where('sale_id', $sale->id)->sum('amount'))->toEqual(750.0);
    expect($this->customer->fresh()->outstanding)->toBe(1250.0);
});

test('a credit sale has no change, even when the flag is left on and more is tendered', function () {
    // 2,000 sale, 2,500 tendered, on_credit still ticked: the extra is change,
    // never a credit balance, and the sale is simply paid.
    tillCredit(4, 2500)->assertSessionMissing('error');

    $sale = Sale::sole();

    expect((float) $sale->change_due)->toBe(500.0)
        ->and((float) $sale->paid_amount)->toBe(2000.0)
        ->and((float) $sale->balance_due)->toBe(0.0)
        ->and($sale->payment_status)->toBe(Sale::PAYMENT_PAID)
        ->and($sale->isCredit())->toBeFalse();
});

test('stock still moves on a credit sale: the goods left the shelf whether or not the money arrived', function () {
    tillCredit(4, 0)->assertSessionMissing('error');

    expect($this->product->fresh()->stock_quantity)->toBe(46)
        ->and(InventoryLog::where('type', 'OUT')->where('product_id', $this->product->id)->value('quantity'))->toBe(4);
});

test('a short payment with no pay-later asked for is refused, as it always was', function () {
    checkout(cartOf([[$this->product, 4]]), [
        'paid_amount' => 500,
        'customer_id' => $this->customer->id,
    ])->assertSessionHas('error', 'Insufficient payment.');

    expect(Sale::count())->toBe(0)->and($this->product->fresh()->stock_quantity)->toBe(50);
});

test('pay-later with nobody to bill is refused, and says to choose someone', function () {
    checkout(cartOf([[$this->product, 4]]), [
        'paid_amount' => 500,
        'on_credit' => 1,
    ])->assertSessionHas('error');

    expect(session('error'))->toContain('before putting part of a sale on credit');
    expect(Sale::count())->toBe(0)->and($this->product->fresh()->stock_quantity)->toBe(50);
});

test('a part payment over the credit limit is refused in the service\'s own words', function () {
    $limited = Customer::factory()->limit(1000)->create(['name' => 'Tight Limit Ltd']);

    // 2,000 sale with a 500 deposit leaves 1,500 owing against a 1,000 limit.
    tillCredit(4, 500, ['customer_id' => $limited->id])->assertSessionHas('error');

    expect(session('error'))
        ->toContain('Tight Limit Ltd')
        ->toContain('credit limit')
        ->toContain('left');

    expect(Sale::count())->toBe(0)
        ->and($this->product->fresh()->stock_quantity)->toBe(50)
        ->and(SalePayment::count())->toBe(0);
});

test('a limit already used up by an earlier invoice is counted against the new one', function () {
    $limited = Customer::factory()->limit(2000)->create();

    tillCredit(2, 0, ['customer_id' => $limited->id])->assertSessionMissing('error');   // 1,000 owed
    tillCredit(2, 0, ['customer_id' => $limited->id])->assertSessionMissing('error');   // 2,000 owed, at the limit

    // A third would go past it.
    tillCredit(2, 0, ['customer_id' => $limited->id])->assertSessionHas('error');

    expect(Sale::count())->toBe(2);
});

test('an inactive customer cannot be billed', function () {
    $closed = Customer::factory()->inactive()->create(['name' => 'Closed Ltd']);

    tillCredit(4, 0, ['customer_id' => $closed->id])->assertSessionHas('error');

    expect(session('error'))->toContain('Closed Ltd');
    expect(Sale::count())->toBe(0);
});

test('a due date is stored, and one in the past is refused by validation', function () {
    tillCredit(4, 0, ['due_date' => today()->addDays(30)->toDateString()])->assertSessionMissing('error');

    expect(Sale::sole()->due_date->toDateString())->toBe(today()->addDays(30)->toDateString());

    checkout(cartOf([[$this->product, 1]]), ['due_date' => today()->subDay()->toDateString()])
        ->assertSessionHasErrors('due_date');
});

test('a customer attached to the cart is used when the form does not post one', function () {
    $this->withSession(['cart_customer' => $this->customer->id]);

    checkout(cartOf([[$this->product, 4]]), ['paid_amount' => 0, 'on_credit' => 1])
        ->assertSessionMissing('error');

    expect(Sale::sole()->customer_id)->toBe($this->customer->id);
});

test('selling on credit can be switched off for a shop', function () {
    Setting::set('feature_credit', '0');

    tillCredit(4, 0)->assertSessionHas('error');

    expect(session('error'))->toContain('switched off');
    expect(Sale::count())->toBe(0);
});

test('the cart only offers pay-later once a customer is attached', function () {
    $this->withSession(['cart' => cartOf([[$this->product, 1]])]);

    $this->get(route('pos.index'))->assertOk()->assertDontSee('Put the rest on');

    $this->withSession(['cart' => cartOf([[$this->product, 1]]), 'cart_customer' => $this->customer->id]);

    $this->get(route('pos.index'))->assertOk()
        ->assertSee('Put the rest on')
        ->assertSee('Ada Trading Co');
});

test('the receipt prints the balance due, the due date and the customer', function () {
    tillCredit(4, 750, ['due_date' => today()->addDays(14)->toDateString()])->assertSessionMissing('error');

    $this->get(route('pos.print', Sale::sole()->id))
        ->assertOk()
        ->assertSee('BALANCE DUE')
        ->assertSee('1,250.00')
        ->assertSee(today()->addDays(14)->format('d M Y'))
        ->assertSee('Ada Trading Co')
        ->assertSee('Part paid');
});

test('the customer can be attached to and taken off the cart from the till', function () {
    $this->postJson(route('pos.customer'), ['customer_id' => $this->customer->id])
        ->assertOk()
        ->assertJsonPath('customer.name', 'Ada Trading Co');

    expect(session('cart_customer'))->toBe($this->customer->id);

    $this->postJson(route('pos.customer'), ['customer_id' => null])->assertOk();

    expect(session('cart_customer'))->toBeNull();
});

test('an inactive customer cannot be attached to the cart in the first place', function () {
    $closed = Customer::factory()->inactive()->create(['name' => 'Closed Ltd']);

    $this->postJson(route('pos.customer'), ['customer_id' => $closed->id])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect(session('cart_customer'))->toBeNull();
});

test('the customer does not follow the cashier into the next sale', function () {
    tillCredit(1, 500)->assertSessionMissing('error');

    expect(session('cart_customer'))->toBeNull();
});

// ---- shifts ----

test('a sale is stamped with the cashier\'s open shift', function () {
    $shift = Shift::factory()->open()->create(['user_id' => $this->cashier->id]);

    checkout(cartOf([[$this->product, 1]]))->assertSessionMissing('error');

    expect(Sale::sole()->shift_id)->toBe($shift->id);
});

test('a closed shift is never stamped onto a later sale', function () {
    Shift::factory()->closed()->create(['user_id' => $this->cashier->id]);

    checkout(cartOf([[$this->product, 1]]))->assertSessionMissing('error');

    expect(Sale::sole()->shift_id)->toBeNull();
});

test('another cashier\'s open shift is not stamped onto this sale', function () {
    Shift::factory()->open()->create(['user_id' => User::factory()->cashier()->create()->id]);

    checkout(cartOf([[$this->product, 1]]))->assertSessionMissing('error');

    expect(Sale::sole()->shift_id)->toBeNull();
});

test('with no shift open and no requirement, a sale still goes through unstamped', function () {
    // The default: an install that has never used shifts is unaffected.
    expect(setting('require_open_shift', '0'))->toBe('0');

    checkout(cartOf([[$this->product, 1]]))->assertSessionMissing('error');

    expect(Sale::count())->toBe(1)->and(Sale::sole()->shift_id)->toBeNull();
});

test('with the requirement switched on and no shift open, the sale is refused and nothing moves', function () {
    Setting::set('require_open_shift', '1');

    checkout(cartOf([[$this->product, 1]]))
        ->assertSessionHas('error', 'No shift is open. Open a shift before selling.');

    expect(Sale::count())->toBe(0)
        ->and(SalePayment::count())->toBe(0)
        ->and(InventoryLog::count())->toBe(0)
        ->and($this->product->fresh()->stock_quantity)->toBe(50);
});

test('with the requirement switched on and a shift open, the sale goes through', function () {
    Setting::set('require_open_shift', '1');
    $shift = Shift::factory()->open()->create(['user_id' => $this->cashier->id]);

    checkout(cartOf([[$this->product, 1]]))->assertSessionMissing('error');

    expect(Sale::sole()->shift_id)->toBe($shift->id);
});

test('the till refuses to offer the charge button when a shift is required and none is open', function () {
    Setting::set('require_open_shift', '1');
    $this->withSession(['cart' => cartOf([[$this->product, 1]])]);

    $this->get(route('pos.index'))->assertOk()->assertSee('requires one before anything can be sold');
});

// ---- items with no stock ----

test('a service sells with no stock, and moves no stock', function () {
    $haircut = Product::factory()->create([
        'name' => 'Haircut',
        'sale_price' => 3000,
        'is_service' => true,
        'track_stock' => false,
        'stock_quantity' => 0,
    ]);

    checkout(cartOf([[$haircut, 3]]), ['paid_amount' => 9000])->assertSessionMissing('error');

    $sale = Sale::sole();

    expect((float) $sale->grand_total)->toBe(9000.0)
        ->and($sale->items)->toHaveCount(1)
        ->and($sale->items->first()->quantity)->toBe(3)
        // No stock level, so nothing to decrement and no movement to log.
        ->and($haircut->fresh()->stock_quantity)->toBe(0)
        ->and(InventoryLog::where('product_id', $haircut->id)->count())->toBe(0);
});

test('a service alongside stocked goods: only the goods move', function () {
    $haircut = Product::factory()->create([
        'sale_price' => 1000, 'is_service' => true, 'track_stock' => false, 'stock_quantity' => 0,
    ]);

    checkout(cartOf([[$haircut, 1], [$this->product, 2]]), ['paid_amount' => 2000])
        ->assertSessionMissing('error');

    expect($this->product->fresh()->stock_quantity)->toBe(48)
        ->and($haircut->fresh()->stock_quantity)->toBe(0)
        ->and(InventoryLog::where('type', 'OUT')->count())->toBe(1);
});

test('an item that simply does not track stock is not blocked either', function () {
    $bulk = Product::factory()->create([
        'name' => 'Loose Sand', 'sale_price' => 100,
        'is_service' => false, 'track_stock' => false, 'stock_quantity' => 0,
    ]);

    $this->postJson(route('pos.add', $bulk->id))->assertOk();
    $this->postJson(route('pos.update', $bulk->id), ['quantity' => 99])->assertOk();

    checkout(cartOf([[$bulk, 99]]), ['paid_amount' => 9900])->assertSessionMissing('error');

    expect(Sale::count())->toBe(1)->and(InventoryLog::count())->toBe(0);
});

test('the cart shows no stock figure or warning against a service', function () {
    $haircut = Product::factory()->create([
        'name' => 'Haircut', 'sale_price' => 3000,
        'is_service' => true, 'track_stock' => false, 'stock_quantity' => 0,
    ]);

    $this->withSession(['cart' => cartOf([[$haircut, 5]])]);

    $html = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get(route('pos.index'))->getContent();

    expect($html)->toContain('Haircut')->not->toContain('in stock');
});
