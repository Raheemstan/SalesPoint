<?php

use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockTake;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockTakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| tests/Pest.php only binds the Laravel test case to the Feature suite, so a
| unit test that touches the database asks for it here.
*/
uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(StockTakeService::class);
    $this->counter = User::factory()->manager()->create();
    $this->approver = User::factory()->admin()->create();
});

/** Count sheet over everything, with one line per product. */
function sheetFor(array $filters = []): StockTake
{
    return test()->service->createSheet(test()->counter, $filters);
}

function lineFor(StockTake $sheet, Product $product)
{
    return $sheet->items()->where('product_id', $product->id)->first();
}

it('builds a sheet from stocked, active products only', function () {
    $counted = Product::factory()->create(['name' => 'Sugar', 'stock_quantity' => 12, 'cost_price' => 3.50]);
    $service = Product::factory()->create(['name' => 'Haircut', 'is_service' => true]);
    $untracked = Product::factory()->create(['name' => 'Plastic bag', 'track_stock' => false]);
    $archived = Product::factory()->create(['name' => 'Old stock', 'is_active' => false]);

    $sheet = sheetFor();

    expect($sheet->items)->toHaveCount(1)
        ->and($sheet->items->first()->product_id)->toBe($counted->id)
        ->and($sheet->status)->toBe(StockTake::STATUS_DRAFT)
        ->and($sheet->reference)->toStartWith('ST-');

    // the three that must never be counted
    foreach ([$service, $untracked, $archived] as $excluded) {
        expect(lineFor($sheet, $excluded))->toBeNull();
    }
});

it('snapshots the system quantity and the cost, and does not follow later edits', function () {
    $product = Product::factory()->create(['stock_quantity' => 20, 'cost_price' => 4]);

    $line = lineFor(sheetFor(), $product);
    expect($line->system_quantity)->toBe(20)->and((float) $line->cost_price)->toBe(4.0);

    // Someone edits the product and sells some after the sheet was printed.
    $product->update(['cost_price' => 99, 'stock_quantity' => 15]);

    expect($line->fresh()->system_quantity)->toBe(20)
        ->and((float) $line->fresh()->cost_price)->toBe(4.0);
});

it('can be limited to one category or one supplier', function () {
    $drinks = Category::factory()->create();
    $acme = Supplier::factory()->create();

    $inBoth = Product::factory()->create(['category_id' => $drinks->id, 'supplier_id' => $acme->id]);
    $elsewhere = Product::factory()->create();

    expect(sheetFor(['category_id' => $drinks->id])->items)->toHaveCount(1)
        ->and(sheetFor(['supplier_id' => $acme->id])->items)->toHaveCount(1)
        ->and(lineFor(sheetFor(['category_id' => $drinks->id]), $elsewhere))->toBeNull();
});

it('refuses to build a sheet with nothing countable on it', function () {
    Product::factory()->create(['is_service' => true]);

    expect(fn() => sheetFor())->toThrow(DomainException::class);
});

it('computes the variance from the count, and treats a blank box as "not counted"', function () {
    $short = Product::factory()->create(['stock_quantity' => 10, 'cost_price' => 2]);
    $over = Product::factory()->create(['stock_quantity' => 5, 'cost_price' => 2]);
    $skipped = Product::factory()->create(['stock_quantity' => 7, 'cost_price' => 2]);

    $sheet = sheetFor();
    $this->service->saveCounts($sheet, [
        lineFor($sheet, $short)->id => 8,
        lineFor($sheet, $over)->id => 6,
        lineFor($sheet, $skipped)->id => '',
    ]);

    expect(lineFor($sheet, $short)->variance)->toBe(-2)
        ->and(lineFor($sheet, $over)->variance)->toBe(1)
        ->and(lineFor($sheet, $skipped)->counted_quantity)->toBeNull()
        ->and(lineFor($sheet, $skipped)->variance)->toBe(0);

    // a counted zero is a real count, not a blank
    $this->service->saveCounts($sheet, [lineFor($sheet, $skipped)->id => 0]);
    expect(lineFor($sheet, $skipped)->counted_quantity)->toBe(0)
        ->and(lineFor($sheet, $skipped)->variance)->toBe(-7);
});

