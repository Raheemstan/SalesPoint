<?php

use App\Http\Controllers\SerialController;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemSerial;
use App\Models\Setting;
use App\Models\User;
use App\Services\SerialService;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Serial / IMEI capture
|--------------------------------------------------------------------------
|
| Everything that writes a serial goes through SerialService, so these tests
| hold its two rules: one serial per unit on the line, and no physical unit sold
| twice. The screen is the post-sale one a manager uses today; the till will call
| the same service at checkout.
|
*/

beforeEach(function () {
    // Electronics: serials on.
    Setting::set('business_type', 'electronics');

    $this->manager = User::factory()->manager()->create();
    $this->actingAs($this->manager);
    $this->category = Category::factory()->create();
    $this->serials = app(SerialService::class);

    registerSerialRoutes();
});

/**
 * The routes the coordinator still has to register in routes/web.php (they are
 * listed in the summary). Declared here so the controller can be exercised
 * today; skipped once they exist for real.
 *
 * refreshNameLookups() because ->name() is called after the route is added to
 * the collection, so the name index does not know about it yet - the same call
 * Laravel's own RouteServiceProvider makes once routes/web.php is loaded.
 */
function registerSerialRoutes(): void
{
    if (Route::has('serials.edit')) {
        return;
    }

    Route::middleware(['web', 'auth', 'role:admin|manager'])->group(function () {
        Route::get('/serials', [SerialController::class, 'index'])->name('serials.index');
        Route::get('/sales/{sale}/serials', [SerialController::class, 'edit'])->name('serials.edit');
        Route::put('/sales/{sale}/serials', [SerialController::class, 'update'])->name('serials.update');
    });

    Route::getRoutes()->refreshNameLookups();
}

function aPhone(array $overrides = []): Product
{
    return Product::factory()->serialTracked()->create(array_merge([
        'name' => 'Phone X',
        'sku' => 'PHX',
        'barcode' => null,
        'category_id' => test()->category->id,
        'cost_price' => 100,
        'sale_price' => 150,
        'stock_quantity' => 10,
    ], $overrides));
}

/** A completed sale with one line per [product, quantity] pair. */
function aSaleOf(array $lines, array $overrides = []): Sale
{
    $sale = Sale::create(array_merge([
        'invoice_number' => 'INV-' . strtoupper(Str::random(6)),
        'user_id' => test()->manager->id,
        'total_amount' => 150,
        'grand_total' => 150,
        'payment_method' => 'cash',
        'paid_amount' => 150,
        'sale_date' => now(),
        'status' => Sale::STATUS_COMPLETED,
    ], $overrides));

    foreach ($lines as [$product, $quantity]) {
        $sale->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->sale_price,
            'cost_price' => $product->cost_price,
            'total' => $product->sale_price * $quantity,
            'paid_total' => $product->sale_price * $quantity,
        ]);
    }

    return $sale->refresh();
}

test('the serial screen shows one box per unit sold and saves them', function () {
    $phone = aPhone();
    $sale = aSaleOf([[$phone, 2]]);
    $item = $sale->items->first();

    $this->get(route('serials.edit', $sale))->assertOk()
        ->assertSee('Serial / IMEI numbers')
        ->assertSee('serial required')
        ->assertSee('Unit 1')
        ->assertSee('Unit 2')
        ->assertDontSee('Unit 3');

    $this->put(route('serials.update', $sale), [
        'serials' => [$item->id => ['imei-001', 'IMEI-002']],
    ])->assertSessionHas('success');

    expect($item->serials()->count())->toBe(2)
        // Stored normalised, so the same label read in either case is one unit.
        ->and($item->serials()->pluck('serial')->sort()->values()->all())->toBe(['IMEI-001', 'IMEI-002'])
        ->and($item->serials()->first()->product_id)->toBe($phone->id)
        ->and($item->fresh()->missingSerials())->toBe(0);
});

test('a line is refused when the number of serials does not match the quantity', function () {
    $phone = aPhone();
    $sale = aSaleOf([[$phone, 3]]);
    $item = $sale->items->first();

    $this->put(route('serials.update', $sale), [
        'serials' => [$item->id => ['A1', 'A2']],
    ])->assertSessionHas('error');

    expect($item->serials()->count())->toBe(0);

    // One too many is just as wrong.
    expect(fn() => $this->serials->record($item, ['A1', 'A2', 'A3', 'A4']))
        ->toThrow(DomainException::class, 'needs 3 serial number(s)');

    // Exactly right goes through.
    $this->put(route('serials.update', $sale), [
        'serials' => [$item->id => ['A1', 'A2', 'A3']],
    ])->assertSessionHas('success');

    expect($item->serials()->count())->toBe(3);
});

