<?php

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\Sale;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->manager = User::factory()->manager()->create();
    // AuditLog::record() only writes for a signed-in user, and every one of these
    // actions is meant to be audited.
    $this->actingAs($this->manager);

    $this->credit = new CreditService();
    $this->customer = Customer::factory()->create();
});

/**
 * An invoice on the customer's account: $total rung up, $tendered handed over now.
 * Goes through CreditService, so the balance and status are whatever the service decides.
 */
function creditInvoice(float $total, float $tendered = 0.0, array $attrs = []): Sale
{
    static $n = 0;
    $n++;

    $sale = Sale::create(array_merge([
        'invoice_number' => 'INV-TEST-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'user_id' => test()->manager->id,
        'customer_id' => test()->customer->id,
        'total_amount' => $total,
        'grand_total' => $total,
        'payment_method' => 'cash',
        'paid_amount' => 0,
        'sale_date' => now(),
        'status' => Sale::STATUS_COMPLETED,
    ], $attrs));

    // Spread them out in the past so "oldest first" is never ambiguous.
    // created_at is not fillable, so it has to be forced.
    $sale->forceFill(['created_at' => now()->subYear()->addMinutes($n)])->save();

    test()->credit->recordCreditSale($sale, $tendered);

    return $sale->fresh();
}

/** The allocation amounts of a payment, in the order they were written. */
function allocationAmounts(CustomerPayment $payment): array
{
    return $payment->allocations()->orderBy('id')->pluck('amount')->map(fn($a) => round((float) $a, 2))->all();
}

// ---- recordCreditSale: what the till collected, and what is left on the account ----

test('a sale paid in full at the till is not credit', function () {
    $sale = creditInvoice(500, 500);

    expect($sale->payment_status)->toBe('paid')
        ->and($sale->outstanding)->toBe(0.0)
        ->and((float) $sale->paid_amount)->toBe(500.0)
        ->and($sale->isCredit())->toBeFalse();
});

test('a part payment leaves the rest on the account', function () {
    $sale = creditInvoice(500, 120.50);

    expect($sale->payment_status)->toBe('partial')
        ->and($sale->outstanding)->toBe(379.50)
        ->and((float) $sale->paid_amount)->toBe(120.50)
        ->and($sale->isCredit())->toBeTrue();
});

test('a sale with nothing tendered is unpaid, not partial', function () {
    $sale = creditInvoice(500);

    expect($sale->payment_status)->toBe('unpaid')
        ->and($sale->outstanding)->toBe(500.0)
        ->and((float) $sale->paid_amount)->toBe(0.0);
});

test('money above the total is change, not credit', function () {
    $sale = creditInvoice(500, 600);

    expect($sale->payment_status)->toBe('paid')
        ->and($sale->outstanding)->toBe(0.0)
        ->and((float) $sale->paid_amount)->toBe(500.0);
});

test('credit cannot be left on a sale with nobody to collect it from', function () {
    $sale = Sale::create([
        'invoice_number' => 'INV-NO-CUSTOMER',
        'user_id' => $this->manager->id,
        'total_amount' => 100,
        'grand_total' => 100,
        'payment_method' => 'cash',
        'paid_amount' => 0,
        'sale_date' => now(),
        'status' => Sale::STATUS_COMPLETED,
    ]);

    expect(fn() => $this->credit->recordCreditSale($sale, 40))
        ->toThrow(DomainException::class);

    // A sale paid in full needs no customer.
    $this->credit->recordCreditSale($sale, 100);
    expect($sale->fresh()->payment_status)->toBe('paid');
});

test('a negative tender is refused, and an inactive customer cannot buy on credit', function () {
    $sale = creditInvoice(100, 100);

    expect(fn() => $this->credit->recordCreditSale($sale, -5))->toThrow(DomainException::class, 'negative');

    $this->customer->update(['is_active' => false]);
    expect(fn() => $this->credit->recordCreditSale($sale, 50))->toThrow(DomainException::class, 'not active');
});

// ---- the credit limit ----

