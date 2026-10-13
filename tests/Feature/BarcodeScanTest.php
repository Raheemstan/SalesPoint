<?php

use App\Models\Product;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Barcode scanning
|--------------------------------------------------------------------------
|
| A scanner is a keyboard that types a code and presses Enter, so the till has
| to resolve the code and add the item in one round trip, with no picker in the
| middle. These cover that, and the LIKE-escaping bug the search box had.
*/

beforeEach(function () {
    $this->cashier = User::factory()->cashier()->create();
    $this->actingAs($this->cashier);
});

/** POST a code at the scan endpoint, the way the search box does on Enter. */
function scanCode(string $code)
{
    return test()->postJson(route('pos.scan'), ['code' => $code]);
}

test('an exact barcode scan adds that product and nothing else', function () {
    $wanted = Product::factory()->create(['barcode' => '5901234123457', 'name' => 'Scanned Thing', 'stock_quantity' => 5]);
    $other = Product::factory()->create(['barcode' => '4006381333931', 'stock_quantity' => 5]);

    scanCode('5901234123457')
        ->assertOk()
        ->assertJsonPath('found', true)
        ->assertJsonPath('success', true)
        ->assertJsonPath('quantity', 1)
        ->assertJsonPath('product.name', 'Scanned Thing');

    expect(array_keys(session('cart')))->toBe([$wanted->id])
        ->and(session('cart')[$wanted->id]['quantity'])->toBe(1)
        ->and(session('cart'))->not->toHaveKey($other->id);
});

test('scanning the same code again increments the quantity instead of adding a second line', function () {
    $product = Product::factory()->create(['barcode' => '111222333444', 'stock_quantity' => 10]);

    scanCode('111222333444')->assertOk()->assertJsonPath('quantity', 1);
    scanCode('111222333444')->assertOk()->assertJsonPath('quantity', 2);
    scanCode('111222333444')->assertOk()->assertJsonPath('quantity', 3);

    expect(session('cart'))->toHaveCount(1)
        ->and(session('cart')[$product->id]['quantity'])->toBe(3);
});

test('a line discount already on the scanned line survives being scanned again', function () {
    $product = Product::factory()->create(['barcode' => '999', 'sale_price' => 100, 'stock_quantity' => 10]);

    scanCode('999')->assertOk();
    $this->postJson(route('pos.discount.line', $product->id), ['type' => 'percent', 'value' => 10])->assertOk();
    scanCode('999')->assertOk();

    expect(session('cart')[$product->id]['quantity'])->toBe(2)
        ->and((float) session('cart')[$product->id]['discount_value'])->toBe(10.0);
});

test('an exact SKU is scanned too, for stock a shop labels itself', function () {
    $product = Product::factory()->create(['sku' => 'OWN-LABEL-7', 'barcode' => null, 'stock_quantity' => 3]);

    scanCode('OWN-LABEL-7')->assertOk()->assertJsonPath('found', true)->assertJsonPath('success', true);

    expect(session('cart'))->toHaveKey($product->id);
});

test('the barcode wins over a SKU that happens to be the same string', function () {
    $bySku = Product::factory()->create(['sku' => 'DUPE-CODE', 'barcode' => 'B-1', 'stock_quantity' => 5]);
    $byBarcode = Product::factory()->create(['sku' => 'S-2', 'barcode' => 'DUPE-CODE', 'stock_quantity' => 5]);

    scanCode('DUPE-CODE')->assertOk()->assertJsonPath('product.id', $byBarcode->id);

    expect(session('cart'))->toHaveKey($byBarcode->id)->and(session('cart'))->not->toHaveKey($bySku->id);
});

test('an unknown barcode does not blow up: it answers 200, changes nothing, and offers what nearly matched', function () {
    Product::factory()->create(['name' => 'Milk 1L', 'sku' => 'MILK-1', 'barcode' => '700000000001']);

    $response = scanCode('nosuchcode')
        ->assertOk()
        ->assertJsonPath('found', false)
        ->assertJsonPath('success', false);

    expect($response->json('message'))->toContain('nosuchcode');
    expect(session('cart'))->toBeNull();

    // A code that is a fragment of something real comes back as a short list to tap.
    $matches = scanCode('MILK')->assertOk()->assertJsonPath('found', false)->json('matches');

    expect($matches)->toHaveCount(1)->and($matches[0]['name'])->toBe('Milk 1L');
});

