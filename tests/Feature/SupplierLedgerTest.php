<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\PurchaseReturnService;
use App\Services\SupplierLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->user = User::factory()->manager()->create();
    $this->actingAs($this->user);
    $this->supplier = Supplier::factory()->create(['opening_balance' => 0]);
    $this->ledger = app(SupplierLedgerService::class);
});

/** A delivery that has arrived, with its stock already added. */
function ledgerPurchase(Supplier $supplier, float $total, array $overrides = []): Purchase
{
    return Purchase::factory()->create(array_merge([
        'supplier_id' => $supplier->id,
        'user_id' => test()->user->id,
        'total_amount' => $total,
        'status' => Purchase::STATUS_RECEIVED,
        'amount_paid' => 0,
        'balance_due' => $total,
    ], $overrides));
}

test('a supplier balance is the opening figure plus what they invoiced, less what they were paid and credited', function () {
    $supplier = Supplier::factory()->create(['opening_balance' => 100]);

    expect($this->ledger->balance($supplier))->toBe(100.0);

    // Invoiced 500, 50 handed over on delivery.
    ledgerPurchase($supplier, 500, ['amount_paid' => 50, 'balance_due' => 450]);
    expect($this->ledger->balance($supplier))->toBe(550.0);

    // 200 sent later, against the account rather than one invoice.
    $this->post(route('suppliers.payments.store', $supplier), ['amount' => 200, 'method' => 'transfer'])
        ->assertSessionHas('success');
    expect($this->ledger->balance($supplier))->toBe(350.0);

    // Goods sent back reduce what is owed.
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $purchase = ledgerPurchase($supplier, 60);
    PurchaseItem::create([
        'purchase_id' => $purchase->id, 'product_id' => $product->id,
        'quantity' => 6, 'cost_price' => 10, 'total' => 60,
    ]);
    $product->increment('stock_quantity', 6);

    expect($this->ledger->balance($supplier))->toBe(410.0); // the new 60 invoice

    app(PurchaseReturnService::class)->returnToSupplier(
        $purchase,
        [$purchase->purchaseItems()->first()->id => 2],
        'Damaged',
        $this->user
    );

    expect($this->ledger->balance($supplier))->toBe(390.0);
});

test('reversing a payment puts the balance straight back up', function () {
    ledgerPurchase($this->supplier, 300);

    $this->post(route('suppliers.payments.store', $this->supplier), ['amount' => 120, 'method' => 'cash']);
    expect($this->ledger->balance($this->supplier))->toBe(180.0);

    $payment = SupplierPayment::first();

    $this->delete(route('suppliers.payments.destroy', $payment))->assertSessionHas('success');

    expect($this->ledger->balance($this->supplier))->toBe(300.0)
        ->and(SupplierPayment::count())->toBe(0)
        ->and(AuditLog::where('action', 'like', 'Reversed%')->exists())->toBeTrue();
});

test('an order that has not arrived is not a debt yet', function () {
    ledgerPurchase($this->supplier, 400, ['status' => Purchase::STATUS_ORDERED, 'received_at' => null]);
    ledgerPurchase($this->supplier, 90, ['status' => Purchase::STATUS_CANCELLED]);

    expect($this->ledger->balance($this->supplier))->toBe(0.0)
        ->and($this->ledger->summary($this->supplier)['ordered'])->toBe(400.0);
});

test('a payment is validated and only a sane method is accepted', function () {
    foreach ([
        ['amount' => 0, 'method' => 'cash'],
        ['amount' => -5, 'method' => 'cash'],
        ['amount' => 10, 'method' => 'bitcoin'],
        ['method' => 'cash'],
    ] as $payload) {
        $this->post(route('suppliers.payments.store', $this->supplier), $payload)->assertSessionHasErrors();
    }

    expect(SupplierPayment::count())->toBe(0);
});

