<?php

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryLog;
use App\Models\OfflineSaleAttempt;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\OfflineSyncService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Offline sales
|--------------------------------------------------------------------------
|
| A till in a market with routine power and network cuts queues sales on the
| device and replays them when the line comes back. Two things make that safe,
| and nearly every test here is about one of them:
|
|   - the same sale arriving twice must bank once (the unique index on
|     sales.client_uuid, and the sync endpoint answering `duplicate`);
|   - a queued payload is intent, not value - every figure is recomputed from
|     the database, so a wrong or tampered total cannot move a penny.
|
| The rest is honesty: whatever the verdict, it is written down.
*/

beforeEach(function () {
    $this->cashier = User::factory()->cashier()->create(['name' => 'Ada']);
    $this->actingAs($this->cashier);
    $this->product = Product::factory()->create(['sale_price' => 500, 'stock_quantity' => 50, 'name' => 'Rice']);
});

/**
 * A queued sale in the shape the till's IndexedDB queue stores and posts.
 *
 * `claimed_total` is included on purpose and is deliberately allowed to be
 * wrong: the server stores it as evidence and never reads it to bank anything.
 */
function queued(array $overrides = [], array $lines = null): array
{
    $lines = $lines ?? [['product_id' => test()->product->id, 'quantity' => 2, 'price' => 500.0]];

    $total = array_sum(array_map(fn ($line) => ($line['price'] ?? 0) * $line['quantity'], $lines));

    return array_merge([
        'client_uuid' => (string) Str::uuid(),
        'reference' => 'OFF-' . Str::upper(Str::random(4)),
        'rung_up_at' => now()->subHours(2)->toIso8601String(),
        'cashier_id' => test()->cashier->id,
        'lines' => $lines,
        'tenders' => [['method' => 'cash', 'amount' => $total]],
        'claimed_total' => $total,
    ], $overrides);
}

/** POST a batch at /pos/sync. */
function sync(array $sales)
{
    return test()->postJson(route('pos.sync'), ['sales' => $sales]);
}

/** POST one queued sale and hand back its verdict. */
function syncOne(array $sale): array
{
    return sync([$sale])->json('results.0');
}

// ---- idempotency: the whole point ----

test('replaying the same queued sale twice banks it once and reports the second as a duplicate', function () {
    $sale = queued();

    $first = syncOne($sale);

    expect($first['status'])->toBe('accepted')
        ->and($first['invoice_number'])->not->toBeNull();

    // Byte for byte the same payload, as a till that lost the first response would send.
    $second = syncOne($sale);

    expect($second['status'])->toBe('duplicate')
        // It names the sale the first attempt banked, so the till can still
        // reconcile the provisional reference on the customer's receipt.
        ->and($second['sale_id'])->toBe($first['sale_id'])
        ->and($second['invoice_number'])->toBe($first['invoice_number'])
        ->and($second['reason'])->toContain($first['invoice_number']);

    expect(Sale::count())->toBe(1)
        // And nothing was taken twice: one sale's worth of stock, one tender row.
        ->and($this->product->fresh()->stock_quantity)->toBe(48)
        ->and(SalePayment::count())->toBe(1)
        ->and(InventoryLog::where('type', 'OUT')->count())->toBe(1);

    // Both arrivals are on the record, with different verdicts.
    expect(OfflineSaleAttempt::where('client_uuid', $sale['client_uuid'])->count())->toBe(2)
        ->and(OfflineSaleAttempt::where('status', 'duplicate')->count())->toBe(1);
});

test('the same sale twice in one batch is banked once', function () {
    $sale = queued();

    $response = sync([$sale, $sale]);

    $response->assertOk()
        ->assertJsonPath('summary.accepted', 1)
        ->assertJsonPath('summary.duplicate', 1);

    expect(Sale::count())->toBe(1)->and($this->product->fresh()->stock_quantity)->toBe(48);
});

