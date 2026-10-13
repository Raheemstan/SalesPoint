<?php

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->cashier = User::factory()->cashier()->create();
    $this->manager = User::factory()->manager()->create();
    $this->customer = Customer::factory()->create(['name' => 'Ade Stores', 'phone' => '08011112222']);

    $this->actingAs($this->manager);
});

/** An invoice on a customer's account, through CreditService so the balance is real. */
function debtInvoice(Customer $customer, float $total, float $tendered = 0.0, array $attrs = []): Sale
{
    static $n = 0;
    $n++;

    $sale = Sale::create(array_merge([
        'invoice_number' => 'INV-DEBT-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        'user_id' => test()->manager->id,
        'customer_id' => $customer->id,
        'total_amount' => $total,
        'grand_total' => $total,
        'payment_method' => 'cash',
        'paid_amount' => 0,
        'sale_date' => now(),
        'status' => Sale::STATUS_COMPLETED,
    ], $attrs));

    $sale->forceFill(['created_at' => now()->subYear()->addMinutes($n)])->save();

    app(CreditService::class)->recordCreditSale($sale, $tendered);

    return $sale->fresh();
}

function payDebt(Customer $customer, array $data = [])
{
    return test()->post(route('customers.payments.store', $customer), array_merge([
        'amount' => 100,
        'method' => 'cash',
    ], $data));
}

// ---- the list ----

test('the list shows each balance without a query per row, and totals what is owed', function () {
    debtInvoice($this->customer, 500, 200);              // 300 owed
    $clear = Customer::factory()->create(['name' => 'Paid Up Ltd']);
    debtInvoice($clear, 100, 100);                        // nothing owed
    $late = Customer::factory()->create(['name' => 'Late Payer']);
    debtInvoice($late, 400, 0, ['due_date' => today()->subWeek()]);

    $response = $this->get(route('customers.index'))->assertOk()
        ->assertSee('Ade Stores')->assertSee('Paid Up Ltd')->assertSee('Late Payer');

    expect($response->viewData('summary'))->toMatchArray([
        'customers' => 3,
        'owed' => 700.0,
        'overdue' => 400.0,
        'accounts' => 2,
    ]);

    // Balances come from the subquery the list already ran.
    $rows = $response->viewData('customers');
    expect($rows->firstWhere('name', 'Ade Stores')->outstanding)->toBe(300.0)
        ->and($rows->firstWhere('name', 'Paid Up Ltd')->outstanding)->toBe(0.0)
        ->and($rows->firstWhere('name', 'Late Payer')->overdue)->toBe(400.0);
});

test('the list can be searched and filtered', function () {
    debtInvoice($this->customer, 500);                                            // owes, not due
    $late = Customer::factory()->create(['name' => 'Late Payer', 'phone' => '09099998888']);
    debtInvoice($late, 400, 0, ['due_date' => today()->subDay()]);
    $off = Customer::factory()->inactive()->create(['name' => 'Closed Account']);
    $clean = Customer::factory()->create(['name' => 'Never Bought']);

    $this->get(route('customers.index', ['search' => 'Late']))->assertSee('Late Payer')->assertDontSee('Ade Stores');
    $this->get(route('customers.index', ['search' => '09099998888']))->assertSee('Late Payer');
    $this->get(route('customers.index', ['filter' => 'balance']))
        ->assertSee('Ade Stores')->assertSee('Late Payer')->assertDontSee('Never Bought');
    $this->get(route('customers.index', ['filter' => 'overdue']))
        ->assertSee('Late Payer')->assertDontSee('Ade Stores');
    $this->get(route('customers.index', ['filter' => 'inactive']))
        ->assertSee('Closed Account')->assertDontSee('Ade Stores');

    expect($clean->fresh()->outstanding)->toBe(0.0);
});

test('the list can be sorted by balance, and bad filters are rejected', function () {
    $small = Customer::factory()->create(['name' => 'Owes Little']);
    debtInvoice($small, 50);
    debtInvoice($this->customer, 900);

    $top = $this->get(route('customers.index', ['sort' => 'balance', 'direction' => 'desc']))
        ->viewData('customers')->first();
    expect($top->name)->toBe('Ade Stores');

    $bottom = $this->get(route('customers.index', ['sort' => 'balance', 'direction' => 'asc']))
        ->viewData('customers')->first();
    expect($bottom->name)->toBe('Owes Little');

    $this->get(route('customers.index', ['sort' => 'credit_limit']))->assertSessionHasErrors('sort');
    $this->get(route('customers.index', ['filter' => 'banana']))->assertSessionHasErrors('filter');
});

