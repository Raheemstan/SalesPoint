<?php

use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;

beforeEach(function () {
    $this->cashier = User::factory()->cashier()->create();
    $this->actingAs($this->cashier);
});

test('every cart line decrements ITS OWN product (regression: only the last product was touched)', function () {
    $a = Product::factory()->create(['stock_quantity' => 10, 'sale_price' => 100]);
    $b = Product::factory()->create(['stock_quantity' => 20, 'sale_price' => 50]);
    $c = Product::factory()->create(['stock_quantity' => 30, 'sale_price' => 10]);

    $response = checkout(cartOf([[$a, 2], [$b, 3], [$c, 5]]));

    $sale = Sale::first();
    $response->assertRedirect(route('pos.print', $sale->id));

    expect($a->fresh()->stock_quantity)->toBe(8)
        ->and($b->fresh()->stock_quantity)->toBe(17)
        ->and($c->fresh()->stock_quantity)->toBe(25);

    // one OUT log per product, each against the right product
    expect(InventoryLog::where('type', 'OUT')->count())->toBe(3);
    expect(InventoryLog::where('product_id', $a->id)->value('quantity'))->toBe(2);
    expect(InventoryLog::where('product_id', $b->id)->value('quantity'))->toBe(3);
    expect(InventoryLog::where('product_id', $c->id)->value('quantity'))->toBe(5);

    expect((float) $sale->total_amount)->toBe(2 * 100.0 + 3 * 50.0 + 5 * 10.0);
    expect($sale->items)->toHaveCount(3);
});

test('a failed checkout changes nothing: no sale, no stock movement', function () {
    $ok = Product::factory()->create(['stock_quantity' => 10, 'sale_price' => 100]);
    $short = Product::factory()->create(['stock_quantity' => 1, 'sale_price' => 100]);

    checkout(cartOf([[$ok, 2], [$short, 5]]))->assertSessionHas('error');

    expect(Sale::count())->toBe(0)
        ->and($ok->fresh()->stock_quantity)->toBe(10)
        ->and($short->fresh()->stock_quantity)->toBe(1)
        ->and(InventoryLog::count())->toBe(0);
});

test('checkout prices each line from the database, not the session copy', function () {
    $p = Product::factory()->create(['stock_quantity' => 10, 'sale_price' => 100]);
    $cart = cartOf([[$p, 2]]);
    $cart[$p->id]['price'] = 1; // tampered / stale session price

    checkout($cart);

    expect((float) Sale::first()->grand_total)->toBe(200.0);
});

test('insufficient payment is rejected', function () {
    $p = Product::factory()->create(['stock_quantity' => 10, 'sale_price' => 100]);

    checkout(cartOf([[$p, 1]]), ['paid_amount' => 50])->assertSessionHas('error', 'Insufficient payment.');

    expect(Sale::count())->toBe(0)->and($p->fresh()->stock_quantity)->toBe(10);
});

test('a discount larger than the sale is rejected', function () {
    $p = Product::factory()->create(['stock_quantity' => 10, 'sale_price' => 100]);

    checkout(cartOf([[$p, 1]]), ['paid_amount' => 0], ['type' => 'fixed', 'value' => 500])->assertSessionHas('error');

    expect(Sale::count())->toBe(0);
});

test('unknown payment methods and negative payments fail validation', function () {
    $p = Product::factory()->create(['stock_quantity' => 10, 'sale_price' => 100]);

    checkout(cartOf([[$p, 1]]), ['payment_method' => 'bitcoin'])->assertSessionHasErrors('payment_method');
    checkout(cartOf([[$p, 1]]), ['paid_amount' => -5])->assertSessionHasErrors('paid_amount');

    expect(Sale::count())->toBe(0);
});

test('tax and discount sent by the browser are ignored: only server-side rules count', function () {
    $p = Product::factory()->create(['stock_quantity' => 10, 'sale_price' => 100]);

    checkout(cartOf([[$p, 1]]), ['tax' => -50, 'discount' => 99, 'grand_total' => 1]);

    $sale = Sale::first();
    expect((float) $sale->grand_total)->toBe(100.0)
        ->and((float) $sale->discount_amount)->toBe(0.0)
        ->and((float) $sale->tax_amount)->toBe(0.0);
});

test('change and totals are computed correctly with the tax setting and a sale discount', function () {
    \App\Models\Setting::set('tax_rate', '10');
    $p = Product::factory()->create(['stock_quantity' => 10, 'sale_price' => 100]);

    // 2 x 100 = 200, minus 20 discount = 180, plus 10% tax = 198
    checkout(cartOf([[$p, 2]]), ['paid_amount' => 250], ['type' => 'fixed', 'value' => 20]);

    $sale = Sale::first();
    expect((float) $sale->total_amount)->toBe(200.0)
        ->and((float) $sale->discount_amount)->toBe(20.0)
        ->and((float) $sale->tax_amount)->toBe(18.0)
        ->and((float) $sale->tax_rate)->toBe(10.0)
        ->and((float) $sale->grand_total)->toBe(198.0)
        ->and((float) $sale->change_due)->toBe(52.0);
});

test('invoice numbers are unique even for sales in the same second', function () {
    $p = Product::factory()->create(['stock_quantity' => 50, 'sale_price' => 10]);

    checkout(cartOf([[$p, 1]]));
    checkout(cartOf([[$p, 1]]));

    expect(Sale::pluck('invoice_number')->unique())->toHaveCount(2);
});

test('an empty cart cannot be checked out', function () {
    $this->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 10])
        ->assertSessionHas('error', 'Cart is empty!');
});

test('adding more than the available stock to the cart is refused', function () {
    $p = Product::factory()->create(['stock_quantity' => 1, 'sale_price' => 10]);

    $this->postJson(route('pos.add', $p->id))->assertOk();
    $this->postJson(route('pos.add', $p->id))->assertStatus(422)->assertJsonPath('success', false);

    $this->postJson(route('pos.update', $p->id), ['quantity' => 5])->assertStatus(422);
});

test('product search matches name, sku and barcode and treats % literally', function () {
    Product::factory()->create(['name' => 'Blue Pen', 'sku' => 'PEN-1', 'barcode' => '111']);
    Product::factory()->create(['name' => 'Red Pen', 'sku' => 'PEN-2', 'barcode' => '222']);

    expect($this->getJson(route('pos.search', ['q' => 'pen']))->json())->toHaveCount(2);
    expect($this->getJson(route('pos.search', ['q' => 'PEN-2']))->json())->toHaveCount(1);
    expect($this->getJson(route('pos.search', ['q' => '111']))->json())->toHaveCount(1);
    expect($this->getJson(route('pos.search', ['q' => '%']))->json())->toHaveCount(0);
});

test('the receipt renders for a sale containing a since-deleted product', function () {
    $p = Product::factory()->create(['stock_quantity' => 5, 'sale_price' => 10, 'name' => 'Archived Thing']);
    checkout(cartOf([[$p, 1]]));
    $p->delete();

    $this->get(route('pos.print', Sale::first()->id))->assertOk()->assertSee('Archived Thing');
});
