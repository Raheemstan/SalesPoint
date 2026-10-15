<?php

use App\Models\Product;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| What the till endpoints are allowed to hand out
|--------------------------------------------------------------------------
|
| /pos/search and /pos/scan used to answer with whole Product models. Product
| declares no $hidden, so every column went over the wire - including
| cost_price, supplier_id, alert_quantity and reorder_quantity - to anyone who
| could open the till, which is every cashier.
|
| That contradicted the app's own stated design: ProductPolicy and
| PurchasePolicy both say in as many words that a cashier never sees a cost.
| Both endpoints now select POSController::TILL_COLUMNS, and this file is the
| lock on that.
|
| Two decisions worth keeping:
|
|   - The assertions are on *keys*, not on the response text. Searching the
|     body for the cost as a string passes by accident the moment a product's
|     cost equals its price, which is exactly what a test product looks like.
|   - Every role is covered, not just the cashier. There is no reason for the
|     till to quote a cost to anybody, and a cashier-only test invites the next
|     person to "fix" a failure by widening it to managers.
|
*/

/** Columns a till response must never carry, whoever is signed in. */
const TILL_FORBIDDEN_KEYS = ['cost_price', 'supplier_id', 'alert_quantity', 'reorder_quantity'];

/** Columns the till's JavaScript reads, so removing one breaks the screen. */
const TILL_REQUIRED_KEYS = ['id', 'name', 'sale_price', 'stock_quantity', 'track_stock', 'is_service'];

/**
 * Assert a list of till rows is both safe and still usable.
 *
 * @param  array<int, array<string, mixed>>  $rows
 */
function assertTillRowsAreSafe(array $rows, string $where): void
{
    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        $keys = array_keys($row);

        expect(array_values(array_intersect(TILL_FORBIDDEN_KEYS, $keys)))
            ->toBe([], $where . ' leaked back-office columns');

        expect(array_values(array_diff(TILL_REQUIRED_KEYS, $keys)))
            ->toBe([], $where . ' stopped sending columns the till needs');
    }
}

/** One product the search and the partial scan will both find. */
function tillProduct(): Product
{
    return Product::factory()->create([
        'name' => 'Zebra Milk Powder',
        'sku' => 'ZEB-001',
        'barcode' => '5901234123457',
        'cost_price' => 400,
        'sale_price' => 650,
        'stock_quantity' => 12,
        'alert_quantity' => 3,
        'reorder_quantity' => 20,
    ]);
}

dataset('till roles', [
    'cashier' => ['cashier'],
    'manager' => ['manager'],
    'admin' => ['admin'],
]);

test('product search never sends a cost price to the till', function (string $role) {
    tillProduct();

    $this->actingAs(User::factory()->state(['role' => $role])->create());

    $response = $this->getJson(route('pos.search', ['q' => '']))->assertOk();

    assertTillRowsAreSafe($response->json(), "/pos/search as {$role}");
})->with('till roles');

test('a scan that matches nothing never sends a cost price in its suggestions', function (string $role) {
    tillProduct();

    $this->actingAs(User::factory()->state(['role' => $role])->create());

    /*
    | "Zebra" is not an exact barcode or SKU, so scan() falls through to the
    | no-match branch and answers with the same search results under `matches`.
    | That branch is a second copy of the query and was leaking independently.
    */
    $response = $this->postJson(route('pos.scan'), ['code' => 'Zebra'])
        ->assertOk()
        ->assertJsonPath('found', false);

    assertTillRowsAreSafe($response->json('matches'), "/pos/scan matches as {$role}");
})->with('till roles');

test('the till payload is a short whitelist rather than a whole product row', function () {
    /*
    | The count is the part that regresses: adding a column to products must not
    | silently add it to the till response. If this fails because the till
    | genuinely needs another column, add it to TILL_REQUIRED_KEYS too - do not
    | just raise the number.
    */
    tillProduct();

    $this->actingAs(User::factory()->cashier()->create());

    $row = $this->getJson(route('pos.search', ['q' => 'Zebra']))->assertOk()->json('0');

    expect(array_keys($row))->toHaveCount(8)
        ->and(count((new Product)->getFillable()))->toBeGreaterThan(8);
});