test('a credit sale that would push the customer past their limit is refused', function () {
    $this->customer->update(['credit_limit' => 1000]);

    creditInvoice(800);                                      // 800 owed, 200 left
    expect($this->credit->remainingCredit($this->customer->fresh()))->toBe(200.0);

    $over = Sale::create([
        'invoice_number' => 'INV-OVER-LIMIT',
        'user_id' => $this->manager->id,
        'customer_id' => $this->customer->id,
        'total_amount' => 300, 'grand_total' => 300,
        'payment_method' => 'cash', 'paid_amount' => 0,
        'sale_date' => now(), 'status' => Sale::STATUS_COMPLETED,
    ]);

    expect(fn() => $this->credit->recordCreditSale($over, 0))
        ->toThrow(DomainException::class, 'credit limit');

    // Untouched: the sale is left for the caller's transaction to roll back.
    expect($over->fresh()->outstanding)->toBe(0.0)
        ->and($this->credit->balanceFor($this->customer))->toBe(800.0);

    // Exactly up to the limit is allowed; a part payment at the till brings it inside.
    $this->credit->recordCreditSale($over, 100);
    expect($over->fresh()->outstanding)->toBe(200.0)
        ->and($this->credit->remainingCredit($this->customer->fresh()))->toBe(0.0);
});

test('a credit limit of zero means no limit at all', function () {
    expect((float) $this->customer->credit_limit)->toBe(0.0);

    creditInvoice(999999);

    expect($this->credit->remainingCredit($this->customer->fresh()))->toBeNull()
        ->and($this->credit->canTakeCredit($this->customer->fresh(), 500000))->toBeTrue();
});

test('the limit check ignores the sale being rung up, so its own balance is not counted twice', function () {
    $this->customer->update(['credit_limit' => 500]);
    creditInvoice(400);                     // 400 already owed
    $ringingUp = creditInvoice(100);        // and this one has just gone on account

    // Asked about the sale being rung up, its own balance is left out: 400 owed, 100 of headroom.
    expect($this->credit->canTakeCredit($this->customer->fresh(), 100, $ringingUp))->toBeTrue()
        ->and($this->credit->canTakeCredit($this->customer->fresh(), 150, $ringingUp))->toBeFalse()
        // Not left out, its 100 is counted twice and nothing more fits.
        ->and($this->credit->canTakeCredit($this->customer->fresh(), 100))->toBeFalse();
});

test('an inactive customer can never take credit', function () {
    $this->customer->update(['is_active' => false]);

    expect($this->credit->canTakeCredit($this->customer, 1))->toBeFalse();
});

// ---- applyPayment ----

test('a payment is applied to the oldest invoices first', function () {
    $first = creditInvoice(100);
    $second = creditInvoice(200);
    $third = creditInvoice(300);

    $payment = $this->credit->applyPayment($this->customer, 250, 'cash', $this->manager);

    expect(allocationAmounts($payment))->toBe([100.0, 150.0])
        ->and($first->fresh()->outstanding)->toBe(0.0)
        ->and($first->fresh()->payment_status)->toBe('paid')
        ->and($second->fresh()->outstanding)->toBe(50.0)
        ->and($second->fresh()->payment_status)->toBe('partial')
        ->and($third->fresh()->outstanding)->toBe(300.0)
        ->and($third->fresh()->payment_status)->toBe('unpaid')
        ->and($this->credit->balanceFor($this->customer))->toBe(350.0);

    // One allocation row per invoice touched, and they add up to the payment.
    expect(CustomerPaymentAllocation::count())->toBe(2)
        ->and(round((float) CustomerPaymentAllocation::sum('amount'), 2))->toBe(250.0);
});

test('a payment can be aimed at one named invoice instead of the queue', function () {
    $old = creditInvoice(100);
    $new = creditInvoice(200);

    $payment = $this->credit->applyPayment($this->customer, 200, 'transfer', $this->manager, $new);

    expect(allocationAmounts($payment))->toBe([200.0])
        ->and($new->fresh()->payment_status)->toBe('paid')
        ->and($old->fresh()->outstanding)->toBe(100.0);   // the older one is untouched
});

