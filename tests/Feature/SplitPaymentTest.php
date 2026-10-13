<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;

/*
|--------------------------------------------------------------------------
| Split payments at the till
|--------------------------------------------------------------------------
|
| One sale, several tenders. The arithmetic itself is unit-tested in
| tests/Unit/PaymentSplitServiceTest.php; these cover what checkout does with
| it - the rows it writes, the column it keeps populated for the old reports,
| and the fact that the plain single-payment path is untouched.
*/

beforeEach(function () {
    $this->cashier = User::factory()->cashier()->create();
    $this->actingAs($this->cashier);
    $this->product = Product::factory()->create(['sale_price' => 500, 'stock_quantity' => 50]);
});

/** The tender rows written against the one sale in the database, keyed by method. */
function tenderRows(): array
{
    return SalePayment::where('sale_id', Sale::sole()->id)
        ->get()
        ->mapWithKeys(fn (SalePayment $row) => [$row->method => round((float) $row->amount, 2)])
        ->all();
}

test('a split writes one row per tender, summing exactly to the grand total', function () {
    // 4 x 500 = 2,000
    checkoutSplit(cartOf([[$this->product, 4]]), [
        ['method' => 'cash', 'amount' => 1200],
        ['method' => 'transfer', 'amount' => 800, 'reference' => 'TRF-0099'],
    ])->assertSessionHasNoErrors()->assertSessionMissing('error');

    $sale = Sale::sole();

    expect((float) $sale->grand_total)->toBe(2000.0)
        ->and(tenderRows())->toBe(['cash' => 1200.0, 'transfer' => 800.0])
        ->and(round((float) $sale->payments->sum('amount'), 2))->toBe(2000.0)
        ->and($sale->payments->firstWhere('method', 'transfer')->reference)->toBe('TRF-0099');
});

test('change comes out of the cash part only, and the rows hold what the drawer kept', function () {
    // 1,000 sale: 400 on the card, and a 1,000 note for the rest, so 400 goes back.
    checkoutSplit(cartOf([[$this->product, 2]]), [
        ['method' => 'cash', 'amount' => 1000],
        ['method' => 'pos', 'amount' => 400],
    ])->assertSessionMissing('error');

    $sale = Sale::sole();

    expect((float) $sale->change_due)->toBe(400.0)
        // The card row is untouched; the change came off the cash.
        ->and(tenderRows())->toBe(['cash' => 600.0, 'pos' => 400.0])
        ->and(round((float) $sale->payments->sum('amount'), 2))->toBe(1000.0)
        // paid_amount is what was applied, so it and the rows always agree.
        ->and((float) $sale->paid_amount)->toBe(1000.0);
});

test('a split that under-pays with nothing to put it on is refused and sells nothing', function () {
    checkoutSplit(cartOf([[$this->product, 4]]), [
        ['method' => 'cash', 'amount' => 500],
        ['method' => 'pos', 'amount' => 300],
    ])->assertSessionHas('error', 'Insufficient payment.');

    expect(Sale::count())->toBe(0)
        ->and(SalePayment::count())->toBe(0)
        ->and($this->product->fresh()->stock_quantity)->toBe(50);
});

test('in a split the card and transfer parts cannot exceed the sale, so no drawer is opened for them', function () {
    checkoutSplit(cartOf([[$this->product, 1]]), [
        ['method' => 'cash', 'amount' => 0],
        ['method' => 'transfer', 'amount' => 5000],
    ])->assertSessionHas('error');

    expect(session('error'))->toContain('Change can only be given in cash');
    expect(Sale::count())->toBe(0)->and($this->product->fresh()->stock_quantity)->toBe(50);
});

test('the same method entered twice is refused rather than silently added up', function () {
    checkoutSplit(cartOf([[$this->product, 1]]), [
        ['method' => 'cash', 'amount' => 200],
        ['method' => 'cash', 'amount' => 300],
    ])->assertSessionHas('error');

    expect(session('error'))->toContain('entered twice');
    expect(Sale::count())->toBe(0);
});

test('an unknown tender method fails validation before any money is worked out', function () {
    checkoutSplit(cartOf([[$this->product, 1]]), [
        ['method' => 'crypto', 'amount' => 500],
    ])->assertSessionHasErrors('tenders.0.method');

    checkoutSplit(cartOf([[$this->product, 1]]), [
        ['method' => 'cash', 'amount' => -1],
    ])->assertSessionHasErrors('tenders.0.amount');

    expect(Sale::count())->toBe(0);
});

