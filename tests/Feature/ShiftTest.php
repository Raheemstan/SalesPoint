<?php

use App\Models\Refund;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->alice = User::factory()->cashier()->create(['name' => 'Alice Cashier']);
    $this->bob = User::factory()->cashier()->create(['name' => 'Bob Cashier']);
    $this->manager = User::factory()->manager()->create(['name' => 'Mo Manager']);
});

/** A completed sale stamped onto a shift. The till does not stamp shift_id yet - that is a later job. */
/**
 * A completed sale on a shift.
 *
 * paid_amount and change_due default to agreeing with grand_total: ShiftService
 * reads the money that entered the drawer as paid_amount - change_due, so a sale
 * whose grand_total was overridden on its own would be an incoherent record -
 * rung up for one amount, paid with another.
 */
function saleOnShift(Shift $shift, array $attrs = []): Sale
{
    $grandTotal = $attrs['grand_total'] ?? 500;

    return Sale::create(array_merge([
        'invoice_number' => 'INV-' . Str::upper(Str::random(12)),
        'user_id' => $shift->user_id,
        'shift_id' => $shift->id,
        'total_amount' => $grandTotal,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'grand_total' => $grandTotal,
        'payment_method' => 'cash',
        'paid_amount' => $grandTotal,
        'change_due' => 0,
        'sale_date' => now(),
        'status' => Sale::STATUS_COMPLETED,
    ], $attrs));
}

/** shift_id is assigned directly: Refund::$fillable does not list it yet. */
function refundOnShift(Sale $sale, Shift $shift, array $attrs = []): Refund
{
    $refund = Refund::create(array_merge([
        'sale_id' => $sale->id,
        'user_id' => $sale->user_id,
        'total_amount' => 100,
        'tax_amount' => 0,
        'method' => 'cash',
        'reason' => 'Returned the goods',
        'is_void' => false,
    ], $attrs));

    $refund->shift_id = $shift->id;
    $refund->save();

    return $refund;
}

function openShift(User $user, $float = 5000, array $extra = [])
{
    return test()->actingAs($user)->post(route('shifts.open'), array_merge(['opening_float' => $float], $extra));
}

function closeShift(User $user, Shift $shift, array $counted = [], array $extra = [])
{
    return test()->actingAs($user)->post(route('shifts.close', $shift), array_merge([
        'counted_cash' => 0,
        'counted_pos' => 0,
        'counted_transfer' => 0,
    ], $counted, $extra));
}

// ---- opening ----

test('a cashier opens a shift with a float and sees it on the shifts page', function () {
    openShift($this->alice, 5000, ['note' => 'Small notes only'])
        ->assertRedirect(route('shifts.index'))->assertSessionHas('success');

    $shift = Shift::first();
    expect($shift->user_id)->toBe($this->alice->id)->and((float) $shift->opening_float)->toBe(5000.0);

    $this->get(route('shifts.index'))->assertOk()
        ->assertSee('Shift open')->assertSee('Small notes only')->assertSee('Alice Cashier');
});

test('the open shift shows a live expected-cash figure that moves with the takings', function () {
    openShift($this->alice, 1000);
    $shift = Shift::first();

    $this->get(route('shifts.index'))->assertSee('₦1,000.00');

    saleOnShift($shift, ['grand_total' => 450, 'payment_method' => 'cash']);
    saleOnShift($shift, ['grand_total' => 900, 'payment_method' => 'pos']); // not cash, not in the drawer
    $cash = saleOnShift($shift, ['grand_total' => 200, 'payment_method' => 'cash']);
    refundOnShift($cash, $shift, ['total_amount' => 50, 'method' => 'cash']);

    // 1000 float + 650 cash sales - 50 cash refund
    $this->get(route('shifts.index'))->assertSee('₦1,600.00')->assertDontSee('₦2,500.00');
});

test('a second open shift for the same user is refused', function () {
    openShift($this->alice);
    openShift($this->alice, 2000)->assertSessionHas('error', 'You already have an open shift. Close it before opening another.');

    expect(Shift::count())->toBe(1);
});

test('two cashiers can each have their own open shift', function () {
    openShift($this->alice);
    openShift($this->bob);

    expect(Shift::open()->count())->toBe(2);
});

test('the opening float is validated', function () {
    $this->actingAs($this->alice);

    $this->post(route('shifts.open'), [])->assertSessionHasErrors('opening_float');
    $this->post(route('shifts.open'), ['opening_float' => -5])->assertSessionHasErrors('opening_float');
    $this->post(route('shifts.open'), ['opening_float' => 'plenty'])->assertSessionHasErrors('opening_float');
    $this->post(route('shifts.open'), ['opening_float' => 100, 'note' => str_repeat('x', 501)])->assertSessionHasErrors('note');

    expect(Shift::count())->toBe(0);
});