test('headings follow the trade, so a pharmacy does not say customer', function () {
    Setting::set('business_type', 'pharmacy');
    $this->get(route('customers.index'))->assertOk()->assertSee('Patients');

    Setting::set('business_type', 'wholesale');
    $this->get(route('customers.index'))->assertOk()->assertSee('Accounts');
});

// ---- creating and editing ----

test('a manager can add a customer, and only a name is required', function () {
    $this->post(route('customers.store'), ['name' => 'Walk In Joe'])->assertSessionHasNoErrors();

    $joe = Customer::where('name', 'Walk In Joe')->first();
    expect($joe->phone)->toBeNull()
        ->and((float) $joe->credit_limit)->toBe(0.0)
        ->and($joe->is_active)->toBeTrue();

    expect(AuditLog::where('action', 'like', 'Created customer%')->where('record_id', $joe->id)->exists())->toBeTrue();
});

test('a customer is validated and can be edited', function () {
    $this->post(route('customers.store'), ['name' => ''])->assertSessionHasErrors('name');
    $this->post(route('customers.store'), ['name' => 'X', 'email' => 'not-an-email'])->assertSessionHasErrors('email');
    $this->post(route('customers.store'), ['name' => 'X', 'credit_limit' => -5])->assertSessionHasErrors('credit_limit');

    $this->put(route('customers.update', $this->customer), [
        'name' => 'Ade Stores Ltd',
        'phone' => '08033334444',
        'credit_limit' => 25000,
        'notes' => 'Pays on Fridays',
        'is_active' => 0,                // the form posts a 0 for an unticked box
    ])->assertSessionHasNoErrors();

    $this->customer->refresh();
    expect($this->customer->name)->toBe('Ade Stores Ltd')
        ->and((float) $this->customer->credit_limit)->toBe(25000.0)
        ->and($this->customer->notes)->toBe('Pays on Fridays')
        ->and($this->customer->is_active)->toBeFalse();

    // A request that does not mention is_active is not trying to change it.
    $this->put(route('customers.update', $this->customer), ['name' => 'Ade Stores Ltd'])->assertSessionHasNoErrors();
    expect($this->customer->fresh()->is_active)->toBeFalse();
});

test('a customer with history is deactivated, never deleted', function () {
    debtInvoice($this->customer, 500);

    $this->delete(route('customers.destroy', $this->customer))->assertSessionHas('error');
    expect(Customer::find($this->customer->id))->not->toBeNull();

    // Settling the debt is not enough: the invoices still point at them.
    app(CreditService::class)->applyPayment($this->customer, 500, 'cash', $this->manager);
    $this->delete(route('customers.destroy', $this->customer))->assertSessionHas('error');
    expect(Customer::count())->toBe(1);

    // Someone who never bought anything can go.
    $fresh = Customer::factory()->create();
    $this->delete(route('customers.destroy', $fresh))->assertSessionHas('success');
    expect(Customer::find($fresh->id))->toBeNull();
});

// ---- the statement ----

test('the statement shows the invoices, the payments and a running balance', function () {
    $first = debtInvoice($this->customer, 1000, 250);                       // 750 on account
    $second = debtInvoice($this->customer, 500, 0, ['due_date' => today()->subDays(3)]);
    $cash = debtInvoice($this->customer, 90, 90);                            // paid at the till

    app(CreditService::class)->applyPayment($this->customer, 800, 'transfer', $this->manager, null, 'TRF-123');

    $response = $this->get(route('customers.show', $this->customer))->assertOk()
        ->assertSee($first->invoice_number)->assertSee($second->invoice_number)->assertSee($cash->invoice_number)
        ->assertSee('TRF-123')->assertSee('overdue');

    expect($response->viewData('balance'))->toBe(450.0);

    $ledger = $response->viewData('ledger');
    expect(collect($ledger)->last()['balance'])->toBe(450.0)
        // the till-paid invoice is listed but moves the balance by nothing
        ->and(collect($ledger)->firstWhere('reference', $cash->invoice_number)['debit'])->toBe(0.0)
        ->and(collect($ledger)->firstWhere('reference', $first->invoice_number)['debit'])->toBe(750.0);
});