test('scanning past the available stock is refused, and says how many are left', function () {
    $product = Product::factory()->create(['barcode' => 'LAST-ONE', 'name' => 'Last One', 'stock_quantity' => 1]);

    scanCode('LAST-ONE')->assertOk()->assertJsonPath('success', true);

    scanCode('LAST-ONE')
        ->assertStatus(422)
        ->assertJsonPath('found', true)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Only 1 of Last One in stock.');

    expect(session('cart')[$product->id]['quantity'])->toBe(1);
});

test('a service can be scanned over and over: it has no stock to run out of', function () {
    $haircut = Product::factory()->create([
        'name' => 'Haircut',
        'barcode' => 'SERVICE-1',
        'is_service' => true,
        'track_stock' => false,
        'stock_quantity' => 0,
    ]);

    scanCode('SERVICE-1')->assertOk()->assertJsonPath('success', true);
    scanCode('SERVICE-1')->assertOk()->assertJsonPath('success', true);

    expect(session('cart')[$haircut->id]['quantity'])->toBe(2);
});

test('a blank or oversized code fails validation rather than reaching the catalogue', function () {
    $this->postJson(route('pos.scan'), ['code' => ''])->assertStatus(422)->assertJsonValidationErrors('code');
    $this->postJson(route('pos.scan'), [])->assertStatus(422)->assertJsonValidationErrors('code');
    $this->postJson(route('pos.scan'), ['code' => str_repeat('9', 101)])->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

/*
|--------------------------------------------------------------------------
| The LIKE escaping bug
|--------------------------------------------------------------------------
|
| The search pattern escaped % and _ with a backslash but never declared an
| ESCAPE clause. MySQL treats \ as an escape by default so it looked right in
| production; SQLite does not, so here - and in dev - a search for "50%" asked
| for "contains a literal backslash" and found nothing at all.
*/

test('a search for a literal percent sign finds the product with one in its name', function () {
    $offer = Product::factory()->create(['name' => '50% Off Mug', 'sku' => 'MUG-50']);
    Product::factory()->create(['name' => 'Plain Mug', 'sku' => 'MUG-01']);

    $results = $this->getJson(route('pos.search', ['q' => '%']))->assertOk()->json();

    expect($results)->toHaveCount(1)->and($results[0]['id'])->toBe($offer->id);

    // And the whole string still works, rather than the % swallowing the rest.
    expect($this->getJson(route('pos.search', ['q' => '50%']))->json())->toHaveCount(1);
});

test('a search for a literal underscore does not match every single-character gap', function () {
    $underscored = Product::factory()->create(['name' => 'Tea_Bags', 'sku' => 'TEA-1']);
    Product::factory()->create(['name' => 'TeaXBags', 'sku' => 'TEA-2']);

    $results = $this->getJson(route('pos.search', ['q' => 'Tea_Bags']))->assertOk()->json();

    expect($results)->toHaveCount(1)->and($results[0]['id'])->toBe($underscored->id);
});

test('the escape character itself is searched for literally', function () {
    $bang = Product::factory()->create(['name' => 'Bang! Sauce', 'sku' => 'BANG-1']);
    Product::factory()->create(['name' => 'Mild Sauce', 'sku' => 'MILD-1']);

    $results = $this->getJson(route('pos.search', ['q' => 'Bang!']))->assertOk()->json();

    expect($results)->toHaveCount(1)->and($results[0]['id'])->toBe($bang->id);
});

test('ordinary searches still match name, sku and barcode', function () {
    Product::factory()->create(['name' => 'Blue Pen', 'sku' => 'PEN-1', 'barcode' => '111']);
    Product::factory()->create(['name' => 'Red Pen', 'sku' => 'PEN-2', 'barcode' => '222']);

    expect($this->getJson(route('pos.search', ['q' => 'pen']))->json())->toHaveCount(2);
    expect($this->getJson(route('pos.search', ['q' => 'PEN-2']))->json())->toHaveCount(1);
    expect($this->getJson(route('pos.search', ['q' => '222']))->json())->toHaveCount(1);
});