test('a sale already banked by another device is not banked again', function () {
    $uuid = (string) Str::uuid();

    // Another till got there first.
    syncOne(queued(['client_uuid' => $uuid]));

    $verdict = syncOne(queued(['client_uuid' => $uuid, 'reference' => 'OFF-OTHER']));

    expect($verdict['status'])->toBe('duplicate');
    expect(Sale::count())->toBe(1);
});

test('the unique index on sales.client_uuid is what makes that true', function () {
    // Stated as a test because the endpoint relies on the database refusing the
    // second insert rather than on a SELECT: two requests can both pass a
    // pre-check. If this index is ever dropped, a replay double-banks.
    $indexes = collect(Schema::getIndexes('sales'))
        ->filter(fn ($index) => $index['columns'] === ['client_uuid']);

    expect($indexes)->not->toBeEmpty()
        ->and($indexes->first()['unique'])->toBeTrue();
});

test('a sale banked by a path the endpoint never saw is still answered as a duplicate', function () {
    /*
     * The race, as closely as a single-threaded test can stage it: the sale is
     * already in the table before the request arrives, and it got there without
     * going through this endpoint - so nothing the endpoint remembers can help
     * it. The only thing that can refuse the second insert is the index, which
     * is exactly the situation when two devices sync the same queue at once.
     */
    $sale = queued();

    // Written straight to the table, so it genuinely did not come through the
    // endpoint. (SaleFactory is a stub, and the shape of this row does not
    // matter - only its client_uuid does.)
    $banked = Sale::create([
        'client_uuid' => $sale['client_uuid'],
        'invoice_number' => 'INV-RACE-WINNER',
        'user_id' => $this->cashier->id,
        'total_amount' => 1000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'grand_total' => 1000,
        'payment_method' => 'cash',
        'paid_amount' => 1000,
        'change_due' => 0,
        'sale_date' => now(),
        'status' => Sale::STATUS_COMPLETED,
    ]);

    $verdict = syncOne($sale);

    expect($verdict['status'])->toBe('duplicate')
        ->and($verdict['sale_id'])->toBe($banked->id)
        ->and($verdict['invoice_number'])->toBe('INV-RACE-WINNER');

    // The losing attempt left nothing behind: no second sale, no stock movement.
    expect(Sale::count())->toBe(1)
        ->and($this->product->fresh()->stock_quantity)->toBe(50)
        ->and(InventoryLog::count())->toBe(0)
        ->and(SalePayment::count())->toBe(0);
});

test('two tills that each sold the last unit offline both get banked, and stock goes negative', function () {
    /*
     * The honest limit of this feature, written down as a test so it cannot be
     * mistaken for a bug later. Nothing can stop two disconnected tills selling
     * the same last unit - the shelf is empty either way. What the server can do
     * is bank both (the money is real), show -1, and log both movements so a
     * stock-take finds it.
     */
    $this->product->update(['stock_quantity' => 1]);

    $response = sync([
        queued([], [['product_id' => $this->product->id, 'quantity' => 1, 'price' => 500.0]]),
        queued([], [['product_id' => $this->product->id, 'quantity' => 1, 'price' => 500.0]]),
    ]);

    $response->assertOk()->assertJsonPath('summary.accepted', 2);

    expect(Sale::count())->toBe(2)
        ->and($this->product->fresh()->stock_quantity)->toBe(-1)
        ->and(InventoryLog::where('product_id', $this->product->id)->count())->toBe(2);

    // And the second one says so, rather than looking like an ordinary sale.
    expect($response->json('results.1.notes.0'))->toContain('leaving -1');
});

test('the price tolerance is a setting', function () {
    $sale = queued([], [['product_id' => $this->product->id, 'quantity' => 1, 'price' => 400.0]]);

    // 400 against a 500 catalogue price is 20% off the larger figure, which the
    // default 25% window allows.
    expect(syncOne($sale)['status'])->toBe('accepted');

    Setting::set('offline_price_tolerance', '5');

    expect(syncOne(queued([], [['product_id' => $this->product->id, 'quantity' => 1, 'price' => 400.0]]))['status'])
        ->toBe('rejected');
});

