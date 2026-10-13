<?php

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ParkedSale;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Held ("parked") carts
|--------------------------------------------------------------------------
|
| Parking reserves nothing, so the interesting behaviour is all in resume():
| what it restores, what it refuses, and what it says out loud when the world
| has moved on since the cart was put down.
*/

beforeEach(function () {
    $this->cashier = User::factory()->cashier()->create(['name' => 'Ada']);
    $this->other = User::factory()->cashier()->create(['name' => 'Ben']);
    $this->manager = User::factory()->manager()->create(['name' => 'Mo']);
    $this->actingAs($this->cashier);
});

/** Park whatever is in the session cart. */
function parkCart(array $data = [])
{
    return test()->post(route('pos.park'), $data);
}

function resumeCart(ParkedSale $hold)
{
    return test()->post(route('pos.parked.resume', $hold));
}

// ---- parking ----

test('parking stores the cart, totals it, and leaves the till clear', function () {
    $a = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $b = Product::factory()->create(['sale_price' => 50, 'stock_quantity' => 10]);

    $this->withSession(['cart' => cartOf([[$a, 2], [$b, 1]])]);
    parkCart(['note' => 'Gone to the car for his wallet'])->assertRedirect();

    $hold = ParkedSale::sole();

    expect($hold->user_id)->toBe($this->cashier->id)
        ->and($hold->reference)->toStartWith('HOLD-')
        ->and($hold->note)->toBe('Gone to the car for his wallet')
        ->and($hold->item_count)->toBe(3)
        ->and((float) $hold->total)->toBe(250.0)
        ->and($hold->cart)->toHaveCount(2);

    // The till is empty and ready for the next customer.
    expect(session('cart'))->toBeNull()->and(session('cart_discount'))->toBeNull();
});

test('the stored total is recomputed on the server, not taken from the session copy', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);

    $cart = cartOf([[$product, 2]]);
    $cart[$product->id]['price'] = 1;             // tampered / stale

    $this->withSession(['cart' => $cart]);
    parkCart();

    expect((float) ParkedSale::sole()->total)->toBe(200.0);
});

test('the discount and the customer are held with the cart', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $customer = Customer::factory()->create();

    $this->withSession([
        'cart' => cartOf([[$product, 1, ['discount_type' => 'percent', 'discount_value' => 10]]]),
        'cart_discount' => ['type' => 'fixed', 'value' => 5],
        'cart_customer' => $customer->id,
    ]);

    parkCart();

    $hold = ParkedSale::sole();

    expect($hold->customer_id)->toBe($customer->id)
        ->and($hold->discount)->toBe(['type' => 'fixed', 'value' => 5])
        ->and($hold->cart[$product->id]['discount_type'])->toBe('percent')
        // 100 less 10% = 90, less the 5 sale discount = 85
        ->and((float) $hold->total)->toBe(85.0);
});

test('there is nothing to hold when the till is empty', function () {
    parkCart()->assertRedirect()->assertSessionHas('error');

    expect(ParkedSale::count())->toBe(0);
});

test('holding and deleting a cart are written to the audit log', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $this->withSession(['cart' => cartOf([[$product, 1]])]);
    parkCart();

    $hold = ParkedSale::sole();

    expect(AuditLog::where('table_name', 'parked_sales')->where('record_id', $hold->id)->count())->toBe(1);

    $this->delete(route('pos.parked.destroy', $hold));

    expect(AuditLog::where('table_name', 'parked_sales')->count())->toBe(2)
        ->and(ParkedSale::count())->toBe(0);
});

// ---- resuming ----

test('resuming restores the lines, the line discount, the sale discount and the customer', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $customer = Customer::factory()->create();

    $hold = ParkedSale::factory()
        ->holding([[$product, 3, ['discount_type' => 'percent', 'discount_value' => 10]]])
        ->create([
            'user_id' => $this->cashier->id,
            'customer_id' => $customer->id,
            'discount' => ['type' => 'fixed', 'value' => 20],
        ]);

    resumeCart($hold)->assertRedirect(route('pos.index'))->assertSessionHas('success');

    expect(session('cart'))->toHaveCount(1)
        ->and(session('cart')[$product->id]['quantity'])->toBe(3)
        ->and(session('cart')[$product->id]['discount_type'])->toBe('percent')
        ->and((float) session('cart')[$product->id]['discount_value'])->toBe(10.0)
        ->and(session('cart_discount'))->toBe(['type' => 'fixed', 'value' => 20])
        ->and(session('cart_customer'))->toBe($customer->id);

    // Resuming consumes the hold: it cannot be resumed into two tills.
    expect(ParkedSale::count())->toBe(0);
});