test('balances for a whole page of suppliers cost the same few queries as one', function () {
    $suppliers = Supplier::factory()->count(6)->create();

    foreach ($suppliers as $supplier) {
        ledgerPurchase($supplier, 100, ['amount_paid' => 10, 'balance_due' => 90]);
        SupplierPayment::factory()->create(['supplier_id' => $supplier->id, 'user_id' => $this->user->id, 'amount' => 5]);
    }

    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });

    $balances = $this->ledger->balances($suppliers);

    // Opening, purchases, payments, returns: four, however many suppliers there are.
    expect($count)->toBe(4)
        ->and($balances)->toHaveCount(6)
        ->and(array_values($balances)[0])->toBe(85.0);
});

test('the supplier list shows a balance per row and filters by status', function () {
    $owing = Supplier::factory()->create(['name' => 'Owed Trading', 'opening_balance' => 250]);
    $quiet = Supplier::factory()->inactive()->create(['name' => 'Dormant Dealers']);

    $this->get(route('suppliers.index'))->assertOk()
        ->assertSee('Owed Trading')
        ->assertSee('Dormant Dealers')
        ->assertSee(money(250));

    $this->get(route('suppliers.index', ['status' => 'active']))
        ->assertSee('Owed Trading')->assertDontSee('Dormant Dealers');

    $this->get(route('suppliers.index', ['status' => 'inactive']))
        ->assertSee('Dormant Dealers')->assertDontSee('Owed Trading');

    $this->get(route('suppliers.index', ['search' => 'Dormant']))
        ->assertSee('Dormant Dealers')->assertDontSee('Owed Trading');

    $this->get(route('suppliers.index', ['status' => 'nonsense']))->assertSessionHasErrors('status');
    $this->get(route('suppliers.show', $owing))->assertOk()->assertSee('Owed Trading');
});

test('a supplier can be edited without losing what is not on the form', function () {
    $this->put(route('suppliers.update', $this->supplier), [
        'name' => 'Renamed Ltd',
        'phone' => '08099',
        'opening_balance' => 75,
        'notes' => 'Pays on the 1st',
        'is_active' => '0',
    ])->assertSessionHas('success');

    $fresh = $this->supplier->fresh();

    expect($fresh->name)->toBe('Renamed Ltd')
        ->and((float) $fresh->opening_balance)->toBe(75.0)
        ->and($fresh->notes)->toBe('Pays on the 1st')
        ->and($fresh->is_active)->toBeFalse();

    // The inline-add shape has no opening balance or active flag; neither is wiped.
    $this->put(route('suppliers.update', $this->supplier), ['name' => 'Renamed Ltd', 'phone' => '08099'])
        ->assertSessionHas('success');

    expect((float) $this->supplier->fresh()->opening_balance)->toBe(75.0)
        ->and($this->supplier->fresh()->is_active)->toBeFalse();

    $this->put(route('suppliers.update', $this->supplier), ['phone' => '1'])->assertSessionHasErrors('name');
});

test('a supplier with purchases is deactivated rather than deleted, and one without is deleted', function () {
    ledgerPurchase($this->supplier, 100);

    $this->delete(route('suppliers.destroy', $this->supplier))->assertSessionHas('success');

    expect(Supplier::find($this->supplier->id))->not->toBeNull()
        ->and($this->supplier->fresh()->is_active)->toBeFalse()
        ->and(Purchase::count())->toBe(1);

    // Already inactive and still referenced: now it is simply refused.
    $this->delete(route('suppliers.destroy', $this->supplier))->assertSessionHas('error');
    expect(Supplier::find($this->supplier->id))->not->toBeNull();

    // A supplier nobody ever bought from really does go.
    $unused = Supplier::factory()->create();
    SupplierPayment::factory()->create(['supplier_id' => $unused->id, 'user_id' => $this->user->id]);

    $this->delete(route('suppliers.destroy', $unused))
        ->assertRedirect(route('suppliers.index'))->assertSessionHas('success');

    expect(Supplier::find($unused->id))->toBeNull()
        ->and(SupplierPayment::where('supplier_id', $unused->id)->count())->toBe(0);
});