test('the same serial cannot be entered twice on one line', function () {
    $sale = aSaleOf([[aPhone(), 2]]);
    $item = $sale->items->first();

    $this->put(route('serials.update', $sale), [
        'serials' => [$item->id => ['SAME-1', 'same-1']],
    ])->assertSessionHas('error');

    expect($item->serials()->count())->toBe(0);
});

test('the same unit cannot be sold twice', function () {
    $phone = aPhone();

    $first = aSaleOf([[$phone, 1]]);
    $second = aSaleOf([[$phone, 1]]);

    $this->put(route('serials.update', $first), [
        'serials' => [$first->items->first()->id => ['IMEI-999']],
    ])->assertSessionHas('success');

    $this->put(route('serials.update', $second), [
        'serials' => [$second->items->first()->id => ['imei-999']],
    ])->assertSessionHas('error');

    expect(SaleItemSerial::matching('IMEI-999')->count())->toBe(1)
        ->and($second->items->first()->serials()->count())->toBe(0)
        ->and($this->serials->isAvailable($phone, 'IMEI-999'))->toBeFalse()
        ->and($this->serials->isAvailable($phone, 'IMEI-998'))->toBeTrue();

    // A different product may legitimately use the same string: serial numbers
    // are only unique within a manufacturer's own range.
    $other = aPhone(['name' => 'Tablet Y', 'sku' => 'TBY']);
    $third = aSaleOf([[$other, 1]]);

    $this->put(route('serials.update', $third), [
        'serials' => [$third->items->first()->id => ['IMEI-999']],
    ])->assertSessionHas('success');

    expect(SaleItemSerial::matching('IMEI-999')->count())->toBe(2);
});

test('a line can be corrected, and its own serials reused while doing it', function () {
    $sale = aSaleOf([[aPhone(), 2]]);
    $item = $sale->items->first();

    $this->put(route('serials.update', $sale), ['serials' => [$item->id => ['A1', 'A2']]]);

    // The second one was mistyped: resubmit the line with it put right.
    $this->put(route('serials.update', $sale), ['serials' => [$item->id => ['A1', 'A3']]])
        ->assertSessionHas('success');

    expect($item->serials()->pluck('serial')->sort()->values()->all())->toBe(['A1', 'A3'])
        ->and(SaleItemSerial::count())->toBe(2);
});

test('clearing every box on a line removes its serials', function () {
    $sale = aSaleOf([[aPhone(), 2]]);
    $item = $sale->items->first();

    $this->put(route('serials.update', $sale), ['serials' => [$item->id => ['A1', 'A2']]]);
    expect($item->serials()->count())->toBe(2);

    $this->put(route('serials.update', $sale), ['serials' => [$item->id => ['', '  ']]])
        ->assertSessionHas('success');

    expect($item->serials()->count())->toBe(0);
});

test('a serial lookup finds the sale it went out on', function () {
    $phone = aPhone();
    $sale = aSaleOf([[$phone, 1]], ['invoice_number' => 'INV-WARRANTY']);

    $this->put(route('serials.update', $sale), [
        'serials' => [$sale->items->first()->id => ['SN-ABC-123']],
    ]);

    // Through the service: typed in any case, with space around it.
    $found = $this->serials->lookup('  sn-abc-123 ');

    expect($found)->toHaveCount(1)
        ->and($found->first()->saleItem->sale->invoice_number)->toBe('INV-WARRANTY')
        ->and($found->first()->product->id)->toBe($phone->id);

    // And on the screen.
    $this->get(route('serials.index', ['serial' => 'sn-abc-123']))->assertOk()
        ->assertSee('Found')
        ->assertSee('INV-WARRANTY')
        ->assertSee('Phone X');

    // A partial number is offered when nothing matches exactly.
    $this->get(route('serials.index', ['serial' => 'ABC']))->assertOk()
        ->assertSee('No exact match')
        ->assertSee('INV-WARRANTY');

    $this->get(route('serials.index', ['serial' => 'NOTHING-LIKE-IT']))->assertOk()
        ->assertSee('No serial like that has been sold');

    // With no search at all, the most recent are listed.
    $this->get(route('serials.index'))->assertOk()->assertSee('Recently recorded')->assertSee('SN-ABC-123');
});