test('payment_method keeps the dominant tender, so the old reports and filters still work', function () {
    checkoutSplit(cartOf([[$this->product, 4]]), [
        ['method' => 'cash', 'amount' => 600],
        ['method' => 'transfer', 'amount' => 1400],
    ])->assertSessionMissing('error');

    expect(Sale::sole()->payment_method)->toBe('transfer');

    // And the sales list can still filter on it.
    $this->actingAs(User::factory()->manager()->create())
        ->get(route('sales.index', ['payment_method' => 'transfer']))
        ->assertOk()
        ->assertSee(Sale::sole()->invoice_number);
});

test('the split tenders win over the single-payment fields when both are posted', function () {
    // tests/Helpers.php always posts payment_method=cash and paid_amount=100000.
    checkoutSplit(cartOf([[$this->product, 2]]), [
        ['method' => 'pos', 'amount' => 1000],
    ])->assertSessionMissing('error');

    $sale = Sale::sole();

    expect($sale->payment_method)->toBe('pos')
        ->and((float) $sale->change_due)->toBe(0.0)
        ->and(tenderRows())->toBe(['pos' => 1000.0]);
});

// ---- the single-payment path is unchanged ----

test('a plain cash sale still writes one sensible tender row', function () {
    checkout(cartOf([[$this->product, 1]]), ['paid_amount' => 500])->assertSessionMissing('error');

    $sale = Sale::sole();

    expect($sale->payment_method)->toBe('cash')
        ->and(tenderRows())->toBe(['cash' => 500.0])
        ->and((float) $sale->change_due)->toBe(0.0);
});

test('a plain sale works on every method, with no split fields posted at all', function () {
    foreach (['cash', 'pos', 'transfer'] as $method) {
        checkout(cartOf([[$this->product, 1]]), ['payment_method' => $method, 'paid_amount' => 100000])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $sale = Sale::latest('id')->first();

        expect($sale->payment_method)->toBe($method)
            ->and($sale->user_id)->toBe($this->cashier->id)
            ->and(round((float) $sale->payments->sum('amount'), 2))->toBe(500.0);
    }

    expect(Sale::count())->toBe(3);
});

test('an overpaid cash sale keeps the change on the sale and the takings in the row', function () {
    checkout(cartOf([[$this->product, 1]]), ['paid_amount' => 700])->assertSessionMissing('error');

    $sale = Sale::sole();

    expect((float) $sale->change_due)->toBe(200.0)
        ->and((float) $sale->paid_amount)->toBe(500.0)
        ->and(tenderRows())->toBe(['cash' => 500.0]);
});

test('a reference can be recorded on a single non-cash payment', function () {
    checkout(cartOf([[$this->product, 1]]), [
        'payment_method' => 'transfer',
        'paid_amount' => 500,
        'payment_reference' => 'GTB-12345',
    ])->assertSessionMissing('error');

    expect(Sale::sole()->payments->first()->reference)->toBe('GTB-12345');
});

// ---- what the drawer expects ----

test('the shift takings are split across the tenders, from the rows rather than the column', function () {
    $shift = Shift::factory()->open()->create(['user_id' => $this->cashier->id, 'opening_float' => 1000]);

    checkoutSplit(cartOf([[$this->product, 4]]), [
        ['method' => 'cash', 'amount' => 1200],
        ['method' => 'transfer', 'amount' => 800],
    ])->assertSessionMissing('error');

    $expected = app(ShiftService::class)->expectedByMethod($shift->fresh());

    expect($expected['cash']['sales'])->toBe(1200.0)
        ->and($expected['cash']['expected'])->toBe(2200.0)        // the 1,000 float as well
        ->and($expected['transfer']['sales'])->toBe(800.0)
        ->and($expected['pos']['sales'])->toBe(0.0);
});

test('a cash sale with change expects only what stayed in the drawer', function () {
    $shift = Shift::factory()->open()->create(['user_id' => $this->cashier->id, 'opening_float' => 0]);

    // 500 sale, 1,000 note, 500 change: the drawer is up by 500, not 1,000.
    checkout(cartOf([[$this->product, 1]]), ['paid_amount' => 1000])->assertSessionMissing('error');

    expect(app(ShiftService::class)->expectedByMethod($shift->fresh())['cash']['expected'])->toBe(500.0);
});

