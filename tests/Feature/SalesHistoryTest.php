<?php

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;

beforeEach(function () {
    $this->alice = User::factory()->cashier()->create(['name' => 'Alice Cashier']);
    $this->bob = User::factory()->cashier()->create(['name' => 'Bob Cashier']);
    $this->manager = User::factory()->manager()->create();
    $this->product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 500]);
});

/**
 * Ring up one sale as $user and return it.
 *
 * The checks are deliberately strict. checkout() refuses with a flashed
 * `error`, not with a validation error bag, so assertSessionHasNoErrors() alone
 * lets a refusal through; Sale::latest() then quietly returns the *previous*
 * sale and the test fails several assertions later somewhere unrelated.
 */
function saleBy(User $user, array $attrs = []): Sale
{
    $before = Sale::max('id');

    test()->actingAs($user);
    checkout(cartOf([[test()->product, 1]]), array_filter(['payment_method' => $attrs['payment_method'] ?? null]))
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('error');

    $sale = Sale::latest('id')->first();

    expect($sale?->id)->not->toBe($before, 'checkout did not create a sale');
    expect($sale->user_id)->toBe($user->id, 'the sale was not attributed to the acting user');

    if (isset($attrs['when'])) {
        $sale->forceFill(['created_at' => $attrs['when']])->save();
    }

    return $sale->fresh();
}

test('a cashier sees only their own sales from today', function () {
    $mine = saleBy($this->alice);
    $yesterday = saleBy($this->alice, ['when' => now()->subDay()]);
    $theirs = saleBy($this->bob);

    $this->actingAs($this->alice)->get(route('sales.index'))
        ->assertOk()->assertSee($mine->invoice_number)
        ->assertDontSee($yesterday->invoice_number)->assertDontSee($theirs->invoice_number);
});

test('a cashier cannot widen the list with query parameters', function () {
    $theirs = saleBy($this->bob);
    $old = saleBy($this->alice, ['when' => now()->subDays(3)]);

    $this->actingAs($this->alice)
        ->get(route('sales.index', ['cashier' => $this->bob->id, 'from' => now()->subWeek()->toDateString()]))
        ->assertOk()->assertDontSee($theirs->invoice_number)->assertDontSee($old->invoice_number);
});

test('managers see every cashier over a date range and can filter', function () {
    $a = saleBy($this->alice, ['payment_method' => 'cash']);
    $b = saleBy($this->bob, ['payment_method' => 'transfer']);
    $old = saleBy($this->alice, ['when' => now()->subDays(20)]);

    $this->actingAs($this->manager);

    $this->get(route('sales.index'))->assertSee($a->invoice_number)->assertSee($b->invoice_number)
        ->assertDontSee($old->invoice_number); // default window is the last 7 days

    $this->get(route('sales.index', ['from' => now()->subDays(30)->toDateString()]))->assertSee($old->invoice_number);
    $this->get(route('sales.index', ['cashier' => $this->bob->id]))->assertSee($b->invoice_number)->assertDontSee($a->invoice_number);
    $this->get(route('sales.index', ['payment_method' => 'cash']))->assertSee($a->invoice_number)->assertDontSee($b->invoice_number);
    $this->get(route('sales.index', ['search' => substr($b->invoice_number, 0, 18)]))->assertSee($b->invoice_number);
});

test('the status filter and the summary cards understand voided sales', function () {
    $keep = saleBy($this->alice);
    $void = saleBy($this->alice);

    $this->actingAs($this->manager)->post(route('sales.void', $void), ['reason' => 'mistake', 'method' => 'cash']);

    $response = $this->get(route('sales.index', ['status' => 'voided']))
        ->assertSee($void->invoice_number)->assertDontSee($keep->invoice_number);

    $all = $this->get(route('sales.index'));
    expect($all->viewData('summary'))->toMatchArray(['count' => 1, 'total' => 200, 'refunded' => 100]);
});

