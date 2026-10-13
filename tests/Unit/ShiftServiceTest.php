<?php

use App\Models\AuditLog;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
 * The unit suite is not bound to the application test case in tests/Pest.php
 * (that only covers Feature), and this service is all database arithmetic.
 */
uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = new ShiftService();
    $this->alice = User::factory()->cashier()->create(['name' => 'Alice']);
    $this->bob = User::factory()->cashier()->create(['name' => 'Bob']);
});

/**
 * A completed sale on a shift. Written straight to the table because the till does
 * not stamp shift_id yet (that is a later job) and because the maths under test is
 * about the money on the sale, not about how the cart produced it.
 *
 * paid_amount and change_due default to agreeing with grand_total, because
 * ShiftService reads "money that entered the drawer" as paid_amount - change_due.
 * A sale whose grand_total was overridden on its own would be an incoherent
 * record - rung up for 1,500 but only 100 handed over - and would be testing
 * arithmetic that cannot occur.
 */
function shiftSale(?Shift $shift, array $attrs = []): Sale
{
    $grandTotal = $attrs['grand_total'] ?? 100;

    return Sale::create(array_merge([
        'invoice_number' => 'INV-' . Str::upper(Str::random(12)),
        'user_id' => $shift?->user_id ?? test()->alice->id,
        'shift_id' => $shift?->id,
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

/** A refund paid out of a shift's drawer. shift_id is set directly: Refund::$fillable does not list it yet. */
function shiftRefund(Sale $sale, ?Shift $shift, array $attrs = []): Refund
{
    $refund = Refund::create(array_merge([
        'sale_id' => $sale->id,
        'user_id' => $sale->user_id,
        'total_amount' => 50,
        'tax_amount' => 0,
        'method' => 'cash',
        'reason' => 'Changed their mind',
        'is_void' => false,
    ], $attrs));

    $refund->shift_id = $shift?->id;
    $refund->save();

    return $refund;
}

// ---- opening ----

it('opens a shift with a float and records who and when', function () {
    $this->actingAs($this->alice);

    $shift = $this->service->open($this->alice, 5000, 'Two bundles of small notes');

    expect($shift->user_id)->toBe($this->alice->id)
        ->and((float) $shift->opening_float)->toBe(5000.0)
        ->and($shift->status)->toBe('open')
        ->and($shift->isOpen())->toBeTrue()
        ->and($shift->opened_at)->not->toBeNull()
        ->and($shift->closed_at)->toBeNull()
        ->and($shift->note)->toBe('Two bundles of small notes');

    expect(AuditLog::where('table_name', 'shifts')->where('record_id', $shift->id)->value('action'))
        ->toContain('Opened shift');
});

it('refuses a second open shift for the same user', function () {
    $this->service->open($this->alice, 1000, null);

    expect(fn () => $this->service->open($this->alice, 2000, null))
        ->toThrow(DomainException::class, 'You already have an open shift. Close it before opening another.');

    expect(Shift::count())->toBe(1);
});

it('refuses the loser of two near-simultaneous opens, so only one shift is ever created', function () {
    // Both requests read "no open shift" before either writes. The guard that
    // matters is the one *inside* open()'s transaction, which re-reads under a lock
    // on the user row: by the time the second request gets there, the first exists.
    expect(Shift::open()->where('user_id', $this->alice->id)->exists())->toBeFalse();

    $results = [];
    foreach ([1000, 2000] as $float) {
        try {
            $results[] = (new ShiftService())->open($this->alice, $float, null);
        } catch (DomainException $e) {
            $results[] = $e;
        }
    }

    expect($results[0])->toBeInstanceOf(Shift::class)
        ->and($results[1])->toBeInstanceOf(DomainException::class)
        ->and(Shift::where('user_id', $this->alice->id)->count())->toBe(1)
        ->and((float) Shift::first()->opening_float)->toBe(1000.0);
});

it('lets a different user open their own shift at the same time', function () {
    $this->service->open($this->alice, 1000, null);
    $this->service->open($this->bob, 2000, null);

    expect(Shift::open()->count())->toBe(2);
});

it('finds the open shift for a user, which is what the till stamps onto a sale', function () {
    expect($this->service->currentFor($this->alice))->toBeNull();

    $mine = $this->service->open($this->alice, 1000, null);
    $this->service->open($this->bob, 1000, null);

    expect($this->service->currentFor($this->alice)->id)->toBe($mine->id);

    // A closed shift is not current: the till must not keep stamping it.
    $this->service->close($mine, ['cash' => 1000], null, $this->alice);
    expect($this->service->currentFor($this->alice))->toBeNull();
});

it('does not require an open shift to sell until the shop asks for it', function () {
    expect($this->service->requiredToSell())->toBeFalse();

    Setting::set('require_open_shift', '1');
    expect($this->service->requiredToSell())->toBeTrue();

    Setting::set('require_open_shift', '0');
    expect($this->service->requiredToSell())->toBeFalse();
});

it('refuses a negative float', function () {
    expect(fn () => $this->service->open($this->alice, -1, null))
        ->toThrow(DomainException::class, 'The opening float cannot be negative.');

    expect(Shift::count())->toBe(0);
});

// ---- expected by method ----

it('expects the float plus the shift cash sales in the drawer', function () {
    $shift = $this->service->open($this->alice, 5000, null);

    shiftSale($shift, ['grand_total' => 1200, 'payment_method' => 'cash']);
    shiftSale($shift, ['grand_total' => 300.55, 'payment_method' => 'cash']);
    shiftSale($shift, ['grand_total' => 800, 'payment_method' => 'pos']);
    shiftSale($shift, ['grand_total' => 400, 'payment_method' => 'transfer']);

    $expected = $this->service->expectedByMethod($shift);

    expect($expected['cash'])->toMatchArray([
        'opening_float' => 5000.0,
        'sales' => 1500.55,
        'refunds' => 0.0,
        'expected' => 6500.55,
    ]);

    // Only cash starts with a float: a card terminal holds no change.
    expect($expected['pos'])->toMatchArray(['opening_float' => 0.0, 'sales' => 800.0, 'expected' => 800.0])
        ->and($expected['transfer'])->toMatchArray(['opening_float' => 0.0, 'sales' => 400.0, 'expected' => 400.0]);
});

it('ignores sales that belong to another shift, or to no shift at all', function () {
    $mine = $this->service->open($this->alice, 1000, null);
    $theirs = $this->service->open($this->bob, 1000, null);

    shiftSale($mine, ['grand_total' => 500]);
    shiftSale($theirs, ['grand_total' => 700]);
    shiftSale(null, ['grand_total' => 900]); // predates shifts

    expect($this->service->expectedByMethod($mine)['cash']['sales'])->toBe(500.0)
        ->and($this->service->expectedByMethod($theirs)['cash']['sales'])->toBe(700.0);
});

it('leaves a voided sale out of the expected drawer', function () {
    $shift = $this->service->open($this->alice, 1000, null);

    shiftSale($shift, ['grand_total' => 500]);
    $void = shiftSale($shift, ['grand_total' => 400, 'status' => Sale::STATUS_VOIDED, 'refunded_amount' => 400]);
    // A void writes a refund row too; the money came in and went straight back out,
    // so counting it on top of excluding the sale would leave the drawer short.
    shiftRefund($void, $shift, ['total_amount' => 400, 'is_void' => true]);

    expect($this->service->expectedByMethod($shift)['cash']['expected'])->toBe(1500.0);
});

it('reduces expected cash by a cash refund, and expected pos by a pos refund', function () {
    $shift = $this->service->open($this->alice, 1000, null);

    $cashSale = shiftSale($shift, ['grand_total' => 500, 'payment_method' => 'cash']);
    $posSale = shiftSale($shift, ['grand_total' => 900, 'payment_method' => 'pos']);

    shiftRefund($cashSale, $shift, ['total_amount' => 120.50, 'method' => 'cash']);
    shiftRefund($posSale, $shift, ['total_amount' => 200, 'method' => 'pos']);

    $expected = $this->service->expectedByMethod($shift);

    expect($expected['cash'])->toMatchArray(['sales' => 500.0, 'refunds' => 120.50, 'expected' => 1379.50])
        ->and($expected['pos'])->toMatchArray(['sales' => 900.0, 'refunds' => 200.0, 'expected' => 700.0]);
});

it('takes a refund out of the drawer that paid it, not the drawer that sold the goods', function () {
    $yesterday = Shift::factory()->closed()->create(['user_id' => $this->alice->id, 'opened_at' => now()->subDay()]);
    $sale = shiftSale($yesterday, ['grand_total' => 500]);

    $today = $this->service->open($this->alice, 1000, null);
    shiftRefund($sale, $today, ['total_amount' => 500, 'method' => 'cash']);

    expect($this->service->expectedByMethod($today)['cash']['expected'])->toBe(500.0);
});

it('counts a void of an earlier shift sale as money leaving this drawer', function () {
    $yesterday = Shift::factory()->closed()->create(['user_id' => $this->alice->id, 'opened_at' => now()->subDay()]);
    $sale = shiftSale($yesterday, ['grand_total' => 300, 'status' => Sale::STATUS_VOIDED]);

    $today = $this->service->open($this->alice, 1000, null);
    shiftRefund($sale, $today, ['total_amount' => 300, 'is_void' => true]);

    expect($this->service->expectedByMethod($today)['cash']['expected'])->toBe(700.0);
});

it('adds money up to the penny instead of to a floating point approximation', function () {
    $shift = $this->service->open($this->alice, 0.1, null);

    foreach ([0.2, 33.33, 33.33, 33.34] as $amount) {
        shiftSale($shift, ['grand_total' => $amount]);
    }

    $expected = $this->service->expectedByMethod($shift);

    // Added up as raw floats these give 100.19999999999999 and 100.30000000000001.
    expect($expected['cash']['sales'])->toBe(100.2)
        ->and($expected['cash']['expected'])->toBe(100.3);
});

it('lets expected cash go negative when more was refunded than was taken', function () {
    $shift = $this->service->open($this->alice, 100, null);
    $sale = shiftSale($shift, ['grand_total' => 50]);
    shiftRefund($sale, $shift, ['total_amount' => 400, 'method' => 'cash']);

    // Not clamped to zero: a drawer that owes money has to say so.
    expect($this->service->expectedByMethod($shift)['cash']['expected'])->toBe(-250.0);
});

// ---- closing ----

it('freezes the expected figures, stores the counted ones and works out over and short', function () {
    $this->actingAs($this->alice);
    $shift = $this->service->open($this->alice, 1000, null);

    shiftSale($shift, ['grand_total' => 500, 'payment_method' => 'cash']);
    shiftSale($shift, ['grand_total' => 800, 'payment_method' => 'pos']);

    $closed = $this->service->close($shift, ['cash' => 1450, 'pos' => 800, 'transfer' => 25], 'Short a note', $this->alice);

    expect($closed->status)->toBe('closed')
        ->and($closed->isOpen())->toBeFalse()
        ->and($closed->closed_at)->not->toBeNull()
        ->and($closed->closed_by)->toBe($this->alice->id)
        ->and($closed->close_note)->toBe('Short a note')
        ->and((float) $closed->expected_cash)->toBe(1500.0)
        ->and((float) $closed->expected_pos)->toBe(800.0)
        ->and((float) $closed->expected_transfer)->toBe(0.0)
        ->and((float) $closed->counted_cash)->toBe(1450.0);

    // counted - expected: short is negative, over is positive, balanced is zero.
    expect($closed->variance_cash)->toBe(-50.0)
        ->and($closed->variance_pos)->toBe(0.0)
        ->and($closed->variance_transfer)->toBe(25.0)
        ->and($closed->variance_total)->toBe(-25.0);

    expect(AuditLog::where('table_name', 'shifts')->latest('id')->value('action'))->toContain('short by');
});

it('does not let a sale backdated after sign-off rewrite the reconciliation', function () {
    $shift = $this->service->open($this->alice, 1000, null);
    shiftSale($shift, ['grand_total' => 500]);

    $closed = $this->service->close($shift, ['cash' => 1500, 'pos' => 0, 'transfer' => 0], null, $this->alice);
    expect($closed->variance_total)->toBe(0.0);

    // Somebody adds a sale to the closed shift afterwards.
    shiftSale($closed, ['grand_total' => 9999]);

    $closed->refresh();
    expect((float) $closed->expected_cash)->toBe(1500.0)
        ->and($closed->variance_total)->toBe(0.0);

    // ...but the Z report says so out loud rather than hiding it.
    $summary = $this->service->summarise($closed);
    expect($summary['drifted'])->toBeTrue()
        ->and($summary['methods']['cash']['expected'])->toBe(1500.0)
        ->and($summary['methods']['cash']['expected_live'])->toBe(11499.0);
});

it('refuses to close a shift twice', function () {
    $shift = $this->service->open($this->alice, 1000, null);
    $this->service->close($shift, ['cash' => 1000, 'pos' => 0, 'transfer' => 0], null, $this->alice);

    expect(fn () => $this->service->close($shift->fresh(), ['cash' => 50, 'pos' => 0, 'transfer' => 0], 'second go', $this->alice))
        ->toThrow(DomainException::class, 'This shift was already closed and cannot be closed again.');

    // The first count stands: a second close must not overwrite the audit trail.
    $shift->refresh();
    expect((float) $shift->counted_cash)->toBe(1000.0)->and($shift->close_note)->toBeNull();
});

it('treats a missing counted figure as zero rather than as "expected"', function () {
    $shift = $this->service->open($this->alice, 1000, null);
    shiftSale($shift, ['grand_total' => 400, 'payment_method' => 'pos']);

    $closed = $this->service->close($shift, ['cash' => 1000], null, $this->alice);

    expect((float) $closed->counted_pos)->toBe(0.0)
        ->and($closed->variance_pos)->toBe(-400.0);
});

it('lets a manager force-close another cashier drawer and records who did it', function () {
    $manager = User::factory()->manager()->create();
    $shift = $this->service->open($this->alice, 1000, null);

    $closed = $this->service->close($shift, ['cash' => 1000, 'pos' => 0, 'transfer' => 0], 'Alice went home', $manager);

    expect($closed->closed_by)->toBe($manager->id)->and($closed->user_id)->toBe($this->alice->id);
});

// ---- the Z report figures ----

it('summarises everything the Z report needs', function () {
    Setting::set('tax_rate', '10');
    $shift = $this->service->open($this->alice, 1000, null);

    $a = shiftSale($shift, ['total_amount' => 1000, 'discount_amount' => 100, 'tax_amount' => 90, 'grand_total' => 990]);
    shiftSale($shift, ['total_amount' => 500, 'discount_amount' => 0, 'tax_amount' => 50, 'grand_total' => 550, 'payment_method' => 'pos']);
    $void = shiftSale($shift, ['grand_total' => 200, 'status' => Sale::STATUS_VOIDED]);
    shiftRefund($void, $shift, ['total_amount' => 200, 'is_void' => true]);
    shiftRefund($a, $shift, ['total_amount' => 90, 'method' => 'cash']);

    $summary = $this->service->summarise($shift);

    expect($summary)->toMatchArray([
        'sales_count' => 2,
        'subtotal' => 1500.0,
        'discounts' => 100.0,
        'tax' => 140.0,
        'gross' => 1540.0,
        'refunds' => 90.0,
        'voids_count' => 1,
        'voids' => 200.0,
        'net' => 1450.0,
        'frozen' => false,
        'drifted' => false,
    ]);

    expect($summary['methods']['cash'])->toMatchArray([
        'label' => 'Cash',
        'opening_float' => 1000.0,
        'sales' => 990.0,
        'refunds' => 90.0,
        'expected' => 1900.0,
        'counted' => null,
        'variance' => null,
    ]);

    expect($summary['expected_total'])->toBe(2450.0)
        ->and($summary['counted_total'])->toBeNull()
        ->and($summary['variance_total'])->toBeNull();
});

it('reports the counted side and the total variance once the drawer is counted', function () {
    $shift = $this->service->open($this->alice, 1000, null);
    shiftSale($shift, ['grand_total' => 500]);
    $this->service->close($shift, ['cash' => 1510, 'pos' => 0, 'transfer' => 0], null, $this->alice);

    $summary = $this->service->summarise($shift->fresh());

    expect($summary['frozen'])->toBeTrue()
        ->and($summary['counted_total'])->toBe(1510.0)
        ->and($summary['variance_total'])->toBe(10.0)
        ->and($summary['methods']['cash']['variance'])->toBe(10.0);
});
