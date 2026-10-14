<?php

/**
 * Branches: the migration, the screens, and who may stand where.
 *
 * The switching tests are the ones that matter most. A cashier reaching a branch
 * they do not work at would be able to read its takings, void its sales and sell
 * off its shelf - so it is checked here from the outside, as a request, not just
 * on the model.
 */

use App\Models\Branch;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\BranchContext;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/../BranchHelpers.php';

/*
|--------------------------------------------------------------------------
| The migration
|--------------------------------------------------------------------------
*/

test('the migration creates exactly one default branch', function () {
    $branches = Branch::all();

    expect($branches)->toHaveCount(1)
        ->and($branches->first()->is_default)->toBeTrue()
        ->and($branches->first()->is_active)->toBeTrue()
        ->and($branches->first()->code)->toBe('MAIN')
        ->and(Branch::default()->id)->toBe($branches->first()->id);
});

test('the backfill puts existing data and existing users in the default branch', function () {
    $main = defaultBranch();

    /*
     * The migration ran before this test's rows existed, so the backfill itself
     * is asserted on what it could reach: the columns are there, nullable, and
     * everything written since lands at the default branch. (The users table had
     * rows at migration time only in a seeded database; the stamp is what
     * guarantees the same outcome either way.)
     */
    expect(Schema::hasColumn('sales', 'branch_id'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'branch_id'))->toBeTrue()
        ->and(Schema::hasColumn('inventory_logs', 'branch_id'))->toBeTrue()
        ->and(Schema::hasColumn('shifts', 'branch_id'))->toBeTrue()
        ->and(Schema::hasTable('branch_stock'))->toBeTrue()
        ->and(Schema::hasTable('branch_user'))->toBeTrue()
        ->and(Schema::hasTable('stock_transfers'))->toBeTrue();

    $sale = saleAt($main, 50.0, ['branch_id' => null]);
    $product = Product::factory()->create(['stock_quantity' => 7]);

    expect($sale->branch_id)->toBe($main->id)
        // The catalogue is shared, so a product has no branch of its own - its
        // stock does.
        ->and(Schema::hasColumn('products', 'branch_id'))->toBeFalse()
        ->and(shelf($main, $product))->toBe(7);
});

test('the catalogue tables deliberately have no branch column', function () {
    // The decision, asserted: one catalogue, one customer ledger, one supplier
    // account, shared by every shop. Only the stock is per-site.
    foreach (['products', 'categories', 'customers', 'suppliers'] as $table) {
        expect(Schema::hasColumn($table, 'branch_id'))->toBeFalse();
    }
});