// ---- the payload is intent, not value ----

test('a client-supplied total is ignored and the sale is banked at the recomputed figure', function () {
    // The till claims this 1,000 sale came to 1. It did not.
    $verdict = syncOne(queued(['claimed_total' => 1]));

    expect($verdict['status'])->toBe('accepted')->and($verdict['grand_total'])->toEqual(1000.0);

    $sale = Sale::sole();

    expect((float) $sale->grand_total)->toBe(1000.0)
        ->and((float) $sale->total_amount)->toBe(1000.0)
        ->and((float) $sale->paid_amount)->toBe(1000.0);

    // The claim is kept as evidence, and is visibly not what was banked.
    expect(OfflineSaleAttempt::sole()->claimedTotal())->toBe(1.0);
});

test('tax is recomputed from the shop setting, not from anything the till sent', function () {
    Setting::set('tax_rate', '7.5');

    // 2 x 500 = 1,000 + 7.5% = 1,075. The payload says nothing about tax at all,
    // and the tender it carries is short of the real total, so it goes on as
    // change-less cash and the shortfall is refused rather than guessed at.
    $verdict = syncOne(queued(['tenders' => [['method' => 'cash', 'amount' => 1075]]]));

    expect($verdict['status'])->toBe('accepted')->and($verdict['grand_total'])->toEqual(1075.0);
    expect((float) Sale::sole()->tax_amount)->toBe(75.0);
});

test('a tender that does not cover the recomputed total is refused, not rounded away', function () {
    Setting::set('tax_rate', '7.5');

    // The till worked the tax out wrongly and only took 1,000 of a 1,075 sale.
    $verdict = syncOne(queued(['tenders' => [['method' => 'cash', 'amount' => 1000]]]));

    expect($verdict['status'])->toBe('rejected')
        ->and($verdict['reason'])->toBe('Insufficient payment.');

    expect(Sale::count())->toBe(0);
});

test('a discount is recomputed and spread by the calculator, not taken on trust', function () {
    $this->actingAs(User::factory()->manager()->create());

    $verdict = syncOne(queued([
        'cashier_id' => null,
        'order_discount' => ['type' => 'percent', 'value' => 10],
        'tenders' => [['method' => 'cash', 'amount' => 900]],
        'claimed_total' => 500,
    ]));

    expect($verdict['status'])->toBe('accepted');

    $sale = Sale::sole();

    expect((float) $sale->discount_amount)->toBe(100.0)
        ->and((float) $sale->grand_total)->toBe(900.0)
        ->and((float) $sale->items->sum('paid_total'))->toBe(900.0);
});

// ---- a bad apple does not spoil the batch ----

test('a malformed sale in a batch rejects only itself', function () {
    $other = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);

    $good = queued();
    $alsoGood = queued([], [['product_id' => $other->id, 'quantity' => 1, 'price' => 100.0]]);
    $broken = ['client_uuid' => 'not-a-uuid', 'lines' => 'nonsense'];

    $response = sync([$good, $broken, $alsoGood]);

    $response->assertOk()
        ->assertJsonPath('results.0.status', 'accepted')
        ->assertJsonPath('results.1.status', 'rejected')
        ->assertJsonPath('results.2.status', 'accepted')
        ->assertJsonPath('summary.accepted', 2)
        ->assertJsonPath('summary.rejected', 1);

    expect(Sale::count())->toBe(2);

    // The refusal is on the record with the payload that caused it.
    $refused = OfflineSaleAttempt::needsAttention()->sole();
    expect($refused->reason)->not->toBeEmpty()
        ->and($refused->payload['lines'])->toBe('nonsense');
});

