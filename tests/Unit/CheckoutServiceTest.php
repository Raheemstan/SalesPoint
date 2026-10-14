<?php

use App\Models\Customer;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\CheckoutService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
 * CheckoutService is the one place in the app that creates a sale. The online
 * till and the offline sync endpoint both go through it, and the only thing that
 * differs between them is the options below - which is the whole point of the
 * class existing, so this file leans on the options hard.
 *
 * The Feature suite covers the online path end to end (TillTest, PosCheckoutTest,
 * SplitPaymentTest, CreditSaleAtTillTest) and the offline one end to end
 * (OfflineSyncTest). What is tested here is the seam: what each option does, and
 * that the money is worked out server-side whichever caller asked.
 */
uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(CheckoutService::class);
    $this->cashier = User::factory()->cashier()->create();
    // Inventory logs and audit entries read the signed-in user in places, and
    // the online caller is always authenticated.
    $this->actingAs($this->cashier);
});

/** A one-line sale of $product, paid in full in cash. */
function ring(Product $product, int $quantity = 1, array $input = [], array $options = []): array
{
    $price = (float) $product->sale_price;

    return app(CheckoutService::class)->create(array_merge([
        'lines' => [['product_id' => $product->id, 'quantity' => $quantity, 'price' => $price]],
        'tenders' => [['method' => 'cash', 'amount' => $price * $quantity]],
    ], $input), array_merge([
        'cashier' => test()->cashier,
    ], $options));
}

// ---- intent in, value out ----

test('a line is priced from the catalogue, and a price sent with it is ignored', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);

    // The default mode. A price on the line is simply not read.
    $sale = $this->service->create([
        'lines' => [['product_id' => $product->id, 'quantity' => 2, 'price' => 1]],
        'tenders' => [['method' => 'cash', 'amount' => 200]],
    ], ['cashier' => $this->cashier])['sale'];

    expect((float) $sale->grand_total)->toBe(200.0)
        ->and((float) $sale->items->first()->price)->toBe(100.0);
});

test('tax and discounts are recomputed, never accepted', function () {
    Setting::set('tax_rate', '10');
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $this->actingAs($manager = User::factory()->manager()->create());

    // 2 x 100 = 200, less a fixed 20 = 180, plus 10% = 198.
    $sale = ring($product, 2, [
        'order_discount' => ['type' => 'fixed', 'value' => 20],
        'tenders' => [['method' => 'cash', 'amount' => 250]],
    ], ['cashier' => $manager])['sale'];

    expect((float) $sale->total_amount)->toBe(200.0)
        ->and((float) $sale->discount_amount)->toBe(20.0)
        ->and((float) $sale->tax_amount)->toBe(18.0)
        ->and((float) $sale->grand_total)->toBe(198.0)
        ->and((float) $sale->change_due)->toBe(52.0);
});

test('a sale with no lines is refused', function () {
    expect(fn () => $this->service->create(['lines' => []], ['cashier' => $this->cashier]))
        ->toThrow(DomainException::class);
});

test('a sale with no cashier is a programming error, not a refusal', function () {
    $product = Product::factory()->create(['sale_price' => 10, 'stock_quantity' => 5]);

    expect(fn () => $this->service->create([
        'lines' => [['product_id' => $product->id, 'quantity' => 1]],
        'tenders' => [['method' => 'cash', 'amount' => 10]],
    ], []))->toThrow(InvalidArgumentException::class);
});

// ---- the queued price, which is the one number taken from outside ----

test('in queued mode the line is banked at the price the customer paid, with a note', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10, 'name' => 'Rice']);

    // Rung up at 100; the shop has since put it up to 120.
    $product->update(['sale_price' => 120]);

    $result = ring($product, 2, [
        'lines' => [['product_id' => $product->id, 'quantity' => 2, 'price' => 100]],
        'tenders' => [['method' => 'cash', 'amount' => 200]],
    ], ['prices' => 'queued']);

    expect((float) $result['sale']->grand_total)->toBe(200.0)
        ->and((float) $result['sale']->items->first()->price)->toBe(100.0)
        ->and($result['notes'])->toHaveCount(1)
        ->and($result['notes'][0])->toContain('Rice')->toContain('banked at the price the customer paid');
});