test('the migration rolls back', function () {
    /*
     * The migration file is required and driven directly rather than through
     * `migrate:rollback --step=1`, which rolls back whichever migration happens
     * to be last. This one stopped being last the moment another was added
     * after it, and the test then silently rolled back someone else's work and
     * asserted against a schema it had not touched. Naming the file is the only
     * form of this test that keeps meaning as the app grows - the same reason
     * every other migration test in this suite does it this way.
     */
    $migration = require base_path('database/migrations/2026_10_14_000012_add_branches.php');

    $migration->down();

    expect(Schema::hasTable('branches'))->toBeFalse()
        ->and(Schema::hasTable('branch_stock'))->toBeFalse()
        ->and(Schema::hasTable('branch_user'))->toBeFalse()
        ->and(Schema::hasTable('stock_transfers'))->toBeFalse()
        ->and(Schema::hasTable('stock_transfer_items'))->toBeFalse()
        // The columns come off the per-site tables too, indexes and foreign
        // keys first - which is the part SQLite is fussy about.
        ->and(Schema::hasColumn('sales', 'branch_id'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'branch_id'))->toBeFalse()
        ->and(Schema::hasColumn('tables', 'branch_id'))->toBeFalse()
        ->and(Schema::hasColumn('purchase_returns', 'branch_id'))->toBeFalse()
        // Nothing the rest of the app depends on went with them.
        ->and(Schema::hasTable('sales'))->toBeTrue()
        ->and(Schema::hasTable('products'))->toBeTrue();

    // And back up again, so a rollback is not a one-way door.
    $migration->up();

    expect(Schema::hasTable('branches'))->toBeTrue()
        ->and(Schema::hasColumn('sales', 'branch_id'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Nothing exists for a single-site shop
|--------------------------------------------------------------------------
*/

test('every branch screen 404s while branches are switched off', function () {
    $this->actingAs(User::factory()->admin()->create());

    expect(feature('branches'))->toBeFalse();

    $this->get(route('branches.index'))->assertNotFound();
    $this->get(route('transfers.index'))->assertNotFound();
    $this->get(route('transfers.create'))->assertNotFound();
    $this->post(route('branches.switch'), ['branch_id' => defaultBranch()->id])->assertNotFound();
});

test('no branch switcher appears while branches are switched off', function () {
    makeBranch('TWO');

    $this->actingAs(User::factory()->manager()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('id="active-branch"', false);
});

/*
|--------------------------------------------------------------------------
| Who may manage branches
|--------------------------------------------------------------------------
*/

test('a cashier cannot reach the branch screens at all', function () {
    $main = defaultBranch();
    enableBranches();

    $this->actingAs(staffAt($main, 'cashier'));

    // Blocked by the route's role middleware before a policy is even consulted.
    $this->get(route('branches.index'))->assertForbidden();
    $this->post(route('branches.store'), ['name' => 'Mine', 'code' => 'MINE'])->assertForbidden();
    $this->get(route('transfers.index'))->assertForbidden();
    $this->get(route('transfers.create'))->assertForbidden();
});

test('a manager reads the branch list but cannot open a branch', function () {
    $main = defaultBranch();
    enableBranches();

    $this->actingAs(staffAt($main, 'manager'));

    $this->get(route('branches.index'))->assertOk()->assertSee('MAIN');

    // Opening a shop is a structural decision: an admin's.
    $this->post(route('branches.store'), ['name' => 'Lekki', 'code' => 'LEK'])->assertForbidden();

    expect(Branch::count())->toBe(1);
});

test('an admin opens a branch, and is assigned to it so they can go there', function () {
    enableBranches();
    $admin = User::factory()->admin()->create(['branch_id' => defaultBranch()->id]);

    $this->actingAs($admin)
        ->post(route('branches.store'), [
            'name' => 'Lekki',
            'code' => 'lek',
            'address' => '1 Admiralty Way',
            'phone' => '08000000000',
        ])
        ->assertRedirect(route('branches.index'));

    $lekki = Branch::where('name', 'Lekki')->firstOrFail();

    expect($lekki->code)->toBe('LEK')          // normalised: shouted down a phone
        ->and($lekki->is_active)->toBeTrue()
        ->and($lekki->is_default)->toBeFalse()  // the default does not move
        ->and($lekki->staff()->whereKey($admin->id)->exists())->toBeTrue();
});

test('a branch code cannot be used twice', function () {
    enableBranches();
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('branches.store'), ['name' => 'Another Main', 'code' => 'MAIN'])
        ->assertSessionHasErrors('code');

    expect(Branch::count())->toBe(1);
});

test('closing a branch deactivates it and keeps every row it ever touched', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    $sale = saleAt($lekki, 400.0);

    $this->actingAs(User::factory()->admin()->create(['branch_id' => $main->id]))
        ->delete(route('branches.destroy', $lekki))
        ->assertRedirect(route('branches.index'));

    expect($lekki->fresh()->is_active)->toBeFalse()
        // Not deleted: a branch id is stamped on everything it saw.
        ->and(Branch::whereKey($lekki->id)->exists())->toBeTrue()
        ->and(Sale::withoutBranchScope()->whereKey($sale->id)->first()->branch_id)->toBe($lekki->id);
});

test('the default branch cannot be closed or deleted', function () {
    $main = defaultBranch();
    enableBranches();

    $this->actingAs(User::factory()->admin()->create(['branch_id' => $main->id]));

    // Something has to be left for anything with no branch of its own to fall
    // back to, so the policy refuses outright...
    $this->delete(route('branches.destroy', $main))->assertForbidden();

    // ...and the update form refuses to switch it off either.
    $this->put(route('branches.update', $main), [
        'name' => $main->name,
        'code' => $main->code,
        'is_active' => '0',
    ])->assertRedirect();

    expect($main->fresh()->is_active)->toBeTrue();
});

test('making a branch the default clears the flag on every other one', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    $this->actingAs(User::factory()->admin()->create(['branch_id' => $main->id]))
        ->put(route('branches.update', $lekki), [
            'name' => $lekki->name,
            'code' => $lekki->code,
            'is_active' => '1',
            'is_default' => '1',
        ])->assertRedirect();

    // Two defaults would quietly send new rows to the wrong shop.
    expect(Branch::where('is_default', true)->count())->toBe(1)
        ->and($lekki->fresh()->is_default)->toBeTrue()
        ->and($main->fresh()->is_default)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Switching branch: the control that matters
|--------------------------------------------------------------------------
*/

test('a cashier cannot switch to a branch they are not assigned to', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    // Works at MAIN only.
    $cashier = staffAt($main, 'cashier');

    $this->actingAs($cashier)
        ->post(route('branches.switch'), ['branch_id' => $lekki->id])
        ->assertForbidden();

    // And the refusal is not cosmetic: they are still at their own branch.
    $this->get(route('pos.index'))->assertOk();

    expect(app(BranchContext::class)->id())->toBe($main->id)
        ->and($cashier->canUseBranch($lekki))->toBeFalse();
});

test('a cashier can switch to a branch they are assigned to', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    // A relief cashier who genuinely works at both.
    $cashier = staffAt($main, 'cashier', [$lekki]);

    $this->actingAs($cashier)
        ->post(route('branches.switch'), ['branch_id' => $lekki->id])
        ->assertRedirect();

    $this->get(route('pos.index'))->assertOk();

    expect(app(BranchContext::class)->id())->toBe($lekki->id);
});

test('a cashier cannot switch to a closed branch they are assigned to', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    $cashier = staffAt($main, 'cashier', [$lekki]);
    $lekki->forceFill(['is_active' => false])->save();

    $this->actingAs($cashier)
        ->post(route('branches.switch'), ['branch_id' => $lekki->id])
        ->assertForbidden();
});

test('a manager may stand in any open branch', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    // Not assigned to Lekki: running several sites is the job.
    $manager = staffAt($main, 'manager');

    $this->actingAs($manager)
        ->post(route('branches.switch'), ['branch_id' => $lekki->id])
        ->assertRedirect();

    expect(app(BranchContext::class)->id())->toBe($lekki->id);
});