test('resuming is refused while the till still has a sale in it', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $inHand = Product::factory()->create(['sale_price' => 70, 'stock_quantity' => 10]);

    $hold = ParkedSale::factory()->holding([[$product, 1]])->create(['user_id' => $this->cashier->id]);

    $this->withSession(['cart' => cartOf([[$inHand, 1]])]);
    resumeCart($hold)->assertRedirect()->assertSessionHas('error');

    // Neither cart was touched: the one in hand survives and the hold is still held.
    expect(session('cart'))->toHaveKey($inHand->id)
        ->and(session('cart'))->not->toHaveKey($product->id)
        ->and(ParkedSale::count())->toBe(1);
});

test('resuming re-checks stock: a line that sold out is dropped and said so', function () {
    $gone = Product::factory()->create(['name' => 'Sold Out Thing', 'sale_price' => 100, 'stock_quantity' => 5]);
    $fine = Product::factory()->create(['sale_price' => 40, 'stock_quantity' => 5]);

    $hold = ParkedSale::factory()->holding([[$gone, 2], [$fine, 1]])->create(['user_id' => $this->cashier->id]);

    $gone->update(['stock_quantity' => 0]);

    resumeCart($hold)->assertSessionHas('success');

    expect(session('cart'))->toHaveCount(1)->toHaveKey($fine->id)->not->toHaveKey($gone->id);
    expect(session('warning'))->toContain('Sold Out Thing')->toContain('sold out');
});

test('resuming clamps a line to what is left rather than overselling it', function () {
    $product = Product::factory()->create(['name' => 'Only Two Left', 'sale_price' => 100, 'stock_quantity' => 10]);

    $hold = ParkedSale::factory()->holding([[$product, 6]])->create(['user_id' => $this->cashier->id]);

    $product->update(['stock_quantity' => 2]);

    resumeCart($hold)->assertSessionHas('success');

    expect(session('cart')[$product->id]['quantity'])->toBe(2);
    expect(session('warning'))->toContain('Only Two Left')->toContain('only 2 of 6');
});

test('a price change since the cart was held is pointed out, and the new price is used', function () {
    $product = Product::factory()->create(['name' => 'Risen Thing', 'sale_price' => 100, 'stock_quantity' => 10]);

    $hold = ParkedSale::factory()->holding([[$product, 1]])->create(['user_id' => $this->cashier->id]);

    $product->update(['sale_price' => 130]);

    resumeCart($hold)->assertSessionHas('success');

    expect((float) session('cart')[$product->id]['price'])->toBe(130.0);
    expect(session('warning'))->toContain('Risen Thing')->toContain('130');
});

test('a product deleted since the cart was held drops out with a word about it', function () {
    $keep = Product::factory()->create(['sale_price' => 50, 'stock_quantity' => 5]);
    $doomed = Product::factory()->create(['name' => 'Archived Thing', 'sale_price' => 100, 'stock_quantity' => 5]);

    $hold = ParkedSale::factory()->holding([[$keep, 1], [$doomed, 1]])->create(['user_id' => $this->cashier->id]);

    $doomed->delete();

    resumeCart($hold)->assertSessionHas('success');

    expect(session('cart'))->toHaveCount(1)->toHaveKey($keep->id);
    expect(session('warning'))->toContain('Archived Thing');
});

test('a hold whose every line has gone cannot be resumed, and is left alone', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 5]);
    $hold = ParkedSale::factory()->holding([[$product, 1]])->create(['user_id' => $this->cashier->id]);

    $product->delete();

    resumeCart($hold)->assertSessionHas('error');

    expect(session('cart'))->toBeNull()->and(ParkedSale::count())->toBe(1);
});

test('a customer deactivated since the cart was held is not re-attached', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 5]);
    $customer = Customer::factory()->create(['name' => 'Closed Account Ltd']);

    $hold = ParkedSale::factory()->holding([[$product, 1]])->create([
        'user_id' => $this->cashier->id,
        'customer_id' => $customer->id,
    ]);

    $customer->update(['is_active' => false]);

    resumeCart($hold)->assertSessionHas('success');

    expect(session('cart_customer'))->toBeNull();
    expect(session('warning'))->toContain('Closed Account Ltd');
});