test('something that is not even an array is kept rather than dropped', function () {
    $response = sync([queued(), 'rubbish']);

    $response->assertOk()->assertJsonPath('results.1.status', 'rejected');

    expect(Sale::count())->toBe(1)
        ->and(OfflineSaleAttempt::needsAttention()->sole()->client_uuid)->toBe('unreadable');
});

test('the same product on two lines is refused rather than double-counted', function () {
    $verdict = syncOne(queued([], [
        ['product_id' => $this->product->id, 'quantity' => 1, 'price' => 500.0],
        ['product_id' => $this->product->id, 'quantity' => 1, 'price' => 500.0],
    ]));

    expect($verdict['status'])->toBe('rejected')
        ->and($verdict['reason'])->toContain('twice');

    expect(Sale::count())->toBe(0);
});

test('the batch size is capped, and the cap is refused as a whole rather than truncated', function () {
    $sales = [];

    for ($i = 0; $i <= OfflineSyncService::MAX_BATCH; $i++) {
        $sales[] = queued();
    }

    sync($sales)->assertStatus(422)->assertJsonValidationErrors('sales');

    expect(Sale::count())->toBe(0);
});

// ---- attribution ----

test('an offline sale belongs to the cashier and the minute that rang it up, not to the syncer', function () {
    $rungUpAt = now()->subHours(5)->startOfSecond();
    $manager = User::factory()->manager()->create(['name' => 'Supervisor']);

    // A supervisor clears the till's backlog at the end of the day.
    $this->actingAs($manager);

    $verdict = syncOne(queued([
        'cashier_id' => $this->cashier->id,
        'rung_up_at' => $rungUpAt->toIso8601String(),
    ]));

    expect($verdict['status'])->toBe('accepted');

    $sale = Sale::sole();

    expect($sale->user_id)->toBe($this->cashier->id)
        ->and($sale->created_at->toDateTimeString())->toBe($rungUpAt->toDateTimeString())
        ->and($sale->sale_date)->toBe($rungUpAt->toDateTimeString())
        // Stamped with when it was banked, which is not when it was sold.
        ->and($sale->synced_at)->not->toBeNull()
        ->and($sale->synced_at->toDateTimeString())->not->toBe($rungUpAt->toDateTimeString());

    // The stock movement follows the sale, not the person who posted it.
    expect(InventoryLog::where('product_id', $this->product->id)->value('user_id'))->toBe($this->cashier->id);

    // The batch itself is attributed to whoever sent it.
    expect(OfflineSaleAttempt::sole()->user_id)->toBe($manager->id);
});

test('a cashier cannot attribute an offline sale to someone else', function () {
    $other = User::factory()->cashier()->create(['name' => 'Bola']);

    $verdict = syncOne(queued(['cashier_id' => $other->id]));

    expect($verdict['status'])->toBe('rejected')
        ->and($verdict['reason'])->toContain('only a manager');

    expect(Sale::count())->toBe(0);
    expect(OfflineSaleAttempt::needsAttention()->count())->toBe(1);
});

test('a sale with no cashier named belongs to whoever sent it', function () {
    syncOne(queued(['cashier_id' => null]));

    expect(Sale::sole()->user_id)->toBe($this->cashier->id);
});

test('a sale dated in the future is refused: the clock is wrong', function () {
    $verdict = syncOne(queued(['rung_up_at' => now()->addDays(90)->toIso8601String()]));

    expect($verdict['status'])->toBe('rejected')
        ->and($verdict['reason'])->toContain('clock is wrong');

    expect(Sale::count())->toBe(0);
});

test('a few minutes of clock skew is tolerated', function () {
    $verdict = syncOne(queued(['rung_up_at' => now()->addMinutes(5)->toIso8601String()]));

    expect($verdict['status'])->toBe('accepted');
});