test('switching branch empties the cart', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    $product = atBranch($main, fn () => Product::factory()->create(['stock_quantity' => 5]));
    $cashier = staffAt($main, 'cashier', [$lekki]);

    /*
     * A half-rung-up sale belongs to the floor it was started on: carrying it
     * across would price goods from a shelf the cashier is no longer standing
     * at.
     */
    $this->actingAs($cashier)
        ->withSession(['cart' => cartOf([[$product, 2]]), 'cart_discount' => ['type' => 'percent', 'value' => 5]])
        ->post(route('branches.switch'), ['branch_id' => $lekki->id])
        ->assertRedirect()
        ->assertSessionMissing('cart')
        ->assertSessionMissing('cart_discount');
});

test('switching to a branch that does not exist is a validation error', function () {
    enableBranches();

    $this->actingAs(staffAt(defaultBranch(), 'manager'))
        ->post(route('branches.switch'), ['branch_id' => 99999])
        ->assertSessionHasErrors('branch_id');
});

/*
|--------------------------------------------------------------------------
| The switcher in the header
|--------------------------------------------------------------------------
*/

test('the header shows a switcher only when there is a choice to make', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    // One branch to choose from: nothing to switch, so nothing is drawn.
    $this->actingAs(staffAt($main, 'cashier'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('id="active-branch"', false);

    // Two: the switcher appears, naming both.
    $this->actingAs(staffAt($main, 'cashier', [$lekki]))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('id="active-branch"', false)
        ->assertSee('MAIN')
        ->assertSee('LEK');
});

test('the switcher only offers a cashier the branches they may use', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    $abuja = makeBranch('ABJ');
    enableBranches();

    $html = $this->actingAs(staffAt($main, 'cashier', [$lekki]))
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    // A branch they are not assigned to is not even offered - and posting it
    // anyway is refused (see the switching tests above).
    expect($html)->toContain('id="active-branch"')
        ->and($html)->toContain('LEK')
        ->and($html)->not->toContain('ABJ');
});

/*
|--------------------------------------------------------------------------
| The branch detail screen
|--------------------------------------------------------------------------
*/

test('the branch page reports that branch stock, not the group total', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    $product = atBranch($main, fn () => Product::factory()->create([
        'name' => 'Rice', 'stock_quantity' => 20, 'cost_price' => 10,
    ]));
    atBranch($lekki, fn () => $product->increment('stock_quantity', 3));

    // Standing in MAIN, looking at LEK: the page must answer for LEK.
    $response = $this->actingAs(staffAt($main, 'manager'))
        ->get(route('branches.show', $lekki))
        ->assertOk()
        ->assertSee('Rice');

    $row = $response->viewData('stock')->firstWhere('id', $product->id);

    // LEK's shelf, and the group total beside it - both on the page, because
    // "we have some, just not here" is the question this screen answers.
    expect($row->stockAt($lekki))->toBe(3)
        ->and((int) $row->stock_quantity)->toBe(23);
});

test('the branch list totals each branch separately', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    saleAt($main, 100.0);
    saleAt($lekki, 250.0);

    $this->actingAs(staffAt($main, 'manager'))
        ->get(route('branches.index'))
        ->assertOk()
        ->assertSee(money(100.0))
        ->assertSee(money(250.0));
});