test('a soft-deleted purchase stops counting towards the balance', function () {
    $purchase = ledgerPurchase($this->supplier, 100);
    expect($this->ledger->balance($this->supplier))->toBe(100.0);

    $purchase->delete();

    expect($this->ledger->balance($this->supplier))->toBe(0.0);
});

test('a cashier cannot reach suppliers, their statements or their payments', function () {
    $payment = SupplierPayment::factory()->create(['supplier_id' => $this->supplier->id, 'user_id' => $this->user->id]);

    $this->actingAs(User::factory()->cashier()->create());

    $this->get(route('suppliers.index'))->assertForbidden();
    $this->get(route('suppliers.show', $this->supplier))->assertForbidden();
    $this->post(route('suppliers.store'), ['name' => 'Sneaky', 'phone' => '1'])->assertForbidden();
    $this->put(route('suppliers.update', $this->supplier), ['name' => 'Sneaky', 'phone' => '1'])->assertForbidden();
    $this->delete(route('suppliers.destroy', $this->supplier))->assertForbidden();
    $this->post(route('suppliers.payments.store', $this->supplier), ['amount' => 1, 'method' => 'cash'])->assertForbidden();
    $this->delete(route('suppliers.payments.destroy', $payment))->assertForbidden();

    expect(Supplier::where('name', 'Sneaky')->exists())->toBeFalse()
        ->and(SupplierPayment::count())->toBe(1);
});

test('the migration rolls back and reapplies cleanly', function () {
    $migration = require base_path('database/migrations/2026_10_14_000004_add_supplier_ledger_and_purchase_lifecycle.php');

    $migration->down();

    expect(Schema::hasTable('supplier_payments'))->toBeFalse()
        ->and(Schema::hasTable('purchase_returns'))->toBeFalse()
        ->and(Schema::hasTable('purchase_return_items'))->toBeFalse()
        ->and(Schema::hasColumn('purchases', 'status'))->toBeFalse()
        ->and(Schema::hasColumn('purchases', 'balance_due'))->toBeFalse()
        ->and(Schema::hasColumn('purchase_items', 'returned_quantity'))->toBeFalse()
        ->and(Schema::hasColumn('suppliers', 'opening_balance'))->toBeFalse()
        // What the rest of the app relies on survives the rollback.
        ->and(Schema::hasColumn('purchases', 'total_amount'))->toBeTrue()
        ->and(Schema::hasColumn('suppliers', 'address'))->toBeTrue();

    $migration->up();

    expect(Schema::hasTable('supplier_payments'))->toBeTrue()
        ->and(Schema::hasColumn('purchases', 'status'))->toBeTrue()
        ->and(Schema::hasColumn('purchase_items', 'returned_quantity'))->toBeTrue()
        ->and(Schema::hasColumn('suppliers', 'opening_balance'))->toBeTrue();
});

test('purchases that predate the lifecycle are backfilled as received and settled', function () {
    // A row as it looked before this migration ran: no status, nothing paid.
    $purchase = ledgerPurchase($this->supplier, 250);
    DB::table('purchases')->where('id', $purchase->id)->update([
        'status' => 'ordered', 'received_at' => null, 'amount_paid' => 0, 'balance_due' => 0,
    ]);

    $migration = require base_path('database/migrations/2026_10_14_000004_add_supplier_ledger_and_purchase_lifecycle.php');
    $migration->down();
    $migration->up();

    $backfilled = Purchase::find($purchase->id);

    expect($backfilled->status)->toBe('received')
        ->and($backfilled->received_at)->not->toBeNull()
        ->and((float) $backfilled->amount_paid)->toBe(250.0)
        ->and((float) $backfilled->balance_due)->toBe(0.0)
        // Already settled, so the shop does not suddenly appear to owe for it.
        ->and($this->ledger->balance($this->supplier))->toBe(0.0);
});