test('a sale older than the window is refused, and the window is a setting', function () {
    $old = queued(['rung_up_at' => now()->subDays(30)->toIso8601String()]);

    expect(syncOne($old)['status'])->toBe('rejected');
    expect(syncOne($old)['reason'])->toContain('older than this shop allows');

    // A shop that syncs rarely can widen it.
    Setting::set('offline_max_queue_age_hours', '2400');

    expect(syncOne(queued(['rung_up_at' => now()->subDays(30)->toIso8601String()]))['status'])->toBe('accepted');
});

// ---- stock that has gone since ----

test('by default a sale whose stock has sold out is still banked, with stock going negative and a log written', function () {
    expect(setting('offline_allow_negative_stock', '1'))->toBe('1');

    $this->product->update(['stock_quantity' => 1]);

    $verdict = syncOne(queued([], [['product_id' => $this->product->id, 'quantity' => 3, 'price' => 500.0]]));

    expect($verdict['status'])->toBe('accepted')
        ->and($verdict['notes'][0])->toContain('Rice')->toContain('leaving -2');

    expect($this->product->fresh()->stock_quantity)->toBe(-2)
        // The movement is on the record either way, so a stock-take can correct it.
        ->and(InventoryLog::where('product_id', $this->product->id)->where('type', 'OUT')->value('quantity'))->toBe(3);

    // Banked, but the discrepancy is attached to the accepted attempt too.
    expect(OfflineSaleAttempt::sole()->reason)->toContain('leaving -2');
});

test('a shop can switch that off, and then the sale is refused and a log is written for nothing', function () {
    Setting::set('offline_allow_negative_stock', '0');
    $this->product->update(['stock_quantity' => 1]);

    $verdict = syncOne(queued([], [['product_id' => $this->product->id, 'quantity' => 3, 'price' => 500.0]]));

    expect($verdict['status'])->toBe('rejected')
        ->and($verdict['reason'])->toBe('Rice is out of stock or has insufficient quantity.');

    expect(Sale::count())->toBe(0)
        ->and($this->product->fresh()->stock_quantity)->toBe(1)
        ->and(InventoryLog::count())->toBe(0);

    // Refused, but not lost.
    expect(OfflineSaleAttempt::needsAttention()->sole()->reason)->toContain('out of stock');
});

// ---- a price that moved ----

test('a price change does not reject the sale: it is banked at the price the customer paid', function () {
    $sale = queued();

    // The shop re-priced after the sale was rung up and before it synced.
    $this->product->update(['sale_price' => 560]);

    $verdict = syncOne($sale);

    expect($verdict['status'])->toBe('accepted')
        ->and($verdict['grand_total'])->toEqual(1000.0)
        ->and($verdict['notes'][0])->toContain('Rice')->toContain('banked at the price the customer paid');

    expect((float) Sale::sole()->items->first()->price)->toBe(500.0);

    // And the difference is on the accepted record, not just in the response.
    expect(OfflineSaleAttempt::sole()->reason)->toContain('now costs');
});

test('a price that is nowhere near the catalogue is refused rather than banked', function () {
    $verdict = syncOne(queued([], [['product_id' => $this->product->id, 'quantity' => 1, 'price' => 1]]));

    expect($verdict['status'])->toBe('rejected')
        ->and($verdict['reason'])->toContain('too far apart');

    expect(Sale::count())->toBe(0);
});

// ---- archived and deleted products ----

test('a product archived since the sale does not stop it being banked', function () {
    $sale = queued();
    $this->product->delete();

    $verdict = syncOne($sale);

    expect($verdict['status'])->toBe('accepted');
    expect(Sale::sole()->items->first()->product_id)->toBe($this->product->id);
});

test('a product that no longer exists at all is refused and recorded', function () {
    $verdict = syncOne(queued([], [['product_id' => 999999, 'quantity' => 1, 'price' => 100]]));

    expect($verdict['status'])->toBe('rejected')
        ->and($verdict['reason'])->toContain('no longer exists');

    expect(Sale::count())->toBe(0)
        ->and(OfflineSaleAttempt::needsAttention()->count())->toBe(1);
});

