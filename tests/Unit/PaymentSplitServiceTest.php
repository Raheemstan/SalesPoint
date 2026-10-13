<?php

use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\PaymentSplitService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * The unit suite is not bound to the application test case in tests/Pest.php
 * (that only covers Feature), and this service writes rows and formats refusals
 * with money() / term(), both of which read settings.
 */
uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->split = new PaymentSplitService();
    $this->cashier = User::factory()->cashier()->create();
});

/** Shorthand for a tender row as the request supplies it. */
function tender(string $method, $amount, ?string $reference = null): array
{
    return ['method' => $method, 'amount' => $amount, 'reference' => $reference];
}

/** A bare completed sale to hang tender rows on. */
function saleWorth(float $grandTotal): Sale
{
    static $n = 0;
    $n++;

    return Sale::create([
        'invoice_number' => 'INV-SPLIT-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'user_id' => test()->cashier->id,
        'total_amount' => $grandTotal,
        'grand_total' => $grandTotal,
        'payment_method' => 'cash',
        'paid_amount' => $grandTotal,
        'sale_date' => now(),
        'status' => Sale::STATUS_COMPLETED,
    ]);
}

/** The applied amount of each row in a plan, keyed by method. */
function appliedByMethod(array $plan): array
{
    $out = [];

    foreach ($plan['tenders'] as $row) {
        $out[$row['method']] = $row['applied'];
    }

    return $out;
}

// ---- the simple cases ----

test('one tender that covers the total applies all of it and leaves no change', function () {
    $plan = $this->split->plan([tender('cash', 500)], 500.0);

    expect($plan['tendered'])->toBe(500.0)
        ->and($plan['applied'])->toBe(500.0)
        ->and($plan['change'])->toBe(0.0)
        ->and($plan['credit'])->toBe(0.0)
        ->and($plan['dominant'])->toBe('cash')
        ->and(appliedByMethod($plan))->toBe(['cash' => 500.0]);
});

test('a cash row records the money kept, not the note handed over', function () {
    // 215 sale, 500 note: 285 goes back, so the drawer kept 215.
    $plan = $this->split->plan([tender('cash', 500)], 215.0);

    expect($plan['change'])->toBe(285.0)
        ->and($plan['applied'])->toBe(215.0)
        ->and(appliedByMethod($plan))->toBe(['cash' => 215.0]);
});

// ---- splits ----

test('a split adds up to the grand total exactly', function () {
    $plan = $this->split->plan([tender('cash', 1200), tender('transfer', 800)], 2000.0);

    expect($plan['tendered'])->toBe(2000.0)
        ->and($plan['change'])->toBe(0.0)
        ->and(appliedByMethod($plan))->toBe(['cash' => 1200.0, 'transfer' => 800.0])
        ->and(round(array_sum(array_column($plan['tenders'], 'applied')), 2))->toBe(2000.0);
});

test('change in a split comes out of the cash part only', function () {
    // 1,000 sale: 400 on the card, and a 1,000 note for the remaining 600.
    $plan = $this->split->plan([tender('cash', 1000), tender('pos', 400)], 1000.0);

    expect($plan['tendered'])->toBe(1400.0)
        ->and($plan['change'])->toBe(400.0)
        // The card row is untouched; the whole 400 of change comes off the cash.
        ->and(appliedByMethod($plan))->toBe(['cash' => 600.0, 'pos' => 400.0])
        ->and(round(array_sum(array_column($plan['tenders'], 'applied')), 2))->toBe(1000.0);
});

test('awkward thirds still add up to the penny', function () {
    $plan = $this->split->plan([
        tender('cash', 3.34),
        tender('pos', 3.33),
        tender('transfer', 3.33),
    ], 10.0);

    expect(round(array_sum(array_column($plan['tenders'], 'applied')), 2))->toBe(10.0)
        ->and($plan['change'])->toBe(0.0)
        ->and($plan['credit'])->toBe(0.0);
});

test('a split that under-pays is refused, in the words the till has always used', function () {
    expect(fn () => $this->split->plan([tender('cash', 500), tender('pos', 200)], 1000.0))
        ->toThrow(DomainException::class, 'Insufficient payment.');
});

test('an under-paying split is allowed when the rest is going on credit, and says how much', function () {
    $plan = $this->split->plan([tender('cash', 500), tender('pos', 200)], 1000.0, true);

    expect($plan['tendered'])->toBe(700.0)
        ->and($plan['applied'])->toBe(700.0)
        ->and($plan['credit'])->toBe(300.0)
        ->and($plan['change'])->toBe(0.0);
});

test('a whole sale on credit tenders nothing, and still names a method for the column', function () {
    $plan = $this->split->plan([tender('cash', 0)], 1000.0, true);

    expect($plan['tendered'])->toBe(0.0)
        ->and($plan['credit'])->toBe(1000.0)
        ->and($plan['dominant'])->toBe('cash');
});

// ---- the rules that stop money leaking ----