test('with no shift open the page prompts to open one', function () {
    $this->actingAs($this->alice)->get(route('shifts.index'))
        ->assertOk()->assertSee('No shift is open')->assertSee('Opening float');
});

// ---- the list ----

test('a cashier sees only their own shifts, however the URL is tampered with', function () {
    openShift($this->alice);
    openShift($this->bob);

    $this->actingAs($this->alice)
        ->get(route('shifts.index', ['user' => $this->bob->id, 'from' => now()->subYear()->toDateString()]))
        ->assertOk()->assertSee('Alice Cashier')->assertDontSee('Bob Cashier');
});

test('a manager sees everyone and can filter by cashier and status', function () {
    openShift($this->alice);
    openShift($this->bob);
    closeShift($this->bob, Shift::where('user_id', $this->bob->id)->first());

    $this->actingAs($this->manager);

    // Read the table rows rather than the whole page: a drawer left open is also
    // flagged outside the table on purpose, and that must not look like a broken filter.
    $names = fn (array $params = []) => $this->get(route('shifts.index', $params))
        ->assertOk()->viewData('shifts')->pluck('user.name')->all();

    expect($names())->toContain('Alice Cashier', 'Bob Cashier')
        ->and($names(['user' => $this->bob->id]))->toBe(['Bob Cashier'])
        ->and($names(['status' => 'closed']))->toBe(['Bob Cashier'])
        ->and($names(['status' => 'open']))->toBe(['Alice Cashier']);

    $this->get(route('shifts.index', ['status' => 'closed']))->assertSee('Other drawers still open');
});

test('the list filters are validated', function () {
    $this->actingAs($this->manager);

    $this->get(route('shifts.index', ['status' => 'ajar']))->assertSessionHasErrors('status');
    $this->get(route('shifts.index', ['from' => '2026-10-10', 'to' => '2026-10-01']))->assertSessionHasErrors('to');
});

// ---- authorisation ----

test('a cashier cannot open or close another cashier shift, but a manager can', function () {
    openShift($this->bob, 1000);
    $theirs = Shift::first();

    $this->actingAs($this->alice);
    $this->get(route('shifts.show', $theirs))->assertForbidden();
    closeShift($this->alice, $theirs, ['counted_cash' => 1000])->assertForbidden();

    // A manager sees any drawer and may force-close one (the cashier has gone home).
    $this->actingAs($this->manager)->get(route('shifts.show', $theirs))->assertOk();
    closeShift($this->manager, $theirs, ['counted_cash' => 1000])->assertRedirect(route('shifts.show', $theirs));

    $theirs->refresh();
    expect($theirs->status)->toBe('closed')->and($theirs->closed_by)->toBe($this->manager->id);
});

test('a closed shift cannot be closed again, by anyone', function () {
    openShift($this->alice, 1000);
    $shift = Shift::first();
    closeShift($this->alice, $shift, ['counted_cash' => 900, 'close_note' => 'first count'])->assertSessionHas('success');

    closeShift($this->alice, $shift, ['counted_cash' => 1000])->assertForbidden();
    closeShift($this->manager, $shift, ['counted_cash' => 1000])->assertForbidden();

    // The first count and its variance stand.
    $shift->refresh();
    expect((float) $shift->counted_cash)->toBe(900.0)
        ->and($shift->close_note)->toBe('first count')
        ->and($shift->closed_by)->toBe($this->alice->id);
});

test('shifts need a login', function () {
    openShift($this->alice);
    $shift = Shift::first();
    auth()->logout();

    $this->get(route('shifts.index'))->assertRedirect(route('login'));
    $this->get(route('shifts.show', $shift))->assertRedirect(route('login'));
    $this->post(route('shifts.open'), ['opening_float' => 1])->assertRedirect(route('login'));
    $this->post(route('shifts.close', $shift), ['counted_cash' => 1])->assertRedirect(route('login'));
});

// ---- closing and the Z report ----

test('closing the drawer records the count and reports a shortage', function () {
    openShift($this->alice, 1000);
    $shift = Shift::first();
    saleOnShift($shift, ['grand_total' => 500, 'payment_method' => 'cash']);
    saleOnShift($shift, ['grand_total' => 800, 'payment_method' => 'pos']);

    closeShift($this->alice, $shift, ['counted_cash' => 1450, 'counted_pos' => 800, 'close_note' => 'Cannot find a 50'])
        ->assertRedirect(route('shifts.show', $shift))
        ->assertSessionHas('success', 'Shift closed. The drawer is short by ₦50.00.');

    $shift->refresh();
    expect((float) $shift->expected_cash)->toBe(1500.0)
        ->and((float) $shift->counted_cash)->toBe(1450.0)
        ->and($shift->variance_cash)->toBe(-50.0)
        ->and($shift->variance_total)->toBe(-50.0);

    $this->get(route('shifts.show', $shift))->assertOk()
        ->assertSee('₦1,500.00')->assertSee('₦1,450.00')
        ->assertSee('SHORT')->assertSee('Short ₦50.00')
        ->assertSee('Cannot find a 50');
});