// ---- credit ----

test('an offline credit sale over the limit is refused with a readable reason, and recorded', function () {
    $customer = Customer::factory()->limit(600)->create(['name' => 'Tight Limit Ltd']);

    $verdict = syncOne(queued([
        'customer_id' => $customer->id,
        'on_credit' => true,
        'tenders' => [['method' => 'cash', 'amount' => 0]],
    ]));

    expect($verdict['status'])->toBe('rejected')
        ->and($verdict['reason'])->toContain('Tight Limit Ltd')
        ->toContain('credit limit')
        ->toContain('left');

    expect(Sale::count())->toBe(0)->and(SalePayment::count())->toBe(0);

    // Recorded in full, so a manager can raise the limit and have it sent again.
    $refused = OfflineSaleAttempt::needsAttention()->sole();
    expect($refused->reason)->toContain('Tight Limit Ltd')
        ->and($refused->payload['customer_id'])->toBe($customer->id)
        ->and($refused->claimedTotal())->toBe(1000.0);
});

test('and once the limit is raised the same queued sale goes through', function () {
    $customer = Customer::factory()->limit(600)->create();

    $sale = queued([
        'customer_id' => $customer->id,
        'on_credit' => true,
        'tenders' => [['method' => 'cash', 'amount' => 0]],
    ]);

    expect(syncOne($sale)['status'])->toBe('rejected');

    $customer->update(['credit_limit' => 5000]);

    expect(syncOne($sale)['status'])->toBe('accepted');

    expect((float) Sale::sole()->balance_due)->toBe(1000.0)
        ->and($customer->fresh()->outstanding)->toBe(1000.0);
});

test('an offline credit sale within the limit is banked with the balance owing', function () {
    $customer = Customer::factory()->create();

    $verdict = syncOne(queued([
        'customer_id' => $customer->id,
        'on_credit' => true,
        'tenders' => [['method' => 'cash', 'amount' => 400]],
        'due_date' => now()->subDays(3)->toDateString(),
    ]));

    expect($verdict['status'])->toBe('accepted');

    $sale = Sale::sole();

    // A due date that has already passed is accepted, unlike at the till: it is
    // the date that was agreed, and the sale is simply already overdue.
    expect((float) $sale->paid_amount)->toBe(400.0)
        ->and((float) $sale->balance_due)->toBe(600.0)
        ->and($sale->payment_status)->toBe(Sale::PAYMENT_PARTIAL)
        ->and($sale->isOverdue())->toBeTrue();
});

// ---- discount limit ----

test('a discount above the cashier\'s limit is refused offline too, so going offline is not a way round it', function () {
    $verdict = syncOne(queued([
        'order_discount' => ['type' => 'percent', 'value' => 50],
        'tenders' => [['method' => 'cash', 'amount' => 500]],
    ]));

    expect($verdict['status'])->toBe('rejected')
        ->and($verdict['reason'])->toBe('Discounts above 10% need a manager.');

    expect(Sale::count())->toBe(0);
});

test('the same discount under a manager\'s name is banked', function () {
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);

    $verdict = syncOne(queued([
        'cashier_id' => $manager->id,
        'order_discount' => ['type' => 'percent', 'value' => 50],
        'tenders' => [['method' => 'cash', 'amount' => 500]],
    ]));

    expect($verdict['status'])->toBe('accepted');
    expect((float) Sale::sole()->grand_total)->toBe(500.0);
});

// ---- shifts ----

test('a sale rung up on a shift that is still open is stamped into it', function () {
    $shift = Shift::factory()->open()->create(['user_id' => $this->cashier->id]);

    syncOne(queued(['shift_id' => $shift->id]));

    expect(Sale::sole()->shift_id)->toBe($shift->id);
});

