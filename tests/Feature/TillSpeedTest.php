<?php

use App\Models\Product;
use App\Models\User;

/*
| What the server owes the till's fast path:
|
|  - every cart action can return the redrawn cart panel in the SAME answer, so the till makes one
|    request per tap instead of a POST followed by a GET;
|  - that panel always shows what the server really holds, including after a refusal;
|  - the page carries what the browser needs to show a line, and to search the catalogue, without asking.
*/

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

function renderingPost(string $route, $params = [], array $body = [])
{
    return test()->withHeaders(['X-Till-Render' => '1', 'Accept' => 'application/json'])
        ->postJson(route($route, $params), $body);
}

test('a cart action answers with the redrawn panel when the till asks for it', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 50, 'name' => 'Renderable Widget']);

    $response = renderingPost('pos.add', $p->id)->assertOk()->assertJsonPath('success', true);

    expect($response->json('html'))
        ->toContain('Renderable Widget')
        ->toContain('data-line="' . $p->id . '"')
        ->toContain('data-total="grand"');
});

test('without the header the answer is the plain JSON it always was', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 50]);

    $this->postJson(route('pos.add', $p->id))->assertOk()->assertJsonMissingPath('html');
});

test('every cart action can carry the panel', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 50]);

    renderingPost('pos.add', $p->id)->assertJsonStructure(['html']);
    renderingPost('pos.update', $p->id, ['quantity' => 3])->assertJsonStructure(['html']);
    renderingPost('pos.discount.line', $p->id, ['type' => 'percent', 'value' => 5])->assertJsonStructure(['html']);
    renderingPost('pos.discount.order', [], ['type' => 'percent', 'value' => 2])->assertJsonStructure(['html']);
    renderingPost('pos.remove', $p->id)->assertJsonStructure(['html']);
    renderingPost('pos.clear')->assertJsonStructure(['html']);
});

test('the panel in the answer shows the quantity the server actually holds', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 50]);

    renderingPost('pos.add', $p->id);
    $html = renderingPost('pos.update', $p->id, ['quantity' => 7])->json('html');

    expect($html)->toContain('value="7"')->toContain('data-committed="7"');
});

test('a refusal still returns the panel, showing the cart as it really is', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 2]);

    renderingPost('pos.add', $p->id);
    $response = renderingPost('pos.update', $p->id, ['quantity' => 9])->assertStatus(422);

    expect($response->json('message'))->toContain('Only 2')
        ->and($response->json('html'))->toContain('value="1"'); // not the 9 the till optimistically showed
});

test('a scan returns the panel too, but "not found" has none to return', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 50, 'barcode' => '5550001112223']);

    renderingPost('pos.scan', [], ['code' => '5550001112223'])->assertOk()->assertJsonStructure(['html']);
    renderingPost('pos.scan', [], ['code' => 'NO-SUCH-CODE'])->assertJsonMissingPath('html');
});

test('the AJAX cart panel is exactly what the actions return', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 50]);

    $inline = renderingPost('pos.add', $p->id)->json('html');
    $fetched = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get(route('pos.index'))->getContent();

    expect($fetched)->toBe($inline);
});

test('the till page carries the data the browser needs to show a line and search without asking', function () {
    Product::factory()->create(['name' => 'Tap Me', 'sale_price' => 12.5, 'stock_quantity' => 9, 'sku' => 'TAP-1', 'barcode' => '777']);

    $this->get(route('pos.index'))->assertOk()
        ->assertSee('data-name="Tap Me"', false)
        ->assertSee('data-price="12.5"', false)
        ->assertSee('data-stock="9"', false)
        ->assertSee('data-barcode="777"', false)
        ->assertSee('data-search="tap me tap-1 777"', false)
        ->assertSee('id="cart-line-template"', false)
        ->assertSee('__NAME__', false);   // the placeholder line the browser clones
});

test('the till config is valid JSON and points at the real routes', function () {
    $html = $this->get(route('pos.index'))->getContent();

    preg_match('#<script type="application/json" id="till-config">(.*?)</script>#s', $html, $m);

    $config = json_decode($m[1] ?? '', true);

    expect($config)->toBeArray()
        ->and($config['searchUrl'])->toBe(route('pos.search'))
        ->and($config['scanUrl'])->toBe(route('pos.scan'))
        ->and($config['cartUrl'])->toBe(route('pos.index'))
        ->and($config['symbol'])->toBe(currency());
});

test('a product name can never break out of the till config or the card attributes', function () {
    Product::factory()->create(['name' => '"><script>alert(1)</script></script>', 'stock_quantity' => 5]);

    $html = $this->get(route('pos.index'))->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>');
});

test('the catalogue is searched in the browser when it all fits on the page, and by the server when it does not', function () {
    Product::factory()->count(3)->create();

    $config = fn () => (function () {
        preg_match('#id="till-config">(.*?)</script>#s', $this->get(route('pos.index'))->getContent(), $m);

        return json_decode($m[1], true);
    })->call($this);

    expect($config()['catalogueComplete'])->toBeTrue();

    // Insert rows directly: the factory's unique faker values run out long before 200 products.
    $base = Product::factory()->raw();
    Product::query()->insert(array_map(
        fn ($i) => array_merge($base, [
            'name' => 'Bulk ' . $i,
            'sku' => 'BULK-' . $i,
            'barcode' => '9' . str_pad((string) $i, 12, '0', STR_PAD_LEFT),
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        range(1, 200),
    ));

    expect($config()['catalogueComplete'])->toBeFalse();
});

test('the two columns scroll on their own from the large breakpoint up', function () {
    $html = $this->get(route('pos.index'))->getContent();

    // The page fills the shell; each column is its own scroller; the product list is the part of its column that scrolls.
    expect($html)
        ->toContain('lg:h-full')
        ->toContain('lg:overflow-y-auto')
        ->toContain('lg:grid-rows-[minmax(0,1fr)]')
        ->toContain('id="product-list"')
        ->toContain('id="cart-section"');
});

test('the old inline till script is gone: the behaviour lives in one tested module', function () {
    $html = $this->get(route('pos.index'))->getContent();

    expect($html)->not->toContain('function reloadCart')->not->toContain('function tillPost');
});