test('a payment aimed at an invoice that is not theirs, or is settled, is refused', function () {
    $mine = creditInvoice(100);
    $settled = creditInvoice(50, 50);

    $other = Customer::factory()->create();
    $theirs = Sale::create([
        'invoice_number' => 'INV-SOMEONE-ELSE',
        'user_id' => $this->manager->id, 'customer_id' => $other->id,
        'total_amount' => 70, 'grand_total' => 70,
        'payment_method' => 'cash', 'paid_amount' => 0,
        'sale_date' => now(), 'status' => Sale::STATUS_COMPLETED,
    ]);
    $this->credit->recordCreditSale($theirs, 0);

    expect(fn() => $this->credit->applyPayment($this->customer, 10, 'cash', $this->manager, $theirs))
        ->toThrow(DomainException::class, 'belongs to someone else');
    expect(fn() => $this->credit->applyPayment($this->customer, 10, 'cash', $this->manager, $settled))
        ->toThrow(DomainException::class, 'nothing left to pay');

    expect(CustomerPayment::count())->toBe(0)
        ->and($mine->fresh()->outstanding)->toBe(100.0)
        ->and($theirs->fresh()->outstanding)->toBe(70.0);
});

test('more than is owed is refused outright, and nothing is written', function () {
    $sale = creditInvoice(100);

    expect(fn() => $this->credit->applyPayment($this->customer, 100.01, 'cash', $this->manager))
        ->toThrow(DomainException::class, 'more than is owed');

    expect(CustomerPayment::count())->toBe(0)
        ->and(CustomerPaymentAllocation::count())->toBe(0)
        ->and($sale->fresh()->outstanding)->toBe(100.0)
        ->and($sale->fresh()->payment_status)->toBe('unpaid');

    // Exactly the balance is fine, and settles the account.
    $this->credit->applyPayment($this->customer, 100, 'cash', $this->manager);
    expect($this->credit->balanceFor($this->customer))->toBe(0.0);
});

test('zero, negative and unknown-method payments are refused', function () {
    creditInvoice(100);

    expect(fn() => $this->credit->applyPayment($this->customer, 0, 'cash', $this->manager))->toThrow(DomainException::class);
    expect(fn() => $this->credit->applyPayment($this->customer, -10, 'cash', $this->manager))->toThrow(DomainException::class);
    expect(fn() => $this->credit->applyPayment($this->customer, 10, 'bitcoin', $this->manager))->toThrow(DomainException::class);

    expect(CustomerPayment::count())->toBe(0);
});

test('paying a customer who owes nothing is refused', function () {
    expect(fn() => $this->credit->applyPayment($this->customer, 10, 'cash', $this->manager))
        ->toThrow(DomainException::class, 'nothing outstanding');
});

test('a voided invoice is not collected against', function () {
    $live = creditInvoice(100);
    $void = creditInvoice(400);
    $void->update(['status' => Sale::STATUS_VOIDED]);

    expect($this->credit->balanceFor($this->customer))->toBe(100.0);

    $payment = $this->credit->applyPayment($this->customer, 100, 'cash', $this->manager);

    expect(allocationAmounts($payment))->toBe([100.0])
        ->and($live->fresh()->payment_status)->toBe('paid')
        ->and((float) $void->fresh()->balance_due)->toBe(400.0);   // left alone, not collected
});

test('the payment is recorded with who took it, how, and when', function () {
    creditInvoice(100);

    $payment = $this->credit->applyPayment(
        $this->customer, 60, 'pos', $this->manager, null, 'TXN-99', 'Part payment', '2026-10-01'
    );

    expect((float) $payment->amount)->toBe(60.0)
        ->and($payment->method)->toBe('pos')
        ->and($payment->user_id)->toBe($this->manager->id)
        ->and($payment->customer_id)->toBe($this->customer->id)
        ->and($payment->reference)->toBe('TXN-99')
        ->and($payment->note)->toBe('Part payment')
        ->and($payment->paid_at->toDateString())->toBe('2026-10-01');
});

// ---- rounding: the allocations must add up to the payment, to the penny ----

test('allocations add up to the payment exactly, however awkward the amounts', function () {
    foreach ([
        [[33.33, 33.33, 33.33], 50.0],
        [[19.99, 0.01, 10.0], 20.0],
        [[0.03, 0.03, 0.04], 0.10],
        [[107.53, 1.99], 109.52],
        [[33.33, 33.33, 33.34], 100.0],
        [[1000.0], 333.33],
    ] as [$totals, $pay]) {
        $this->customer = Customer::factory()->create();
        foreach ($totals as $total) {
            creditInvoice($total);
        }

        $owedBefore = $this->credit->balanceFor($this->customer);
        $payment = $this->credit->applyPayment($this->customer, $pay, 'cash', $this->manager);

        $allocated = round((float) $payment->allocations()->sum('amount'), 2);

        expect($allocated)->toBe(round($pay, 2))
            ->and($this->credit->balanceFor($this->customer))->toBe(round($owedBefore - $pay, 2));
    }
});