test('a sale rung up on a since-closed shift is not stamped into its signed-off reconciliation', function () {
    $shift = Shift::factory()->closed()->create(['user_id' => $this->cashier->id]);

    $verdict = syncOne(queued(['shift_id' => $shift->id]));

    // Banked - the money is real - but not written into a reconciliation
    // somebody has already put their name to.
    expect($verdict['status'])->toBe('accepted')
        ->and($verdict['notes'][0])->toContain('closed and signed off')
        ->and($verdict['notes'][0])->toContain('no reconciliation');

    expect(Sale::sole()->shift_id)->toBeNull();

    // Surfaced, not swallowed: it is on the attempt and in the audit log.
    expect(OfflineSaleAttempt::sole()->reason)->toContain('closed and signed off');
    expect(AuditLog::where('action', 'like', 'Banked offline%')->count())->toBe(1);
});

test('another cashier\'s shift is never stamped onto a replayed sale', function () {
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);
    $someoneElse = Shift::factory()->open()->create(['user_id' => $manager->id]);

    syncOne(queued(['cashier_id' => $this->cashier->id, 'shift_id' => $someoneElse->id]));

    expect(Sale::sole()->shift_id)->toBeNull();
});

test('a replay is not blocked by the shop requiring an open shift: the sale already happened', function () {
    Setting::set('require_open_shift', '1');

    $verdict = syncOne(queued());

    expect($verdict['status'])->toBe('accepted');
    expect(Sale::sole()->shift_id)->toBeNull();
});

// ---- the audit trail and the status endpoint ----

test('every verdict is recorded, and a rejection names its reason', function () {
    syncOne(queued());
    syncOne(queued(['rung_up_at' => now()->addYear()->toIso8601String()]));

    expect(OfflineSaleAttempt::count())->toBe(2);

    $accepted = OfflineSaleAttempt::where('status', 'accepted')->sole();
    expect($accepted->sale_id)->toBe(Sale::sole()->id)->and($accepted->reason)->toBeNull();

    $rejected = OfflineSaleAttempt::where('status', 'rejected')->sole();
    expect($rejected->sale_id)->toBeNull()->and($rejected->reason)->toContain('clock is wrong');

    // Both sides of the trail: the sale, and the refusal.
    expect(AuditLog::where('action', 'like', 'Banked offline%')->count())->toBe(1)
        ->and(AuditLog::where('action', 'like', 'Refused an offline sale%')->count())->toBe(1);
});

test('a rejected sale is visible through the status endpoint, with its reason', function () {
    syncOne(queued(['rung_up_at' => now()->addYear()->toIso8601String(), 'reference' => 'OFF-HELD']));

    $status = $this->getJson(route('pos.sync.status'))->assertOk();

    $status->assertJsonPath('rejected', 1)
        ->assertJsonPath('needs_attention.0.reference', 'OFF-HELD')
        ->assertJsonPath('scope', 'mine');

    expect($status->json('needs_attention.0.reason'))->toContain('clock is wrong');
    // The amount at stake is labelled as claimed, and formatted with the shop's
    // currency rather than a hardcoded symbol.
    expect($status->json('needs_attention.0.claimed_total'))->toEqual(1000.0)
        ->and($status->json('needs_attention.0.claimed_total_display'))->toBe(money(1000));
});

test('the sync response carries the up-to-date status so the till needs no second request', function () {
    sync([queued(['rung_up_at' => now()->addYear()->toIso8601String()])])
        ->assertOk()
        ->assertJsonPath('status.rejected', 1)
        ->assertJsonPath('status.limits.max_batch', OfflineSyncService::MAX_BATCH)
        ->assertJsonPath('status.limits.allow_negative_stock', true);
});

test('a cashier sees only their own refusals; a manager sees everyone\'s', function () {
    syncOne(queued(['rung_up_at' => now()->addYear()->toIso8601String()]));

    $other = User::factory()->cashier()->create();
    $this->actingAs($other);

    $this->getJson(route('pos.sync.status'))->assertOk()->assertJsonPath('rejected', 0);

    $this->actingAs(User::factory()->manager()->create());

    $this->getJson(route('pos.sync.status'))->assertOk()
        ->assertJsonPath('rejected', 1)
        ->assertJsonPath('scope', 'all');
});