it('reports what the variance cost at the snapshot cost price', function () {
    $a = Product::factory()->create(['stock_quantity' => 10, 'cost_price' => 2.50]);  // 3 short  = -7.50
    $b = Product::factory()->create(['stock_quantity' => 4, 'cost_price' => 10]);     // 1 over   = +10.00

    $sheet = sheetFor();
    $this->service->saveCounts($sheet, [
        lineFor($sheet, $a)->id => 7,
        lineFor($sheet, $b)->id => 5,
    ]);

    $summary = $this->service->summary($sheet->fresh());

    expect($summary['variance_units'])->toBe(-2)
        ->and($summary['variance_value'])->toBe(2.5)       // -7.50 + 10.00
        ->and($summary['shrinkage_value'])->toBe(-7.5)     // the losses on their own
        ->and($summary['short_lines'])->toBe(1)
        ->and($summary['over_lines'])->toBe(1)
        ->and($summary['counted'])->toBe(2);
});

it('changes no stock until it is approved', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    $sheet = sheetFor();
    $this->service->saveCounts($sheet, [lineFor($sheet, $product)->id => 4], [], true);

    expect($product->fresh()->stock_quantity)->toBe(10)
        ->and(StockAdjustment::count())->toBe(0)
        ->and(InventoryLog::count())->toBe(0)
        ->and($sheet->fresh()->status)->toBe(StockTake::STATUS_SUBMITTED);
});

it('corrects the stock on approval and logs both the adjustment and the movement', function () {
    $short = Product::factory()->create(['stock_quantity' => 10, 'cost_price' => 3]);
    $over = Product::factory()->create(['stock_quantity' => 4, 'cost_price' => 3]);

    $sheet = sheetFor();
    $this->service->saveCounts($sheet, [
        lineFor($sheet, $short)->id => 8,
        lineFor($sheet, $over)->id => 6,
    ]);

    $this->service->approve($sheet->fresh(), $this->approver);

    expect($short->fresh()->stock_quantity)->toBe(8)
        ->and($over->fresh()->stock_quantity)->toBe(6);

    $out = StockAdjustment::where('product_id', $short->id)->first();
    expect($out->type)->toBe(StockAdjustment::TYPE_DECREASE)
        ->and($out->quantity)->toBe(2)
        ->and($out->reason)->toBe('count')
        ->and($out->stock_take_id)->toBe($sheet->id)
        ->and((float) $out->cost_price)->toBe(3.0)
        ->and($out->user_id)->toBe($this->approver->id);

    expect(StockAdjustment::where('product_id', $over->id)->first()->type)->toBe(StockAdjustment::TYPE_INCREASE);

    expect(InventoryLog::where('product_id', $short->id)->where('type', 'OUT')->where('quantity', 2)->exists())->toBeTrue()
        ->and(InventoryLog::where('product_id', $over->id)->where('type', 'IN')->where('quantity', 2)->exists())->toBeTrue();

    $sheet = $sheet->fresh();
    expect($sheet->status)->toBe(StockTake::STATUS_APPROVED)
        ->and($sheet->approved_by)->toBe($this->approver->id)
        ->and($sheet->approved_at)->not->toBeNull();
});

it('writes nothing for a line that was counted and found correct', function () {
    $product = Product::factory()->create(['stock_quantity' => 6]);

    $sheet = sheetFor();
    $this->service->saveCounts($sheet, [lineFor($sheet, $product)->id => 6]);
    $this->service->approve($sheet->fresh(), $this->approver);

    expect($product->fresh()->stock_quantity)->toBe(6)
        ->and(StockAdjustment::count())->toBe(0)
        ->and(InventoryLog::count())->toBe(0);
});