test('a hold with no sale discount does not inherit one left in the session', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 5]);
    $hold = ParkedSale::factory()->holding([[$product, 1]])->create(['user_id' => $this->cashier->id, 'discount' => null]);

    // Stale discount in the session, empty cart.
    $this->withSession(['cart_discount' => ['type' => 'percent', 'value' => 50]]);

    resumeCart($hold)->assertSessionHas('success');

    expect(session('cart_discount'))->toBeNull();
});

// ---- who may do what ----

test("a cashier cannot resume another cashier's held sale", function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 5]);
    $hold = ParkedSale::factory()->holding([[$product, 1]])->create(['user_id' => $this->other->id]);

    resumeCart($hold)->assertForbidden();

    expect(session('cart'))->toBeNull()->and(ParkedSale::count())->toBe(1);
});

test("not even a manager resumes someone else's held sale: it would ring up on their drawer", function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 5]);
    $hold = ParkedSale::factory()->holding([[$product, 1]])->create(['user_id' => $this->cashier->id]);

    $this->actingAs($this->manager);
    resumeCart($hold)->assertForbidden();

    expect(ParkedSale::count())->toBe(1);
});

test('a cashier sees only their own held sales; a manager sees everyone\'s', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 5]);

    $mine = ParkedSale::factory()->holding([[$product, 1]])->create(['user_id' => $this->cashier->id]);
    $theirs = ParkedSale::factory()->holding([[$product, 1]])->create(['user_id' => $this->other->id]);

    $this->get(route('pos.parked'))->assertOk()
        ->assertSee($mine->reference)
        ->assertDontSee($theirs->reference);

    $this->actingAs($this->manager)->get(route('pos.parked'))->assertOk()
        ->assertSee($mine->reference)
        ->assertSee($theirs->reference);
});

test("a manager may delete anyone's held sale; a cashier may not", function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 5]);
    $theirs = ParkedSale::factory()->holding([[$product, 1]])->create(['user_id' => $this->other->id]);

    $this->delete(route('pos.parked.destroy', $theirs))->assertForbidden();
    expect(ParkedSale::count())->toBe(1);

    $this->actingAs($this->manager)->delete(route('pos.parked.destroy', $theirs))->assertRedirect();
    expect(ParkedSale::count())->toBe(0);
});

test('a cashier may delete their own held sale', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 5]);
    $mine = ParkedSale::factory()->holding([[$product, 1]])->create(['user_id' => $this->cashier->id]);

    $this->delete(route('pos.parked.destroy', $mine))->assertRedirect()->assertSessionHas('success');

    expect(ParkedSale::count())->toBe(0);
});

test('references are unique and readable, with no characters that are read wrong aloud', function () {
    $references = collect(range(1, 25))->map(fn () => ParkedSale::newReference());

    expect($references->unique())->toHaveCount(25);

    foreach ($references as $reference) {
        expect($reference)->toMatch('/^HOLD-[A-HJ-NP-Z2-9]{4}$/');
    }
});

test('the till shows how many sales the cashier is holding', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 5]);

    ParkedSale::factory()->holding([[$product, 1]])->count(2)->create(['user_id' => $this->cashier->id]);
    ParkedSale::factory()->holding([[$product, 1]])->create(['user_id' => $this->other->id]);

    // Two of their own, not the three on the table.
    $this->get(route('pos.index'))->assertOk()->assertSeeInOrder(['Held', '2']);
});

test('the migration rolls back and reapplies cleanly', function () {
    $migration = require base_path('database/migrations/2026_10_14_000007_create_parked_sales_and_sale_payments.php');

    $migration->down();

    expect(Schema::hasTable('parked_sales'))->toBeFalse()
        ->and(Schema::hasTable('sale_payments'))->toBeFalse()
        // The tables the rest of the app relies on survive the rollback.
        ->and(Schema::hasTable('sales'))->toBeTrue()
        ->and(Schema::hasColumn('sales', 'grand_total'))->toBeTrue();

    $migration->up();

    expect(Schema::hasTable('parked_sales'))->toBeTrue()
        ->and(Schema::hasTable('sale_payments'))->toBeTrue()
        // The three names ShiftService::salesByMethod() reads are a contract.
        ->and(Schema::hasColumns('sale_payments', ['sale_id', 'method', 'amount']))->toBeTrue()
        ->and(Schema::hasColumns('parked_sales', [
            'reference', 'user_id', 'customer_id', 'cart', 'discount', 'note', 'item_count', 'total',
        ]))->toBeTrue();
});