test('a queued price far from the catalogue price is refused rather than banked', function () {
    $product = Product::factory()->create(['sale_price' => 10000, 'stock_quantity' => 10, 'name' => 'Generator']);

    expect(fn () => ring($product, 1, [
        'lines' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 1]],
        'tenders' => [['method' => 'cash', 'amount' => 1]],
    ], ['prices' => 'queued']))
        ->toThrow(DomainException::class, 'Generator was queued at');

    expect(Sale::count())->toBe(0);
});

test('the tolerance for a queued price is adjustable, and measured both ways', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);

    // 100 queued against 100 catalogue is exact; 80 is 20% off a 100 reference.
    $within = fn (float $queued, float $tolerance) => ring($product, 1, [
        'lines' => [['product_id' => $product->id, 'quantity' => 1, 'price' => $queued]],
        'tenders' => [['method' => 'cash', 'amount' => $queued]],
    ], ['prices' => 'queued', 'price_tolerance' => $tolerance]);

    expect((float) $within(80, 25)['sale']->grand_total)->toBe(80.0);
    expect(fn () => $within(80, 10))->toThrow(DomainException::class);

    // And the other direction: 125 against 100 is 20% of the larger figure.
    expect((float) $within(125, 25)['sale']->grand_total)->toBe(125.0);
});

test('a line with no usable price falls back to the catalogue rather than to zero', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);

    $sale = $this->service->create([
        'lines' => [['product_id' => $product->id, 'quantity' => 1, 'price' => null]],
        'tenders' => [['method' => 'cash', 'amount' => 100]],
    ], ['cashier' => $this->cashier, 'prices' => 'queued'])['sale'];

    expect((float) $sale->grand_total)->toBe(100.0);
});

test('a negative queued price is refused outright', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);

    expect(fn () => $this->service->create([
        'lines' => [['product_id' => $product->id, 'quantity' => 1, 'price' => -100]],
        'tenders' => [['method' => 'cash', 'amount' => 0]],
    ], ['cashier' => $this->cashier, 'prices' => 'queued']))
        ->toThrow(DomainException::class, 'negative price');
});

// ---- stock ----

test('by default a short line is refused in the words the till has always used', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 1, 'name' => 'Sugar']);

    expect(fn () => ring($product, 5))
        ->toThrow(DomainException::class, 'Sugar is out of stock or has insufficient quantity.');

    expect(Sale::count())->toBe(0)
        ->and($product->fresh()->stock_quantity)->toBe(1)
        ->and(InventoryLog::count())->toBe(0);
});

test('with negative stock allowed the sale is banked, stock goes under, and the movement is still logged', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 1, 'name' => 'Sugar']);

    $result = ring($product, 3, [], ['allow_negative_stock' => true]);

    expect((float) $result['sale']->grand_total)->toBe(300.0)
        ->and($product->fresh()->stock_quantity)->toBe(-2)
        // The log is what makes the discrepancy findable and a stock-take able to fix it.
        ->and(InventoryLog::where('product_id', $product->id)->where('type', 'OUT')->value('quantity'))->toBe(3)
        ->and($result['notes'][0])->toContain('Sugar')->toContain('leaving -2');
});

test('a service is never short of stock and never logs a movement', function () {
    $haircut = Product::factory()->create([
        'sale_price' => 3000, 'is_service' => true, 'track_stock' => false, 'stock_quantity' => 0,
    ]);

    $result = ring($haircut, 3);

    expect((float) $result['sale']->grand_total)->toBe(9000.0)
        ->and($result['notes'])->toBeEmpty()
        ->and(InventoryLog::count())->toBe(0);
});

// ---- archived products ----

test('an archived product is refused online and banked on replay', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10, 'name' => 'Withdrawn']);
    $product->delete();

    expect(fn () => ring($product, 1))
        ->toThrow(DomainException::class, 'A product in the cart no longer exists.');

    $sale = ring($product, 1, [], ['allow_archived_products' => true])['sale'];

    expect($sale->items->first()->product_id)->toBe($product->id)
        ->and((float) $sale->grand_total)->toBe(100.0)
        // The goods left the shelf, so the stock figure has to move with them.
        ->and($product->fresh()->stock_quantity)->toBe(9);
});