test('the statement of someone who has never bought anything is empty, not broken', function () {
    $this->get(route('customers.show', $this->customer))->assertOk()->assertSee('Nothing on this account yet.');
});

// ---- taking a payment ----

test('a payment is spread over the oldest invoices and shown back to the manager', function () {
    $first = debtInvoice($this->customer, 300);
    $second = debtInvoice($this->customer, 300);

    payDebt($this->customer, ['amount' => 400, 'method' => 'transfer', 'reference' => 'TRF-9'])
        ->assertRedirect(route('customers.show', $this->customer))
        ->assertSessionHas('success');

    expect($first->fresh()->payment_status)->toBe('paid')
        ->and($second->fresh()->outstanding)->toBe(200.0)
        ->and(CustomerPaymentAllocation::count())->toBe(2)
        ->and(round((float) CustomerPaymentAllocation::sum('amount'), 2))->toBe(400.0);

    $payment = CustomerPayment::first();
    expect($payment->reference)->toBe('TRF-9')->and($payment->user_id)->toBe($this->manager->id);
    expect(AuditLog::where('table_name', 'customer_payments')->where('record_id', $payment->id)->exists())->toBeTrue();
});

test('a payment can be put against one chosen invoice', function () {
    $old = debtInvoice($this->customer, 100);
    $new = debtInvoice($this->customer, 100);

    payDebt($this->customer, ['amount' => 100, 'sale_id' => $new->id])->assertSessionHas('success');

    expect($new->fresh()->payment_status)->toBe('paid')->and($old->fresh()->outstanding)->toBe(100.0);
});

test('an overpayment is refused with the real figure and nothing is written', function () {
    debtInvoice($this->customer, 250);

    payDebt($this->customer, ['amount' => 1000])->assertSessionHas('error');

    expect(CustomerPayment::count())->toBe(0)
        ->and(app(CreditService::class)->balanceFor($this->customer))->toBe(250.0);
});

test('payment amounts and methods are validated', function () {
    debtInvoice($this->customer, 250);

    payDebt($this->customer, ['amount' => 0])->assertSessionHasErrors('amount');
    payDebt($this->customer, ['amount' => 'lots'])->assertSessionHasErrors('amount');
    payDebt($this->customer, ['method' => 'bitcoin'])->assertSessionHasErrors('method');
    payDebt($this->customer, ['sale_id' => 99999])->assertSessionHasErrors('sale_id');

    expect(CustomerPayment::count())->toBe(0);
});

test('someone else\'s invoice cannot be settled with this customer\'s money', function () {
    debtInvoice($this->customer, 100);
    $other = Customer::factory()->create();
    $theirs = debtInvoice($other, 100);

    payDebt($this->customer, ['amount' => 50, 'sale_id' => $theirs->id])->assertSessionHas('error');

    expect(CustomerPayment::count())->toBe(0)->and($theirs->fresh()->outstanding)->toBe(100.0);
});

test('a manager can reverse a payment and the balances go back', function () {
    $sale = debtInvoice($this->customer, 400);
    payDebt($this->customer, ['amount' => 400])->assertSessionHas('success');
    expect($sale->fresh()->payment_status)->toBe('paid');

    $payment = CustomerPayment::first();
    $this->delete(route('customers.payments.destroy', $payment))->assertSessionHas('success');

    expect($sale->fresh()->outstanding)->toBe(400.0)
        ->and($sale->fresh()->payment_status)->toBe('unpaid')
        ->and(CustomerPayment::count())->toBe(0)
        ->and(CustomerPaymentAllocation::count())->toBe(0);
});

// ---- the credit limit at the till ----

test('a sale that would push the customer past their credit limit is refused', function () {
    $this->customer->update(['credit_limit' => 1000]);
    debtInvoice($this->customer, 900);

    $credit = app(CreditService::class);
    expect($credit->canTakeCredit($this->customer->fresh(), 50))->toBeTrue()
        ->and($credit->canTakeCredit($this->customer->fresh(), 500))->toBeFalse();

    expect(fn() => debtInvoice($this->customer->fresh(), 500))
        ->toThrow(DomainException::class, 'credit limit');
});