test('a part-paid credit sale only puts the deposit in the drawer', function () {
    $shift = Shift::factory()->open()->create(['user_id' => $this->cashier->id, 'opening_float' => 0]);
    $customer = Customer::factory()->create();

    checkoutSplit(cartOf([[$this->product, 4]]), [
        ['method' => 'cash', 'amount' => 500],
    ], ['customer_id' => $customer->id, 'on_credit' => 1])->assertSessionMissing('error');

    expect(tenderRows())->toBe(['cash' => 500.0])
        ->and(app(ShiftService::class)->expectedByMethod($shift->fresh())['cash']['expected'])->toBe(500.0)
        ->and((float) Sale::sole()->balance_due)->toBe(1500.0);
});

test('tender rows go with the sale when it is deleted, leaving nothing orphaned', function () {
    checkout(cartOf([[$this->product, 1]]), ['paid_amount' => 500]);

    expect(SalePayment::count())->toBe(1);

    Sale::sole()->delete();

    expect(SalePayment::count())->toBe(0);
});

// ---- the receipt ----

test('the receipt breaks a split sale down by tender and shows what was handed over', function () {
    checkoutSplit(cartOf([[$this->product, 4]]), [
        ['method' => 'cash', 'amount' => 1500],
        ['method' => 'transfer', 'amount' => 800, 'reference' => 'TRF-0099'],
    ])->assertSessionMissing('error');

    // 2,000 sale: 800 transferred, 1,500 cash, so 300 goes back.
    $this->get(route('pos.print', Sale::sole()->id))
        ->assertOk()
        ->assertSee('Cash:')
        // The cash line shows the note handed over, not the 1,200 the drawer kept:
        // the change is added back on, because change only ever comes out of cash.
        ->assertSee('1,500.00')
        ->assertSee('Transfer:')
        ->assertSee('800.00')
        ->assertSee('TRF-0099')
        ->assertSee('Change:')
        ->assertSee('300.00');
});

test('the receipt respects the configured paper width and footer', function () {
    \App\Models\Setting::set('receipt_width', '58mm');
    \App\Models\Setting::set('receipt_footer', 'No refunds after 7 days.');

    checkout(cartOf([[$this->product, 1]]), ['paid_amount' => 500]);

    $this->get(route('pos.print', Sale::sole()->id))
        ->assertOk()
        ->assertSee('width: 58mm', false)
        ->assertSee('No refunds after 7 days.');
});

/*
|--------------------------------------------------------------------------
| Tips and service charges
|--------------------------------------------------------------------------
|
| Table service hands a charge to the till in session('cart_service_charge').
| The till adds it on top of the bill, charges it, prints it, and recomputes it
| rather than believing the figure in the session.
*/

/** The session shape table service hands over. */
function serviceCharge(string $type, float $value, float $amount, string $label = 'Service charge (10%)'): array
{
    return ['type' => $type, 'value' => $value, 'amount' => $amount, 'label' => $label];
}

test('a service charge is added on top of the bill and has to be paid', function () {
    Setting::set('feature_tips', '1');

    // 2 x 500 = 1,000 bill, plus 10% service = 1,100.
    $response = test()->withSession([
        'cart' => cartOf([[$this->product, 2]]),
        'cart_service_charge' => serviceCharge('percent', 10, 100),
    ])->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 1100]);

    $response->assertSessionMissing('error');

    $sale = Sale::sole();

    expect((float) $sale->total_amount)->toBe(1000.0)
        ->and((float) $sale->service_charge)->toBe(100.0)
        ->and($sale->service_charge_label)->toBe('Service charge (10%)')
        ->and((float) $sale->grand_total)->toBe(1100.0)
        ->and((float) $sale->change_due)->toBe(0.0)
        // The whole charged amount is collected, so the drawer balances.
        ->and(tenderRows())->toBe(['cash' => 1100.0]);
});

test('the service charge is taken on the bill including tax, which is what the guest sees', function () {
    Setting::set('feature_tips', '1');
    Setting::set('tax_rate', '10');

    // 1,000 + 10% tax = 1,100, and 10% service on that = 110, so 1,210.
    test()->withSession([
        'cart' => cartOf([[$this->product, 2]]),
        'cart_service_charge' => serviceCharge('percent', 10, 110),
    ])->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 1210])
        ->assertSessionMissing('error');

    $sale = Sale::sole();

    expect((float) $sale->tax_amount)->toBe(100.0)
        ->and((float) $sale->service_charge)->toBe(110.0)
        ->and((float) $sale->grand_total)->toBe(1210.0);
});