// ---- attribution ----

test('the sale belongs to the cashier passed in, not to whoever is signed in', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $other = User::factory()->cashier()->create();

    // A manager is signed in; the sale is for someone else.
    $this->actingAs(User::factory()->manager()->create());

    $sale = ring($product, 1, [], ['cashier' => $other])['sale'];

    expect($sale->user_id)->toBe($other->id)
        // The movement belongs with the sale, not with the person who posted it.
        ->and(InventoryLog::where('product_id', $product->id)->value('user_id'))->toBe($other->id);
});

test('a sale can be stamped with the moment it was rung up rather than now', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $when = now()->subHours(6)->startOfSecond();

    $sale = ring($product, 1, [], ['at' => $when, 'synced_at' => now()])['sale'];
    $fresh = $sale->fresh();

    expect($fresh->created_at->toDateTimeString())->toBe($when->toDateTimeString())
        ->and($fresh->sale_date)->toBe($when->toDateTimeString())
        ->and($fresh->synced_at)->not->toBeNull()
        // The invoice number carries the moment of the sale, not of the sync.
        ->and($fresh->invoice_number)->toStartWith('INV-' . $when->format('YmdHis'));
});

test('a client uuid and a sync time are stored when they are given, and null otherwise', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);

    expect(ring($product)['sale']->client_uuid)->toBeNull();

    $uuid = (string) Str::uuid();
    $sale = ring($product, 1, [], ['client_uuid' => $uuid, 'synced_at' => now()])['sale'];

    expect($sale->client_uuid)->toBe($uuid)->and($sale->wasOffline())->toBeTrue();
});

// ---- shifts ----

test('shift "current" picks up the cashier\'s open shift, and nothing else', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $shift = Shift::factory()->open()->create(['user_id' => $this->cashier->id]);

    expect(ring($product, 1, [], ['shift' => 'current'])['sale']->shift_id)->toBe($shift->id);

    // An explicit null stamps nothing, which is what a drifted offline sale needs.
    expect(ring($product, 1, [], ['shift' => null])['sale']->shift_id)->toBeNull();
});

test('a named shift is stamped as given', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $shift = Shift::factory()->open()->create(['user_id' => $this->cashier->id]);

    expect(ring($product, 1, [], ['shift' => $shift])['sale']->shift_id)->toBe($shift->id);
});

test('the open-shift requirement can be waived for a sale that has already happened', function () {
    Setting::set('require_open_shift', '1');
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);

    // The online till asks ShiftService and is refused.
    expect(fn () => ring($product, 1, [], ['shift' => 'current']))
        ->toThrow(DomainException::class, 'No shift is open. Open a shift before selling.');

    // A replay says "this sale already happened" and is banked unstamped.
    $sale = ring($product, 1, [], ['shift' => null, 'require_shift' => false])['sale'];

    expect($sale->shift_id)->toBeNull()->and((float) $sale->grand_total)->toBe(100.0);
});

// ---- discount limit ----

test('the discount limit is judged against the cashier the sale belongs to', function () {
    $product = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $manager = User::factory()->manager()->create();

    expect($this->service->discountLimitFor($this->cashier))->toBe(10.0)
        ->and($this->service->discountLimitFor($manager))->toBeNull();

    // A manager is signed in, but the sale belongs to a cashier, so the cap bites.
    $this->actingAs($manager);

    expect(fn () => ring($product, 1, [
        'order_discount' => ['type' => 'percent', 'value' => 50],
        'tenders' => [['method' => 'cash', 'amount' => 50]],
    ], ['cashier' => $this->cashier]))
        ->toThrow(DomainException::class, 'Discounts above 10% need a manager.');

    // The same sale under the manager's own name goes through.
    expect((float) ring($product, 1, [
        'order_discount' => ['type' => 'percent', 'value' => 50],
        'tenders' => [['method' => 'cash', 'amount' => 50]],
    ], ['cashier' => $manager])['sale']->grand_total)->toBe(50.0);
});

