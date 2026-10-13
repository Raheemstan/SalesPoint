<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\User;
use App\Services\CreditService;
use App\Services\RefundService;
use App\Services\ShiftService;

/**
 * Refunding a sale that was taken on credit.
 *
 * The two features were built separately, and the seam between them is where the
 * money goes wrong: returning goods on an unpaid invoice has to reduce the debt
 * before it hands over any cash, and the part that only cancelled debt must not
 * be counted as money out of the drawer at closing time.
 */
beforeEach(function () {
    $this->manager = User::factory()->manager()->create();
    $this->customer = Customer::factory()->create(['credit_limit' => 0]); // 0 = no limit
    $this->product = Product::factory()->create([
        'cost_price' => 40,
        'sale_price' => 100,
        'stock_quantity' => 50,
    ]);

    $this->credit = app(CreditService::class);
    $this->refunds = app(RefundService::class);
});

/** A credit sale of $qty units at 100 each, with $tendered paid at the till. */
function creditSale(int $qty, float $tendered): Sale
{
    $total = $qty * 100;

    $sale = Sale::create([
        'invoice_number' => 'INV-' . uniqid(),
        'user_id' => test()->manager->id,
        'customer_id' => test()->customer->id,
        'total_amount' => $total,
        'grand_total' => $total,
        'payment_method' => 'cash',
        'paid_amount' => $tendered,
        'change_due' => 0,
        'sale_date' => now(),
        'status' => Sale::STATUS_COMPLETED,
    ]);

    $sale->items()->create([
        'product_id' => test()->product->id,
        'quantity' => $qty,
        'price' => 100,
        'cost_price' => 40,
        'total' => $total,
        'paid_total' => $total,
    ]);

    test()->credit->recordCreditSale($sale, $tendered);

    return $sale->fresh();
}

test('refunding part of an unpaid invoice reduces the debt instead of paying out cash', function () {
    // 1,000 invoice, 400 tendered, so 600 is still owed.
    $sale = creditSale(10, 400);
    expect((float) $sale->balance_due)->toBe(600.0);

    // Three units come back: 300 of value.
    $item = $sale->items->first();
    $refund = $this->refunds->refund($sale, [$item->id => 3], [$item->id => true], 'changed mind', 'cash', $this->manager);

    // The debt drops by the full 300 and nothing leaves the drawer.
    expect((float) $refund->total_amount)->toBe(300.0)
        ->and((float) $refund->credit_applied)->toBe(300.0)
        ->and($refund->cash_out)->toBe(0.0)
        ->and((float) $sale->fresh()->balance_due)->toBe(300.0)
        ->and($sale->fresh()->payment_status)->toBe(Sale::PAYMENT_PARTIAL);
});

test('a refund larger than the outstanding debt clears it and pays out only the excess', function () {
    // 1,000 invoice, 400 tendered, 600 owed.
    $sale = creditSale(10, 400);

    // Eight units back: 800 of value against 600 of debt.
    $item = $sale->items->first();
    $refund = $this->refunds->refund($sale, [$item->id => 8], [$item->id => true], 'faulty', 'cash', $this->manager);

    // 600 cancels the debt; the remaining 200 is genuinely handed over.
    expect((float) $refund->credit_applied)->toBe(600.0)
        ->and($refund->cash_out)->toBe(200.0)
        ->and((float) $sale->fresh()->balance_due)->toBe(0.0)
        ->and($sale->fresh()->payment_status)->toBe(Sale::PAYMENT_PAID);
});

test('refunding a sale that was paid in full still pays out in full', function () {
    $sale = creditSale(5, 500); // nothing owed
    expect((float) $sale->balance_due)->toBe(0.0);

    $item = $sale->items->first();
    $refund = $this->refunds->refund($sale, [$item->id => 2], [$item->id => true], 'changed mind', 'cash', $this->manager);

    expect((float) $refund->credit_applied)->toBe(0.0)
        ->and($refund->cash_out)->toBe(200.0);
});

test('voiding an unpaid credit sale writes the debt off rather than handing money over', function () {
    $sale = creditSale(4, 0); // 400 invoice, nothing paid

    $refund = $this->refunds->void($sale, 'rung up in error', 'cash', $this->manager);

    expect((float) $refund->total_amount)->toBe(400.0)
        ->and((float) $refund->credit_applied)->toBe(400.0)
        ->and($refund->cash_out)->toBe(0.0)
        ->and((float) $sale->fresh()->balance_due)->toBe(0.0)
        ->and($sale->fresh()->status)->toBe(Sale::STATUS_VOIDED);
});

test('the customer balance follows the refund, so they are not chased for returned goods', function () {
    $sale = creditSale(10, 400);
    expect($this->credit->balanceFor($this->customer))->toBe(600.0);

    $item = $sale->items->first();
    $this->refunds->refund($sale, [$item->id => 3], [$item->id => true], 'changed mind', 'cash', $this->manager);

    expect($this->credit->balanceFor($this->customer->fresh()))->toBe(300.0);
});

test('a shift expects only the cash that really left the drawer, not the debt written off', function () {
    $shifts = app(ShiftService::class);
    $shift = $shifts->open($this->manager, 1000, null);

    // A credit sale rung up on this shift: 400 of the 1,000 goes in the drawer.
    $sale = creditSale(10, 400);
    $sale->update(['shift_id' => $shift->id]);

    expect($shifts->expectedByMethod($shift)['cash']['expected'])->toBe(1400.0);

    // 300 of goods come back. That only cancels debt, so the drawer is untouched.
    $item = $sale->items->first();
    $this->refunds->refund($sale, [$item->id => 3], [$item->id => true], 'changed mind', 'cash', $this->manager);

    expect($shifts->expectedByMethod($shift->fresh())['cash']['expected'])->toBe(1400.0);

    // A further 500 back exceeds the 300 still owed, so 200 is paid out for real.
    $this->refunds->refund($sale->fresh(), [$item->id => 5], [$item->id => true], 'faulty', 'cash', $this->manager);

    expect($shifts->expectedByMethod($shift->fresh())['cash']['expected'])->toBe(1200.0);
});