test('a part payment that does not divide evenly leaves the pennies on the right invoice', function () {
    $a = creditInvoice(33.33);
    $b = creditInvoice(33.33);
    $c = creditInvoice(33.33);

    $payment = $this->credit->applyPayment($this->customer, 50, 'cash', $this->manager);

    // 33.33 clears the first, 16.67 goes against the second, the third is untouched.
    expect(allocationAmounts($payment))->toBe([33.33, 16.67])
        ->and($a->fresh()->outstanding)->toBe(0.0)
        ->and($b->fresh()->outstanding)->toBe(16.66)
        ->and($c->fresh()->outstanding)->toBe(33.33);
});

// ---- reversal ----

test('reversing a payment puts every invoice back exactly as it was', function () {
    creditInvoice(100);
    creditInvoice(200);
    creditInvoice(300, 50);

    $before = Sale::orderBy('id')->pluck('payment_status', 'id')->all();
    $balancesBefore = Sale::orderBy('id')->pluck('balance_due', 'id')->map(fn($b) => round((float) $b, 2))->all();

    $payment = $this->credit->applyPayment($this->customer, 350, 'cash', $this->manager);
    expect($this->credit->balanceFor($this->customer))->toBe(200.0);

    $this->credit->reversePayment($payment);

    expect(Sale::orderBy('id')->pluck('payment_status', 'id')->all())->toBe($before)
        ->and(Sale::orderBy('id')->pluck('balance_due', 'id')->map(fn($b) => round((float) $b, 2))->all())->toBe($balancesBefore)
        ->and($this->credit->balanceFor($this->customer))->toBe(550.0)
        ->and(CustomerPayment::count())->toBe(0)
        ->and(CustomerPaymentAllocation::count())->toBe(0);   // cascaded with the payment
});

test('reversing one payment leaves the others alone', function () {
    creditInvoice(100);
    creditInvoice(100);

    $one = $this->credit->applyPayment($this->customer, 100, 'cash', $this->manager);
    $two = $this->credit->applyPayment($this->customer, 60, 'cash', $this->manager);

    $this->credit->reversePayment($one);

    expect(CustomerPayment::count())->toBe(1)
        ->and(CustomerPayment::first()->id)->toBe($two->id)
        ->and($this->credit->balanceFor($this->customer))->toBe(140.0);
});

test('a reversal never leaves the till payment behind', function () {
    // 300 total, 50 tendered at the till: a reversal must restore 250 owed, not 300.
    $sale = creditInvoice(300, 50);

    $payment = $this->credit->applyPayment($this->customer, 250, 'cash', $this->manager);
    expect($sale->fresh()->payment_status)->toBe('paid');

    $this->credit->reversePayment($payment);

    expect($sale->fresh()->outstanding)->toBe(250.0)
        ->and($sale->fresh()->payment_status)->toBe('partial')
        // paid_amount stays what the till collected; later money lives in customer_payments.
        ->and((float) $sale->fresh()->paid_amount)->toBe(50.0);
});

// ---- reading balances ----

test('balances for a whole list of customers cost one query', function () {
    $others = Customer::factory()->count(4)->create();
    creditInvoice(150);
    creditInvoice(50);

    $ids = $others->pluck('id')->push($this->customer->id)->all();

    DB::enableQueryLog();
    $balances = $this->credit->balancesFor($ids);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1)
        ->and($balances[$this->customer->id])->toBe(200.0)
        ->and($balances)->not->toHaveKey($others->first()->id);   // nothing owing, not listed

    expect($this->credit->balancesFor([]))->toBe([]);
});

test('the customer model reads its own balance, and a list reads them all in one query', function () {
    creditInvoice(120.75);
    Customer::factory()->create();

    expect($this->customer->fresh()->outstanding)->toBe(120.75);

    DB::enableQueryLog();
    $customers = Customer::withOutstanding()->orderBy('id')->get();
    $balances = $customers->map(fn($c) => $c->outstanding)->all();   // no further queries
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1)->and($balances)->toBe([120.75, 0.0]);
});