// ---- credit, split tenders and the service charge still go through their owners ----

test('credit, change and tender rows are left to the services that own them', function () {
    $product = Product::factory()->create(['sale_price' => 500, 'stock_quantity' => 50]);
    $customer = Customer::factory()->create();

    $sale = $this->service->create([
        'lines' => [['product_id' => $product->id, 'quantity' => 4]],
        'tenders' => [
            ['method' => 'cash', 'amount' => 500],
            ['method' => 'transfer', 'amount' => 250],
        ],
        'customer_id' => $customer->id,
        'on_credit' => true,
    ], ['cashier' => $this->cashier])['sale'];

    expect((float) $sale->grand_total)->toBe(2000.0)
        ->and((float) $sale->paid_amount)->toBe(750.0)
        ->and((float) $sale->balance_due)->toBe(1250.0)
        ->and($sale->payment_status)->toBe(Sale::PAYMENT_PARTIAL)
        ->and($sale->payment_method)->toBe('cash')
        ->and($sale->payments)->toHaveCount(2);
});

test('an over-limit credit sale is refused in CreditService\'s own words and leaves nothing behind', function () {
    $product = Product::factory()->create(['sale_price' => 500, 'stock_quantity' => 50]);
    $customer = Customer::factory()->limit(1000)->create(['name' => 'Tight Limit Ltd']);

    expect(fn () => $this->service->create([
        'lines' => [['product_id' => $product->id, 'quantity' => 4]],
        'tenders' => [['method' => 'cash', 'amount' => 500]],
        'customer_id' => $customer->id,
        'on_credit' => true,
    ], ['cashier' => $this->cashier]))
        ->toThrow(DomainException::class, 'Tight Limit Ltd');

    expect(Sale::count())->toBe(0)
        ->and(SaleItem::count())->toBe(0)
        ->and($product->fresh()->stock_quantity)->toBe(50)
        ->and(InventoryLog::count())->toBe(0);
});

test('a service charge is recomputed from its type and value, never from a quoted amount', function () {
    Setting::set('feature_tips', '1');
    $product = Product::factory()->create(['sale_price' => 1000, 'stock_quantity' => 10]);

    $sale = $this->service->create([
        'lines' => [['product_id' => $product->id, 'quantity' => 1]],
        'tenders' => [['method' => 'cash', 'amount' => 1100]],
        // The quoted `amount` is deliberately absurd and deliberately unread.
        'service_charge' => ['type' => 'percent', 'value' => 10, 'amount' => 99999, 'label' => 'Service charge (10%)'],
    ], ['cashier' => $this->cashier])['sale'];

    expect((float) $sale->service_charge)->toBe(100.0)
        ->and((float) $sale->grand_total)->toBe(1100.0)
        ->and($sale->service_charge_label)->toBe('Service charge (10%)');
});

test('a service charge is dropped when the shop does not take tips', function () {
    Setting::set('feature_tips', '0');
    $product = Product::factory()->create(['sale_price' => 1000, 'stock_quantity' => 10]);

    $sale = $this->service->create([
        'lines' => [['product_id' => $product->id, 'quantity' => 1]],
        'tenders' => [['method' => 'cash', 'amount' => 1000]],
        'service_charge' => ['type' => 'percent', 'value' => 10],
    ], ['cashier' => $this->cashier])['sale'];

    expect((float) $sale->service_charge)->toBe(0.0)
        ->and((float) $sale->grand_total)->toBe(1000.0);
});

// ---- all or nothing ----

test('a refusal on the second line leaves no trace of the first', function () {
    $ok = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $short = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 1]);

    expect(fn () => $this->service->create([
        'lines' => [
            ['product_id' => $ok->id, 'quantity' => 2],
            ['product_id' => $short->id, 'quantity' => 5],
        ],
        'tenders' => [['method' => 'cash', 'amount' => 700]],
    ], ['cashier' => $this->cashier]))->toThrow(DomainException::class);

    expect(Sale::count())->toBe(0)
        ->and($ok->fresh()->stock_quantity)->toBe(10)
        ->and($short->fresh()->stock_quantity)->toBe(1)
        ->and(InventoryLog::count())->toBe(0);
});