// ---- who may do what ----

test('a cashier can look a customer up for the till but cannot manage them', function () {
    debtInvoice($this->customer, 400, 100);

    $this->actingAs($this->cashier);

    $found = $this->getJson(route('customers.search', ['q' => 'Ade']))->assertOk()->json();
    expect($found)->toHaveCount(1)
        ->and($found[0]['name'])->toBe('Ade Stores')
        ->and((float) $found[0]['balance'])->toBe(300.0)
        // whitelisted: the till has no business with notes or addresses
        ->and(array_keys($found[0]))->toBe(['id', 'name', 'phone', 'balance', 'credit_limit', 'credit_available']);

    $this->get(route('customers.index'))->assertForbidden();
    $this->get(route('customers.show', $this->customer))->assertForbidden();
    $this->post(route('customers.store'), ['name' => 'Sneaky'])->assertForbidden();
    $this->put(route('customers.update', $this->customer), ['name' => 'Renamed'])->assertForbidden();
    $this->delete(route('customers.destroy', $this->customer))->assertForbidden();
    payDebt($this->customer)->assertForbidden();

    expect(Customer::count())->toBe(1)
        ->and($this->customer->fresh()->name)->toBe('Ade Stores')
        ->and(CustomerPayment::count())->toBe(0);
});

test('a cashier cannot reverse a payment', function () {
    debtInvoice($this->customer, 400);
    payDebt($this->customer, ['amount' => 400]);
    $payment = CustomerPayment::first();

    $this->actingAs($this->cashier)
        ->delete(route('customers.payments.destroy', $payment))->assertForbidden();

    expect(CustomerPayment::count())->toBe(1);
});

test('the search is capped, skips inactive customers and escapes wildcards', function () {
    Customer::factory()->count(25)->create(['name' => 'Bulk Buyer']);
    Customer::factory()->inactive()->create(['name' => 'Bulk Buyer Closed']);
    Customer::factory()->create(['name' => '100% Discount Ltd']);

    $this->actingAs($this->cashier);

    expect($this->getJson(route('customers.search', ['q' => 'Bulk']))->json())->toHaveCount(20);

    // A "%" typed in the box is escaped, so it cannot become "match everything".
    $hits = collect($this->getJson(route('customers.search', ['q' => '100%']))->json());
    expect($hits->pluck('name'))->not->toContain('Bulk Buyer');

    expect(collect($this->getJson(route('customers.search', ['q' => 'Closed']))->json()))->toHaveCount(0);
});

test('credit screens need a login', function () {
    auth()->logout();

    $this->get(route('customers.index'))->assertRedirect(route('login'));
    $this->get(route('customers.search'))->assertRedirect(route('login'));
    $this->post(route('customers.payments.store', $this->customer))->assertRedirect(route('login'));
});

test('the migration rolls back and reapplies cleanly', function () {
    $migration = require base_path('database/migrations/2026_10_14_000001_add_credit_sales_and_customer_ledger.php');

    $migration->down();

    expect(Schema::hasTable('customer_payments'))->toBeFalse()
        ->and(Schema::hasTable('customer_payment_allocations'))->toBeFalse()
        ->and(Schema::hasColumn('sales', 'payment_status'))->toBeFalse()
        ->and(Schema::hasColumn('sales', 'balance_due'))->toBeFalse()
        ->and(Schema::hasColumn('customers', 'credit_limit'))->toBeFalse()
        ->and(Schema::hasColumn('customers', 'notes'))->toBeFalse()
        // What the rest of the app relies on survives the rollback.
        ->and(Schema::hasColumn('sales', 'grand_total'))->toBeTrue()
        ->and(Schema::hasColumn('sales', 'refunded_amount'))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'phone'))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'name'))->toBeTrue();

    $migration->up();

    expect(Schema::hasTable('customer_payments'))->toBeTrue()
        ->and(Schema::hasColumn('sales', 'payment_status'))->toBeTrue()
        ->and(Schema::hasColumn('sales', 'due_date'))->toBeTrue()
        ->and(Schema::hasColumn('customers', 'credit_limit'))->toBeTrue();

    // Every existing sale is backfilled as paid, so history does not read as debt.
    expect(Sale::where('payment_status', 'paid')->count())->toBe(Sale::count());
});