test('a drawer with too much money in it is marked over, not short', function () {
    openShift($this->alice, 1000);
    $shift = Shift::first();

    closeShift($this->alice, $shift, ['counted_cash' => 1075.50])->assertSessionHas('success');

    expect($shift->fresh()->variance_total)->toBe(75.50);

    $this->get(route('shifts.show', $shift))->assertSee('OVER')->assertSee('Over ₦75.50')->assertDontSee('SHORT');
});

test('a drawer that balances says so', function () {
    openShift($this->alice, 1000);
    $shift = Shift::first();
    saleOnShift($shift, ['grand_total' => 250]);

    closeShift($this->alice, $shift, ['counted_cash' => 1250])->assertSessionHas('success', 'Shift closed. The drawer balanced.');

    $this->get(route('shifts.show', $shift))->assertSee('Balanced')->assertDontSee('SHORT')->assertDontSee('OVER');
});

test('the counted figures are validated', function () {
    openShift($this->alice, 1000);
    $shift = Shift::first();

    closeShift($this->alice, $shift, ['counted_cash' => null])->assertSessionHasErrors('counted_cash');
    closeShift($this->alice, $shift, ['counted_cash' => -1])->assertSessionHasErrors('counted_cash');
    closeShift($this->alice, $shift, ['counted_pos' => 'about a thousand'])->assertSessionHasErrors('counted_pos');
    closeShift($this->alice, $shift, [], ['close_note' => str_repeat('x', 501)])->assertSessionHasErrors('close_note');

    expect($shift->fresh()->isOpen())->toBeTrue();
});

test('expected figures posted by the browser are ignored and recomputed from the records', function () {
    openShift($this->alice, 1000);
    $shift = Shift::first();
    saleOnShift($shift, ['grand_total' => 500, 'payment_method' => 'cash']);

    closeShift($this->alice, $shift, ['counted_cash' => 1500], [
        'expected_cash' => 1,            // "the drawer only owed me a naira"
        'expected_pos' => 99999,
        'variance_total' => 0,
        'opening_float' => 999999,
    ])->assertSessionHas('success');

    $shift->refresh();
    expect((float) $shift->expected_cash)->toBe(1500.0)
        ->and((float) $shift->expected_pos)->toBe(0.0)
        ->and((float) $shift->opening_float)->toBe(1000.0)
        ->and($shift->variance_total)->toBe(0.0);
});

test('a cash refund taken during the shift reduces what the drawer should hold', function () {
    openShift($this->alice, 1000);
    $shift = Shift::first();
    $sale = saleOnShift($shift, ['grand_total' => 500, 'payment_method' => 'cash']);
    refundOnShift($sale, $shift, ['total_amount' => 120, 'method' => 'cash']);

    // 1000 + 500 - 120: counting all 1500 would now be 120 OVER.
    closeShift($this->alice, $shift, ['counted_cash' => 1380])->assertSessionHas('success', 'Shift closed. The drawer balanced.');

    expect((float) $shift->fresh()->expected_cash)->toBe(1380.0);
});

test('a voided sale is reported but does not change what the drawer should hold', function () {
    openShift($this->alice, 1000);
    $shift = Shift::first();
    saleOnShift($shift, ['grand_total' => 500]);
    $void = saleOnShift($shift, ['grand_total' => 400, 'status' => Sale::STATUS_VOIDED]);
    refundOnShift($void, $shift, ['total_amount' => 400, 'is_void' => true]);

    closeShift($this->alice, $shift, ['counted_cash' => 1500])->assertSessionHas('success', 'Shift closed. The drawer balanced.');

    $this->get(route('shifts.show', $shift))->assertSee('not counted as takings');
});

test('the Z report shows the whole day: takings, discounts, tax, refunds, voids and the net', function () {
    Setting::set('tax_rate', '10');
    openShift($this->alice, 1000);
    $shift = Shift::first();

    $sale = saleOnShift($shift, ['total_amount' => 1000, 'discount_amount' => 100, 'tax_amount' => 90, 'grand_total' => 990]);
    refundOnShift($sale, $shift, ['total_amount' => 90, 'method' => 'cash']);

    $response = $this->actingAs($this->alice)->get(route('shifts.show', $shift))->assertOk();

    expect($response->viewData('summary'))->toMatchArray([
        'sales_count' => 1,
        'subtotal' => 1000.0,
        'discounts' => 100.0,
        'tax' => 90.0,
        'gross' => 990.0,
        'refunds' => 90.0,
        'net' => 900.0,
    ]);

    $response->assertSee('Transactions')->assertSee('Discounts given')->assertSee('Tax collected')
        ->assertSee('Refunds paid out')->assertSee('Drawer count');
});