test('in a split, the card and transfer parts cannot come to more than the sale', function () {
    // "cash 0, transfer 10,000" against a 2,000 sale would open the drawer for 8,000.
    expect(fn () => $this->split->plan([tender('cash', 0), tender('transfer', 10000)], 2000.0))
        ->toThrow(DomainException::class, 'Change can only be given in cash');
});

test('a single card or transfer tender may still exceed the total, as the till has always allowed', function () {
    // One tender is unambiguous: 'amount received' has always been allowed to
    // exceed the total, with the difference recorded as change.
    $plan = $this->split->plan([tender('transfer', 100000)], 100.0);

    expect($plan['change'])->toBe(99900.0)
        // The row still holds only what the sale actually took.
        ->and(appliedByMethod($plan))->toBe(['transfer' => 100.0]);
});

test('the same method twice is refused rather than quietly summed', function () {
    expect(fn () => $this->split->plan([tender('cash', 100), tender('cash', 50)], 150.0))
        ->toThrow(DomainException::class, 'Cash is entered twice');
});

test('an unknown method, a negative amount and a non-number are all refused', function () {
    expect(fn () => $this->split->plan([tender('bitcoin', 100)], 100.0))
        ->toThrow(DomainException::class, 'Unknown payment method.');

    expect(fn () => $this->split->plan([tender('cash', -5)], 100.0))
        ->toThrow(DomainException::class, 'A payment cannot be negative.');

    expect(fn () => $this->split->plan([tender('cash', 'lots')], 100.0))
        ->toThrow(DomainException::class, 'A payment amount must be a number.');
});

test('no tenders at all, and more than one per method, are refused', function () {
    expect(fn () => $this->split->plan([], 100.0))->toThrow(DomainException::class);

    expect(fn () => $this->split->plan([
        tender('cash', 10), tender('pos', 10), tender('transfer', 10), tender('cash', 10),
    ], 40.0))->toThrow(DomainException::class, 'at most 3 payment methods');
});

// ---- the dominant tender ----

test('the dominant tender is the largest, which is what goes in sales.payment_method', function () {
    $plan = $this->split->plan([tender('cash', 300), tender('transfer', 700)], 1000.0);

    expect($plan['dominant'])->toBe('transfer');
});

test('a tie goes to whichever the cashier keyed first', function () {
    $plan = $this->split->plan([tender('pos', 500), tender('cash', 500)], 1000.0);

    expect($plan['dominant'])->toBe('pos');
});

test('the dominant tender is judged on money kept, not money handed over', function () {
    // 600 cash for a 1,000 sale with 400 on the card: the cash note is bigger,
    // but after change the card kept more.
    $plan = $this->split->plan([tender('cash', 1000), tender('pos', 400)], 1000.0);

    expect(appliedByMethod($plan))->toBe(['cash' => 600.0, 'pos' => 400.0])
        ->and($plan['dominant'])->toBe('cash');
});

// ---- writing the rows ----

test('record writes one row per tender, with the money kept and any reference', function () {
    $sale = saleWorth(2000.0);
    $plan = $this->split->plan([
        tender('cash', 1200),
        tender('transfer', 800, 'TRF-99'),
    ], 2000.0);

    $this->split->record($sale, $plan);

    $rows = SalePayment::where('sale_id', $sale->id)->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->method)->toBe('cash')
        ->and((float) $rows[0]->amount)->toBe(1200.0)
        ->and($rows[0]->reference)->toBeNull()
        ->and($rows[1]->method)->toBe('transfer')
        ->and((float) $rows[1]->amount)->toBe(800.0)
        ->and($rows[1]->reference)->toBe('TRF-99')
        ->and(round((float) $rows->sum('amount'), 2))->toBe(2000.0);
});

test('a tender that brought no money in writes no row', function () {
    $sale = saleWorth(1000.0);

    // The whole sale on account: no money arrived, so there is nothing to record.
    $this->split->record($sale, $this->split->plan([tender('cash', 0)], 1000.0, true));

    expect(SalePayment::where('sale_id', $sale->id)->count())->toBe(0);

    // A deposit writes one row for the deposit and nothing for the rest.
    $deposit = saleWorth(1000.0);
    $this->split->record($deposit, $this->split->plan([tender('cash', 250), tender('pos', 0)], 1000.0, true));

    $rows = SalePayment::where('sale_id', $deposit->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->method)->toBe('cash')
        ->and((float) $rows[0]->amount)->toBe(250.0);
});

test('a blank reference is stored as nothing rather than an empty string', function () {
    $sale = saleWorth(100.0);

    $this->split->record($sale, $this->split->plan([tender('pos', 100, '   ')], 100.0));

    expect(SalePayment::where('sale_id', $sale->id)->value('reference'))->toBeNull();
});

test('a sale that comes to nothing is settled by tendering nothing', function () {
    // A 100% discount is allowed, so a zero-total sale has to be ringable.
    $plan = $this->split->plan([tender('cash', 0)], 0.0);

    expect($plan['applied'])->toBe(0.0)
        ->and($plan['change'])->toBe(0.0)
        ->and($plan['credit'])->toBe(0.0);
});