test('the quoted amount in the session is ignored: the charge is recomputed from the rate', function () {
    Setting::set('feature_tips', '1');

    // A tampered or stale `amount` of 90,000 against a genuine 10% of a 1,000 bill.
    test()->withSession([
        'cart' => cartOf([[$this->product, 2]]),
        'cart_service_charge' => serviceCharge('percent', 10, 90000),
    ])->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 1100])
        ->assertSessionMissing('error');

    expect((float) Sale::sole()->service_charge)->toBe(100.0)
        ->and((float) Sale::sole()->grand_total)->toBe(1100.0);
});

test('a short payment that ignores the service charge is refused', function () {
    Setting::set('feature_tips', '1');

    // The 1,000 bill is covered but the 100 service charge is not.
    test()->withSession([
        'cart' => cartOf([[$this->product, 2]]),
        'cart_service_charge' => serviceCharge('percent', 10, 100),
    ])->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 1000])
        ->assertSessionHas('error', 'Insufficient payment.');

    expect(Sale::count())->toBe(0)->and($this->product->fresh()->stock_quantity)->toBe(50);
});

test('a fixed service charge works the same way', function () {
    Setting::set('feature_tips', '1');

    test()->withSession([
        'cart' => cartOf([[$this->product, 1]]),
        'cart_service_charge' => serviceCharge('fixed', 250, 250, 'Service charge'),
    ])->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 750])
        ->assertSessionMissing('error');

    expect((float) Sale::sole()->service_charge)->toBe(250.0)
        ->and((float) Sale::sole()->grand_total)->toBe(750.0);
});

test('a shop that does not take tips cannot have a charge arrive through a stale session key', function () {
    Setting::set('feature_tips', '0');

    test()->withSession([
        'cart' => cartOf([[$this->product, 2]]),
        'cart_service_charge' => serviceCharge('percent', 10, 100),
    ])->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 1000])
        ->assertSessionMissing('error');

    expect((float) Sale::sole()->grand_total)->toBe(1000.0)
        ->and((float) Sale::sole()->service_charge)->toBe(0.0);
});

test('the cart shows the service charge in the running total before anything is charged', function () {
    Setting::set('feature_tips', '1');

    $html = test()->withSession([
        'cart' => cartOf([[$this->product, 2]]),
        'cart_service_charge' => serviceCharge('percent', 10, 100),
    ])->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get(route('pos.index'))->getContent();

    expect($html)->toContain('Service charge (10%)')->toContain('1,100.00');
});

test('the receipt itemises the service charge under its quoted label', function () {
    Setting::set('feature_tips', '1');

    test()->withSession([
        'cart' => cartOf([[$this->product, 2]]),
        'cart_service_charge' => serviceCharge('percent', 10, 100),
    ])->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 1100]);

    $this->get(route('pos.print', Sale::sole()->id))
        ->assertOk()
        ->assertSee('Service charge (10%)')
        ->assertSee('100.00')
        ->assertSee('1,100.00');
});

test('an unusable service charge is dropped with a warning instead of jamming the till', function () {
    Setting::set('feature_tips', '1');

    // 150% is not a service charge anybody agreed to.
    $this->withSession([
        'cart' => cartOf([[$this->product, 2]]),
        'cart_service_charge' => serviceCharge('percent', 150, 1500),
    ])->get(route('pos.index'))->assertOk()->assertSee('service charge was removed');

    expect(session('cart_service_charge'))->toBeNull();
});

test('a service charge does not follow the cashier into the next sale', function () {
    Setting::set('feature_tips', '1');

    test()->withSession([
        'cart' => cartOf([[$this->product, 2]]),
        'cart_service_charge' => serviceCharge('percent', 10, 100),
    ])->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 1100]);

    expect(session('cart_service_charge'))->toBeNull();

    // And clearing the cart takes it off too.
    $this->withSession([
        'cart' => cartOf([[$this->product, 1]]),
        'cart_service_charge' => serviceCharge('percent', 10, 50),
    ])->postJson(route('pos.clear'))->assertOk();

    expect(session('cart_service_charge'))->toBeNull();
});

test('holding a cart drops the service charge rather than carrying a stale tip', function () {
    Setting::set('feature_tips', '1');

    $this->withSession([
        'cart' => cartOf([[$this->product, 1]]),
        'cart_service_charge' => serviceCharge('percent', 10, 50),
    ])->post(route('pos.park'))->assertRedirect();

    expect(session('cart_service_charge'))->toBeNull();
});