/*
| The decision this feature turns on: approval applies the variance as a delta,
| it does not force the stock to the counted figure. A sale between counting and
| approving is a real movement and must survive approval.
*/
it('applies the variance as a delta, so a sale made during the count is not undone', function () {
    $product = Product::factory()->create(['stock_quantity' => 10, 'cost_price' => 1]);

    $sheet = sheetFor();                                            // system = 10
    $this->service->saveCounts($sheet, [lineFor($sheet, $product)->id => 8]);  // counted 8, so 2 are missing

    $product->decrement('stock_quantity', 3);                       // 3 sold while the sheet waited: 7 left

    $this->service->approve($sheet->fresh(), $this->approver);

    // 7 - 2 = 5. Setting the stock to the counted 8 would have invented the 3 sold units back.
    expect($product->fresh()->stock_quantity)->toBe(5);

    $adjustment = StockAdjustment::where('product_id', $product->id)->first();
    expect($adjustment->quantity)->toBe(2)->and($adjustment->type)->toBe(StockAdjustment::TYPE_DECREASE);
});

it('stops at zero rather than driving the stock negative, and records what it actually applied', function () {
    $product = Product::factory()->create(['stock_quantity' => 10, 'cost_price' => 1]);

    $sheet = sheetFor();
    $this->service->saveCounts($sheet, [lineFor($sheet, $product)->id => 2]);  // variance -8
    $product->update(['stock_quantity' => 5]);                                  // then 5 were sold

    $this->service->approve($sheet->fresh(), $this->approver);

    expect($product->fresh()->stock_quantity)->toBe(0);

    // The logs must add up to the stock column, so the clamped delta is what is recorded.
    $adjustment = StockAdjustment::where('product_id', $product->id)->first();
    expect($adjustment->quantity)->toBe(5)
        ->and(InventoryLog::where('product_id', $product->id)->first()->quantity)->toBe(5);
});

it('cannot be approved twice', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    $sheet = sheetFor();
    $this->service->saveCounts($sheet, [lineFor($sheet, $product)->id => 7]);
    $this->service->approve($sheet->fresh(), $this->approver);

    expect(fn() => $this->service->approve($sheet->fresh(), $this->approver))->toThrow(DomainException::class);

    // and the correction was applied exactly once
    expect($product->fresh()->stock_quantity)->toBe(7)
        ->and(StockAdjustment::count())->toBe(1)
        ->and(InventoryLog::count())->toBe(1);
});

it('refuses to approve a sheet nobody has counted, or one that was cancelled', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    $empty = sheetFor();
    expect(fn() => $this->service->approve($empty, $this->approver))->toThrow(DomainException::class);

    $cancelled = sheetFor();
    $this->service->saveCounts($cancelled, [lineFor($cancelled, $product)->id => 3]);
    $this->service->cancel($cancelled);

    expect(fn() => $this->service->approve($cancelled->fresh(), $this->approver))->toThrow(DomainException::class);
    expect($product->fresh()->stock_quantity)->toBe(10);
});

it('will not take counts for a sheet that is already settled', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    $sheet = sheetFor();
    $this->service->saveCounts($sheet, [lineFor($sheet, $product)->id => 9]);
    $this->service->approve($sheet->fresh(), $this->approver);

    expect(fn() => $this->service->saveCounts($sheet->fresh(), [lineFor($sheet, $product)->id => 1]))
        ->toThrow(DomainException::class);

    expect($product->fresh()->stock_quantity)->toBe(9);
});

it('skips a line whose product became a service or was archived mid-count', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    $sheet = sheetFor();
    $this->service->saveCounts($sheet, [lineFor($sheet, $product)->id => 4]);

    $product->update(['is_service' => true]);   // no longer something with stock

    $this->service->approve($sheet->fresh(), $this->approver);

    expect($product->fresh()->stock_quantity)->toBe(10)
        ->and(StockAdjustment::count())->toBe(0)
        ->and($sheet->fresh()->status)->toBe(StockTake::STATUS_APPROVED);
});

it('gives every sheet its own reference', function () {
    Product::factory()->create(['stock_quantity' => 1]);

    $references = collect(range(1, 3))->map(fn() => sheetFor()->reference);

    expect($references->unique())->toHaveCount(3);
});