test('the Z report is printable: a print stylesheet, a print button and no navigation on paper', function () {
    openShift($this->alice, 1000);
    $shift = Shift::first();

    $html = $this->get(route('shifts.show', $shift))->assertOk()
        ->assertSee('window.print()', false)
        ->assertSee('@media print', false)
        ->getContent();

    // The layout's sidebar and top bar are hidden for print; so are the on-screen controls.
    expect($html)->toContain('aside,')->toContain('.no-print { display: none !important; }');
});

test('an open shift says on its Z report that the figures are still moving', function () {
    openShift($this->alice, 1000);
    $shift = Shift::first();

    $this->get(route('shifts.show', $shift))->assertSee('still open')->assertSee('not counted');
});

test('a record changed after sign-off is flagged rather than silently rewriting the report', function () {
    openShift($this->alice, 1000);
    $shift = Shift::first();
    closeShift($this->alice, $shift, ['counted_cash' => 1000])->assertSessionHas('success');

    saleOnShift($shift, ['grand_total' => 750]); // backdated into a closed shift

    $this->get(route('shifts.show', $shift))
        ->assertSee('has been changed since it was closed')
        ->assertSee('₦1,750.00');          // what it *would* be now

    // The signed-off figures are untouched.
    expect((float) $shift->fresh()->expected_cash)->toBe(1000.0);
});

// ---- house rules ----

test('no currency symbol is hardcoded: the shop currency is used throughout', function () {
    Setting::set('currency_symbol', '$');
    openShift($this->alice, 1000);
    $shift = Shift::first();
    saleOnShift($shift, ['grand_total' => 500]);

    $this->get(route('shifts.index'))->assertSee('$1,500.00')->assertDontSee('₦');

    closeShift($this->alice, $shift, ['counted_cash' => 1400])
        ->assertSessionHas('success', 'Shift closed. The drawer is short by $100.00.');

    $this->get(route('shifts.show', $shift))->assertSee('Short $100.00')->assertDontSee('₦');
});

test('the wording follows the trade: a restaurant counts a Server drawer, not a Cashier one', function () {
    Setting::set('business_type', 'restaurant');
    openShift($this->alice, 1000);
    $shift = Shift::first();

    $this->get(route('shifts.index'))->assertSee('Server')->assertDontSee('Cashier</th>', false);
    $this->get(route('shifts.show', $shift))->assertSee('Server')->assertSee('Orders');
});

test('opening and closing a shift are both written to the audit log', function () {
    openShift($this->alice, 1000);
    $shift = Shift::first();
    closeShift($this->alice, $shift, ['counted_cash' => 950]);

    $actions = \App\Models\AuditLog::where('table_name', 'shifts')->orderBy('id')->pluck('action')->all();

    expect($actions)->toHaveCount(2)
        ->and($actions[0])->toContain('Opened shift')
        ->and($actions[1])->toContain('Closed shift')
        ->and($actions[1])->toContain('short by');
});

test('the migration rolls back and reapplies cleanly', function () {
    $migration = require base_path('database/migrations/2026_10_14_000002_create_shifts_table.php');

    $migration->down();

    expect(Schema::hasTable('shifts'))->toBeFalse()
        ->and(Schema::hasColumn('sales', 'shift_id'))->toBeFalse()
        ->and(Schema::hasColumn('refunds', 'shift_id'))->toBeFalse()
        // The columns the rest of the app relies on survive the rollback.
        ->and(Schema::hasColumn('sales', 'grand_total'))->toBeTrue()
        ->and(Schema::hasColumn('refunds', 'total_amount'))->toBeTrue();

    $migration->up();

    expect(Schema::hasTable('shifts'))->toBeTrue()
        ->and(Schema::hasColumn('sales', 'shift_id'))->toBeTrue()
        ->and(Schema::hasColumn('refunds', 'shift_id'))->toBeTrue();
});

test('sales and refunds that predate shifts keep a null shift and still work', function () {
    openShift($this->alice, 1000);
    $shift = Shift::first();

    $orphan = saleOnShift($shift, ['grand_total' => 400]);
    $orphan->forceFill(['shift_id' => null])->save();

    expect($orphan->fresh()->shift_id)->toBeNull()
        ->and($orphan->fresh()->shift)->toBeNull();

    // Nothing from before shifts existed lands in anyone's drawer.
    closeShift($this->alice, $shift, ['counted_cash' => 1000])
        ->assertSessionHas('success', 'Shift closed. The drawer balanced.');
});
