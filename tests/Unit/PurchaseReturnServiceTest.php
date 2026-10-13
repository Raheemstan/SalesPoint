<?php

use App\Models\AuditLog;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * The service writes stock, inventory logs and an audit trail, so it needs a
 * database. tests/Pest.php only wires those up for the Feature suite.
 */
uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->manager()->create();
    $this->actingAs($this->user);
    $this->supplier = Supplier::factory()->create();
    $this->service = app(PurchaseReturnService::class);
});

/**
 * A delivery that has arrived: the purchase, its lines and the stock it added.
 *
 * @param  array<int, array{0: Product, 1: int, 2: float}>  $lines  [product, quantity, unit cost]
 */
function receivedPurchase(array $lines, array $overrides = []): Purchase
{
    $total = collect($lines)->sum(fn($line) => round($line[1] * $line[2], 2));

    $purchase = Purchase::factory()->create(array_merge([
        'supplier_id' => test()->supplier->id,
        'user_id' => test()->user->id,
        'total_amount' => round($total, 2),
        'status' => Purchase::STATUS_RECEIVED,
        'amount_paid' => 0,
        'balance_due' => round($total, 2),
    ], $overrides));

    foreach ($lines as [$product, $quantity, $cost]) {
        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'cost_price' => $cost,
            'total' => round($quantity * $cost, 2),
        ]);

        $product->increment('stock_quantity', $quantity);
    }

    return $purchase->fresh();
}

/** The quantities map the service wants: purchase_item_id => quantity. */
function returnOf(Purchase $purchase, array $quantities): array
{
    $items = $purchase->purchaseItems()->orderBy('id')->get();
    $map = [];

    foreach (array_values($quantities) as $i => $quantity) {
        $map[$items[$i]->id] = $quantity;
    }

    return $map;
}

it('takes the goods off the shelf, credits the supplier and logs the movement', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $purchase = receivedPurchase([[$product, 10, 2.50]]);

    $return = $this->service->returnToSupplier($purchase, returnOf($purchase, [4]), 'Damaged', $this->user);

    expect($product->fresh()->stock_quantity)->toBe(6)
        ->and((float) $return->total_amount)->toBe(10.0)
        ->and($return->supplier_id)->toBe($this->supplier->id)
        ->and($return->reference)->toBe('RTN-' . $purchase->invoice_number . '-1')
        ->and($purchase->purchaseItems()->first()->returned_quantity)->toBe(4);

    $log = InventoryLog::where('type', 'OUT')->first();
    expect($log->quantity)->toBe(4)
        ->and($log->reason)->toContain($purchase->invoice_number);

    expect(AuditLog::where('table_name', 'purchase_returns')->exists())->toBeTrue();
});

it('makes the line amounts add up to the return total exactly', function () {
    $a = Product::factory()->create(['stock_quantity' => 0]);
    $b = Product::factory()->create(['stock_quantity' => 0]);
    $purchase = receivedPurchase([[$a, 7, 3.33], [$b, 3, 1.01]]);

    $return = $this->service->returnToSupplier($purchase, returnOf($purchase, [7, 3]), 'All wrong', $this->user);

    $lines = round((float) $return->items()->sum('amount'), 2);

    expect($lines)->toBe((float) $return->total_amount)
        // Returning everything credits back exactly what the purchase cost.
        ->and($lines)->toBe(round(7 * 3.33 + 3 * 1.01, 2));
});

it('credits the cost on the purchase line, not what the product costs today', function () {
    $product = Product::factory()->create(['stock_quantity' => 0, 'cost_price' => 10]);
    $purchase = receivedPurchase([[$product, 5, 4.00]]);

    // The shop renegotiated after the delivery; the supplier still owes the old price.
    $product->update(['cost_price' => 99]);

    $return = $this->service->returnToSupplier($purchase, returnOf($purchase, [5]), 'Wrong item', $this->user);

    expect((float) $return->total_amount)->toBe(20.0)
        ->and((float) $return->items()->first()->unit_cost)->toBe(4.0);
});

it('caps each line at what is left, over any number of returns', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $purchase = receivedPurchase([[$product, 10, 1.50]]);
    $itemId = $purchase->purchaseItems()->first()->id;

    $this->service->returnToSupplier($purchase, [$itemId => 6], 'First batch', $this->user);

    // Only 4 left: asking for 5 is refused and nothing is written.
    expect(fn() => $this->service->returnToSupplier($purchase->fresh(), [$itemId => 5], 'Too many', $this->user))
        ->toThrow(DomainException::class, 'only 4');

    expect(PurchaseReturn::count())->toBe(1)
        ->and($product->fresh()->stock_quantity)->toBe(4);

    $second = $this->service->returnToSupplier($purchase->fresh(), [$itemId => 4], 'The rest', $this->user);

    expect($product->fresh()->stock_quantity)->toBe(0)
        ->and(PurchaseItem::find($itemId)->returned_quantity)->toBe(10)
        // The two returns together credit back the whole line, to the penny.
        ->and(round((float) PurchaseReturnItem::sum('amount'), 2))->toBe(15.0)
        ->and((float) $second->total_amount)->toBe(6.0);

    // Nothing is left to return.
    expect(fn() => $this->service->returnToSupplier($purchase->fresh(), [$itemId => 1], 'Again', $this->user))
        ->toThrow(DomainException::class);
});

it('refuses to return stock that has already been sold', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $purchase = receivedPurchase([[$product, 10, 5]]);
    $product->decrement('stock_quantity', 8); // 8 of the 10 sold

    expect(fn() => $this->service->returnToSupplier($purchase, returnOf($purchase, [5]), 'Damaged', $this->user))
        ->toThrow(DomainException::class, 'only 2 left in stock');

    // The whole thing rolls back: no return, no half-decremented stock.
    expect(PurchaseReturn::count())->toBe(0)
        ->and(PurchaseReturnItem::count())->toBe(0)
        ->and($product->fresh()->stock_quantity)->toBe(2)
        ->and($purchase->purchaseItems()->first()->returned_quantity)->toBe(0)
        ->and(InventoryLog::count())->toBe(0);
});

it('refuses a purchase that never arrived', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $purchase = receivedPurchase([[$product, 10, 5]], ['status' => Purchase::STATUS_ORDERED, 'received_at' => null]);

    expect(fn() => $this->service->returnToSupplier($purchase, returnOf($purchase, [1]), 'Damaged', $this->user))
        ->toThrow(DomainException::class, 'Only a received purchase');
});

it('refuses lines that belong to someone else, and an empty selection', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $purchase = receivedPurchase([[$product, 5, 1]]);
    $other = receivedPurchase([[Product::factory()->create(['stock_quantity' => 0]), 5, 1]]);

    expect(fn() => $this->service->returnToSupplier($purchase, [$other->purchaseItems()->first()->id => 1], 'Mixed up', $this->user))
        ->toThrow(DomainException::class, 'does not belong to this purchase');

    expect(fn() => $this->service->returnToSupplier($purchase, returnOf($purchase, [0]), 'Nothing', $this->user))
        ->toThrow(DomainException::class, 'at least one line');

    expect(PurchaseReturn::count())->toBe(0);
});

it('writes the invoice outstanding figure back down as goods go back', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $purchase = receivedPurchase([[$product, 10, 10]]); // 100 invoiced, nothing paid

    $this->service->returnToSupplier($purchase, returnOf($purchase, [3]), 'Damaged', $this->user);

    expect((float) $purchase->fresh()->balance_due)->toBe(70.0);
});