test('filters are validated', function () {
    $this->actingAs($this->manager)->get(route('sales.index', ['status' => 'banana']))->assertSessionHasErrors('status');
    $this->get(route('sales.index', ['from' => '2026-10-10', 'to' => '2026-10-01']))->assertSessionHasErrors('to');
});

test('a cashier can open their own sale from today but nobody else\'s, nor their own from yesterday', function () {
    $mine = saleBy($this->alice);
    $theirs = saleBy($this->bob);
    $old = saleBy($this->alice, ['when' => now()->subDay()]);

    $this->actingAs($this->alice);
    $this->get(route('sales.show', $mine))->assertOk()->assertSee($mine->invoice_number);
    $this->get(route('sales.show', $theirs))->assertForbidden();
    $this->get(route('sales.show', $old))->assertForbidden();
});

test('managers can open any sale', function () {
    $old = saleBy($this->alice, ['when' => now()->subDays(40)]);

    $this->actingAs($this->manager)->get(route('sales.show', $old))->assertOk();
});

test('receipts follow the same rule (they used to be viewable by any signed-in user)', function () {
    $mine = saleBy($this->alice);
    $theirs = saleBy($this->bob);

    $this->actingAs($this->alice);
    $this->get(route('pos.print', $mine))->assertOk();
    $this->get(route('pos.print', $theirs))->assertForbidden();

    $this->actingAs($this->manager)->get(route('pos.print', $theirs))->assertOk();
});

test('a reprint returns to the sale page instead of the till, and a fresh sale returns to the till', function () {
    $sale = saleBy($this->alice);

    $this->actingAs($this->alice);
    $this->get(route('pos.print', ['sale' => $sale->id, 'reprint' => 1]))->assertOk()
        ->assertSee(route('sales.show', $sale->id), false);
    $this->get(route('pos.print', $sale))->assertOk()->assertSee(route('pos.index'), false);
});

test('a reprint does not fire the thermal printer again', function () {
    \App\Models\Setting::set('print_method', 'escpos');
    $sale = saleBy($this->alice);

    // The ESC/POS path logs that it was attempted before it branches on the host OS,
    // so this holds on Windows (where it really prints) and on CI (where it cannot).
    \Illuminate\Support\Facades\Log::spy();

    $this->get(route('pos.print', ['sale' => $sale->id, 'reprint' => 1]))->assertOk();
    $this->get(route('pos.print', $sale))->assertOk();

    // Exactly one attempt across the two requests: the fresh print fires the
    // printer, the reprint does not. Two calls or none would both be bugs.
    \Illuminate\Support\Facades\Log::shouldHaveReceived('info')
        ->withArgs(fn ($message) => $message === 'ESC/POS print attempted')
        ->once();
});

test('the receipt shows discounts, tax, and a voided banner', function () {
    \App\Models\Setting::set('tax_rate', '10');
    $this->actingAs($this->alice);
    checkout(cartOf([[$this->product, 2, ['discount_type' => 'percent', 'discount_value' => 10]]]));
    $sale = Sale::latest('id')->first();

    $this->get(route('pos.print', $sale))->assertSee('Discount:')->assertSee('Tax (10%)')->assertDontSee('VOIDED');

    $this->actingAs($this->manager)->post(route('sales.void', $sale), ['reason' => 'test', 'method' => 'cash']);
    $this->get(route('pos.print', $sale))->assertSee('VOIDED');
});

test('the sales link is in the navigation for cashiers and managers alike', function () {
    foreach ([$this->alice, $this->manager] as $user) {
        $this->actingAs($user)->get(route('pos.index'))->assertSee(route('sales.index'), false);
    }
});

test('the sales history needs a login', function () {
    $sale = saleBy($this->alice);
    auth()->logout();

    $this->get(route('sales.index'))->assertRedirect(route('login'));
    $this->get(route('sales.show', $sale))->assertRedirect(route('login'));
});