test('the status endpoint counts accepted and duplicate arrivals too', function () {
    $sale = queued();
    syncOne($sale);
    syncOne($sale);

    $this->getJson(route('pos.sync.status'))->assertOk()
        ->assertJsonPath('accepted', 1)
        ->assertJsonPath('duplicates', 1)
        ->assertJsonPath('rejected', 0);
});

// ---- access ----

test('a guest cannot sync anything', function () {
    auth()->logout();

    $this->postJson(route('pos.sync'), ['sales' => [queued()]])->assertStatus(401);
    $this->getJson(route('pos.sync.status'))->assertStatus(401);
});

test('an empty batch is refused by validation', function () {
    sync([])->assertStatus(422)->assertJsonValidationErrors('sales');
});

// ---- the receipt ----

test('the real receipt prints the provisional reference the customer was given', function () {
    /*
     * THE RECEIPT PROBLEM, closed. The customer walked out with a slip saying
     * OFF-xxxx because no invoice number existed yet. When the sale is banked,
     * its receipt carries both numbers, so the slip in their hand can be matched
     * to the invoice in the books by anybody, without going digging.
     */
    syncOne(queued(['reference' => 'OFF-K7P3']));

    $this->get(route('pos.print', Sale::sole()->id))
        ->assertOk()
        ->assertSee('OFF-K7P3')
        ->assertSee('Held on the till as')
        ->assertSee('Taken offline and banked');
});

test('an ordinary online sale says nothing about being offline', function () {
    checkout(cartOf([[$this->product, 1]]));

    $this->get(route('pos.print', Sale::sole()->id))
        ->assertOk()
        ->assertDontSee('Held on the till as')
        ->assertDontSee('Taken offline');
});

// ---- the till screen ----

test('the till renders the offline queue panel with the data the client needs', function () {
    $this->get(route('pos.index'))->assertOk()
        ->assertSee('offline-queue', false)
        // The client is handed the server's URLs and rules rather than guessing them.
        ->assertSee(route('pos.sync'), false)
        ->assertSee(route('pos.sync.status'), false);
});

test('the till screen shows refused offline sales without having to be asked', function () {
    syncOne(queued(['rung_up_at' => now()->addYear()->toIso8601String()]));

    $this->get(route('pos.index'))->assertOk()
        ->assertSee('1 offline sale was refused');
});

// ---- the migration ----

test('the migration rolls back and reapplies cleanly', function () {
    $migration = require base_path('database/migrations/2026_10_14_000010_add_offline_sale_queue.php');

    $migration->down();

    expect(Schema::hasTable('offline_sale_attempts'))->toBeFalse()
        ->and(Schema::hasColumn('sales', 'client_uuid'))->toBeFalse()
        ->and(Schema::hasColumn('sales', 'synced_at'))->toBeFalse()
        // What the rest of the app relies on survives the rollback.
        ->and(Schema::hasTable('sales'))->toBeTrue()
        ->and(Schema::hasColumns('sales', ['invoice_number', 'grand_total', 'sale_date']))->toBeTrue();

    $migration->up();

    expect(Schema::hasTable('offline_sale_attempts'))->toBeTrue()
        ->and(Schema::hasColumns('sales', ['client_uuid', 'synced_at']))->toBeTrue()
        ->and(Schema::hasColumns('offline_sale_attempts', [
            'client_uuid', 'user_id', 'payload', 'status', 'reason', 'sale_id', 'received_at',
        ]))->toBeTrue();

    // And it is really usable again, not merely present: the unique index that
    // the whole idempotency guarantee rests on has to come back with it.
    $sale = queued();
    expect(syncOne($sale)['status'])->toBe('accepted');
    expect(syncOne($sale)['status'])->toBe('duplicate');
});