test('only the lines whose product asks for a serial are chased', function () {
    $phone = aPhone();
    $case = Product::factory()->create([
        'name' => 'Phone case', 'sku' => 'CASE', 'barcode' => null,
        'category_id' => $this->category->id, 'stock_quantity' => 50,
    ]);

    $sale = aSaleOf([[$phone, 2], [$case, 1]]);
    $phoneLine = $sale->items->firstWhere('product_id', $phone->id);
    $caseLine = $sale->items->firstWhere('product_id', $case->id);

    expect($this->serials->missingFor($sale))->toBe([$phoneLine->id => 2])
        ->and($phoneLine->requiresSerials())->toBeTrue()
        ->and($caseLine->requiresSerials())->toBeFalse();

    $this->get(route('serials.edit', $sale))->assertOk()->assertSee('2 missing');

    $this->put(route('serials.update', $sale), [
        'serials' => [$phoneLine->id => ['B1', 'B2']],
    ])->assertSessionHas('success');

    expect($this->serials->missingFor($sale->refresh()))->toBe([]);
});

test('a voided sale cannot have its serials changed', function () {
    $sale = aSaleOf([[aPhone(), 1]], ['status' => Sale::STATUS_VOIDED]);

    $this->put(route('serials.update', $sale), [
        'serials' => [$sale->items->first()->id => ['V1']],
    ])->assertSessionHas('error');

    expect(SaleItemSerial::count())->toBe(0);

    $this->get(route('serials.edit', $sale))->assertOk()->assertSee('was voided');
});

test('serials of another sale cannot be written through this one', function () {
    $mine = aSaleOf([[aPhone(), 1]]);
    $theirs = aSaleOf([[aPhone(['sku' => 'PHY', 'name' => 'Phone Y']), 1]]);

    // The line id is posted by a form, so it is checked against the sale.
    $this->put(route('serials.update', $mine), [
        'serials' => [$theirs->items->first()->id => ['X1']],
    ])->assertSessionHas('success');

    expect(SaleItemSerial::count())->toBe(0);
});

test('deleting a sale line takes its serials with it', function () {
    $sale = aSaleOf([[aPhone(), 1]]);
    $item = $sale->items->first();

    $this->put(route('serials.update', $sale), ['serials' => [$item->id => ['D1']]]);
    expect(SaleItemSerial::count())->toBe(1);

    SaleItem::find($item->id)->delete();

    expect(SaleItemSerial::count())->toBe(0);
});

test('a cashier cannot enter, correct or look up serials', function () {
    $sale = aSaleOf([[aPhone(), 1]]);
    $item = $sale->items->first();

    $this->actingAs(User::factory()->cashier()->create());

    $this->get(route('serials.edit', $sale))->assertForbidden();
    $this->put(route('serials.update', $sale), ['serials' => [$item->id => ['C1']]])->assertForbidden();
    $this->get(route('serials.index'))->assertForbidden();

    expect(SaleItemSerial::count())->toBe(0);
});

test('a trade that does not identify units has no serial screens at all', function () {
    $sale = aSaleOf([[aPhone(), 1]]);

    Setting::set('business_type', 'pharmacy');

    $this->get(route('serials.edit', $sale))->assertNotFound();
    $this->put(route('serials.update', $sale), ['serials' => []])->assertNotFound();
    $this->get(route('serials.index'))->assertNotFound();

    // The product form stops offering the flag, and a sale stops asking.
    $this->get(route('products.index'))->assertOk()->assertDontSee('Record a serial / IMEI');
    expect($sale->items->first()->requiresSerials())->toBeFalse();

    // A per-install override beats the preset, as it does for every other feature.
    Setting::set('feature_serials', '1');
    $this->get(route('serials.index'))->assertOk();
    $this->get(route('products.index'))->assertOk()->assertSee('Record a serial / IMEI');
});

test('the sale detail page offers the serial screen to a manager only', function () {
    $sale = aSaleOf([[aPhone(), 1]]);

    $this->get(route('sales.show', $sale))->assertOk()->assertSee('Serial numbers');

    // A cashier looking at their own sale is not offered it.
    $cashier = User::factory()->cashier()->create();
    $theirs = aSaleOf([[aPhone(['sku' => 'PHZ', 'name' => 'Phone Z']), 1]], ['user_id' => $cashier->id]);

    $this->actingAs($cashier);
    $this->get(route('sales.show', $theirs))->assertOk()->assertDontSee('Serial numbers');
});
