<?php

use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;

beforeEach(function () {
    $this->cashier = User::factory()->cashier()->create();
    $this->actingAs($this->cashier);
});

function addToCart(Product $p, int $times = 1): void
{
    for ($i = 0; $i < $times; $i++) {
        test()->postJson(route('pos.add', $p->id))->assertOk();
    }
}

function discountLine(Product $p, string $type, $value)
{
    return test()->postJson(route('pos.discount.line', $p->id), ['type' => $type, 'value' => $value]);
}

function discountOrder(string $type, $value)
{
    return test()->postJson(route('pos.discount.order'), ['type' => $type, 'value' => $value]);
}

function cartHtml(): string
{
    return test()->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get(route('pos.index'))->getContent();
}

test('the cost price is frozen on each sale item (profit must not move when the product cost is edited later)', function () {
    $p = Product::factory()->create(['cost_price' => 60, 'sale_price' => 100, 'stock_quantity' => 10]);

    checkout(cartOf([[$p, 2]]));
    $p->update(['cost_price' => 90]);

    $item = Sale::first()->items->first();
    expect((float) $item->cost_price)->toBe(60.0);
});

test('a percent discount on one line flows through to the sale and its items', function () {
    $a = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $b = Product::factory()->create(['sale_price' => 50, 'stock_quantity' => 10]);
    addToCart($a);
    addToCart($b);

    discountLine($a, 'percent', 10)->assertOk();

    $this->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 500]);

    $sale = Sale::first();
    expect((float) $sale->total_amount)->toBe(150.0)
        ->and((float) $sale->discount_amount)->toBe(10.0)
        ->and((float) $sale->grand_total)->toBe(140.0);

    $item = $sale->items->firstWhere('product_id', $a->id);
    expect((float) $item->discount_amount)->toBe(10.0)->and((float) $item->paid_total)->toBe(90.0);
});

test('a sale-level discount is spread over the lines and recorded', function () {
    $a = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $b = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    addToCart($a);
    addToCart($b);

    discountOrder('percent', 5)->assertOk();
    $this->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 500]);

    $sale = Sale::first();
    expect((float) $sale->grand_total)->toBe(190.0)
        ->and($sale->discount_type)->toBe('percent')->and((float) $sale->discount_value)->toBe(5.0)
        ->and((float) $sale->items->sum('paid_total'))->toBe(190.0);
});

test('invalid discounts are refused and the cart is left exactly as it was', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);
    addToCart($p);

    discountLine($p, 'percent', 150)->assertStatus(422)->assertJsonPath('success', false);
    discountLine($p, 'fixed', 500)->assertStatus(422);
    discountOrder('fixed', 500)->assertStatus(422);
    $this->postJson(route('pos.discount.line', $p->id), ['type' => 'percent', 'value' => -5])->assertStatus(422);

    expect(session('cart')[$p->id]['discount_type'] ?? null)->toBeNull();
    expect(session('cart_discount'))->toBeNull();
});

test('cashiers are capped by the discount limit setting, managers are not', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    addToCart($p);

    // default limit is 10%
    discountLine($p, 'percent', 10)->assertOk();
    discountLine($p, 'percent', 15)->assertStatus(422)->assertJsonPath('message', 'Discounts above 10% need a manager.');
    discountOrder('fixed', 50)->assertStatus(422);

    // the failed attempt left the accepted 10% in place
    expect((float) session('cart')[$p->id]['discount_value'])->toBe(10.0);

    Setting::set('max_cashier_discount', '20');
    discountLine($p, 'percent', 15)->assertOk();

    $this->actingAs(User::factory()->manager()->create());
    discountLine($p, 'percent', 60)->assertOk();
});

test('the limit counts line and sale discounts together', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    addToCart($p);

    discountLine($p, 'percent', 6)->assertOk();
    discountOrder('percent', 6)->assertStatus(422); // 6% + ~5.6% of the rest > 10%
});

test('checkout enforces the cashier limit itself, not just the cart screen', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);

    // someone pokes a 50% discount straight into the session
    checkout(cartOf([[$p, 1, ['discount_type' => 'percent', 'discount_value' => 50]]]))
        ->assertSessionHas('error', 'Discounts above 10% need a manager.');

    expect(Sale::count())->toBe(0)->and($p->fresh()->stock_quantity)->toBe(10);
});

test('the tax setting is added on top and shown in the cart', function () {
    Setting::set('tax_rate', '7.5');
    $p = Product::factory()->create(['sale_price' => 200, 'stock_quantity' => 10]);
    addToCart($p);

    expect(cartHtml())->toContain('Tax (7.5%)')->toContain('15.00')->toContain('215.00');

    $this->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 300]);

    $sale = Sale::first();
    expect((float) $sale->tax_amount)->toBe(15.0)->and((float) $sale->grand_total)->toBe(215.0)
        ->and((float) $sale->change_due)->toBe(85.0)
        ->and((float) $sale->items->first()->tax_amount)->toBe(15.0);
});

test('with no tax rate the cart shows no tax line', function () {
    $p = Product::factory()->create(['sale_price' => 200, 'stock_quantity' => 10]);
    addToCart($p);

    expect(cartHtml())->not->toContain('Tax (');
});

test('the cart shows current database prices, not stale session prices', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10, 'name' => 'Priced Thing']);
    addToCart($p);

    $p->update(['sale_price' => 120]);

    expect(cartHtml())->toContain('120.00');
    $this->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 500]);
    expect((float) Sale::first()->grand_total)->toBe(120.0);
});

test('a discount that stops being valid after a price change is dropped with a warning instead of breaking the till', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $this->actingAs(User::factory()->manager()->create());
    addToCart($p);
    discountLine($p, 'fixed', 50)->assertOk();

    $p->update(['sale_price' => 30]); // the fixed 50 now exceeds the line

    $this->get(route('pos.index'))->assertOk()->assertSee('Discounts were removed');
    expect(session('cart')[$p->id]['discount_type'])->toBeNull();
});

test('adding another unit keeps the discount already set on that line', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    addToCart($p);
    discountLine($p, 'percent', 10)->assertOk();

    addToCart($p);

    expect(session('cart')[$p->id]['quantity'])->toBe(2)
        ->and((float) session('cart')[$p->id]['discount_value'])->toBe(10.0);
});

test('clearing the cart also clears the sale discount', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    addToCart($p);
    discountOrder('percent', 5)->assertOk();

    $this->postJson(route('pos.clear'))->assertOk();

    expect(session('cart'))->toBeNull()->and(session('cart_discount'))->toBeNull();
});

test('a leftover sale discount does not follow the cashier into the next sale', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    addToCart($p);
    discountOrder('percent', 5)->assertOk();
    $this->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 500]);

    expect(session('cart_discount'))->toBeNull();
});

test('the tax and cashier-limit settings validate their input', function () {
    $this->actingAs(User::factory()->manager()->create());

    $this->post(route('settings.update'), ['tax_rate' => 150])->assertSessionHasErrors('tax_rate');
    $this->post(route('settings.update'), ['max_cashier_discount' => -1])->assertSessionHasErrors('max_cashier_discount');

    $this->post(route('settings.update'), ['tax_rate' => '7.5', 'max_cashier_discount' => '15'])->assertSessionHasNoErrors();
    expect(setting('tax_rate'))->toBe('7.5')->and(setting('max_cashier_discount'))->toBe('15');
});
