<?php

/**
 * The global branch scope: the piece of this feature everything else rests on.
 *
 * Two halves, and they are equally important:
 *
 *   - with `feature('branches')` OFF the scope must be completely inert, because
 *     that is what keeps every other test in this suite, and every existing
 *     single-site install, behaving exactly as before;
 *   - with it ON a branch must not be able to see, or sell, another branch's
 *     anything.
 */

use App\Models\Branch;
use App\Models\Expense;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Scopes\BranchScope;
use App\Services\BranchContext;

require_once __DIR__ . '/../BranchHelpers.php';

/*
|--------------------------------------------------------------------------
| Switched off: nothing changes
|--------------------------------------------------------------------------
*/

test('the scope is inert while branches are switched off', function () {
    $main = defaultBranch();
    $other = makeBranch('TWO');

    // Two sales filed at two different shops...
    saleAt($main);
    saleAt($other);

    expect(feature('branches'))->toBeFalse()
        ->and(app(BranchContext::class)->scoping())->toBeFalse()
        // ...and a single-site shop sees both, because it is not filtering.
        ->and(Sale::count())->toBe(2);
});

test('rows are still stamped with the default branch while branches are off', function () {
    $main = defaultBranch();

    // Passing branch_id null is how a caller says "wherever I am": the trait
    // only stamps a row that has not already been told.
    $sale = saleAt($main, 100.0, ['branch_id' => null]);
    $expense = expenseFor($sale->user);

    /*
     * The point of stamping while the feature is off: a shop that trades
     * single-site for a year and then opens a second one finds its whole
     * history already filed in the right place.
     */
    expect($sale->branch_id)->toBe($main->id)
        ->and($expense->branch_id)->toBe($main->id);
});

test('the scope adds no SQL at all while branches are off', function () {
    // Belt and braces on "inert": not merely the same results, the same query.
    expect(Sale::query()->toSql())->not->toContain('branch_id');
});

/*
|--------------------------------------------------------------------------
| Switched on: a branch sees only itself
|--------------------------------------------------------------------------
*/

test('a sale at one branch is invisible from another', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    atBranch($main, fn () => saleAt($main, 100.0, ['invoice_number' => 'MAIN-1', 'branch_id' => null]));
    atBranch($lekki, fn () => saleAt($lekki, 100.0, ['invoice_number' => 'LEK-1', 'branch_id' => null]));

    atBranch($main, function () {
        expect(Sale::count())->toBe(1)
            ->and(Sale::first()->invoice_number)->toBe('MAIN-1');
    });

    atBranch($lekki, function () {
        expect(Sale::count())->toBe(1)
            ->and(Sale::first()->invoice_number)->toBe('LEK-1');
    });
});

test('every branch-aware model is filtered, not just sales', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    $user = User::factory()->create();
    $product = Product::factory()->create(['stock_quantity' => 0]);
    enableBranches();

    atBranch($lekki, function () use ($user, $product) {
        expenseFor($user, 500.0, ['name' => 'Rent']);
        Shift::create(['user_id' => $user->id, 'opened_at' => now(), 'opening_float' => 0, 'status' => Shift::STATUS_OPEN]);
        InventoryLog::create(['product_id' => $product->id, 'user_id' => $user->id, 'type' => 'IN', 'quantity' => 1, 'reason' => 'test']);
    });

    atBranch($main, function () {
        expect(Expense::count())->toBe(0)
            ->and(Shift::count())->toBe(0)
            ->and(InventoryLog::count())->toBe(0);
    });

    atBranch($lekki, function () {
        expect(Expense::count())->toBe(1)
            ->and(Shift::count())->toBe(1)
            ->and(InventoryLog::count())->toBe(1);
    });
});

test('a row is stamped with the branch being acted as', function () {
    $lekki = makeBranch('LEK');
    enableBranches();

    $sale = atBranch($lekki, fn () => saleAt($lekki, 100.0, ['branch_id' => null]));

    expect($sale->branch_id)->toBe($lekki->id);
});

test('an explicit branch wins over the one being acted as', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    // How a transfer records a movement at the far end, and how a test sets a
    // scene up at a shop it is not standing in.
    $sale = atBranch($main, fn () => saleAt($lekki));

    expect($sale->branch_id)->toBe($lekki->id);
});

/*
|--------------------------------------------------------------------------
| The escape hatch
|--------------------------------------------------------------------------
*/

test('withoutBranchScope sees every branch, so a group-wide report can', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    saleAt($main, 100.0);
    saleAt($lekki, 250.0);

    atBranch($main, function () {
        // Scoped: this shop only.
        expect(Sale::sum('grand_total'))->toEqual(100)
            // Escaped: the whole group. This is the owner's consolidated figure.
            ->and(Sale::withoutBranchScope()->sum('grand_total'))->toEqual(350)
            ->and(Sale::withoutBranchScope()->count())->toBe(2);
    });
});

test('forBranch reads one named branch whichever one we are standing in', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    saleAt($lekki, 70.0);

    atBranch($main, function () use ($lekki) {
        expect(Sale::query()->forBranch($lekki)->sum('grand_total'))->toEqual(70);
    });
});

test('groupWide suspends filtering for a whole region and restores it afterwards', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    saleAt($lekki);

    atBranch($main, function () {
        $branches = app(BranchContext::class);

        expect($branches->groupWide(fn () => Sale::count()))->toBe(1)
            // Put back, so a report escaping the scope cannot leak into the rest
            // of the request.
            ->and($branches->scoping())->toBeTrue()
            ->and(Sale::count())->toBe(0);
    });
});

test('groupWide restores filtering even when the callback throws', function () {
    $main = defaultBranch();
    enableBranches();

    atBranch($main, function () {
        $branches = app(BranchContext::class);

        expect(fn () => $branches->groupWide(fn () => throw new RuntimeException('boom')))
            ->toThrow(RuntimeException::class);

        expect($branches->scoping())->toBeTrue();
    });
});

test('the escape hatch is the only way out, and it is the named one', function () {
    $main = defaultBranch();
    enableBranches();

    atBranch($main, function () {
        // The scope is on the model, so a bare query carries it...
        expect(Sale::query()->toSql())->toContain('branch_id')
            // ...and removing it is spelled one way, which is what makes
            // `grep -rn withoutBranchScope app/` an audit rather than a guess.
            ->and(Sale::withoutBranchScope()->toSql())->not->toContain('branch_id')
            ->and(Sale::query()->withoutGlobalScope(BranchScope::class)->toSql())->not->toContain('branch_id');
    });
});

/*
|--------------------------------------------------------------------------
| Stock: the group total and the per-branch split
|--------------------------------------------------------------------------
*/

test('the migration files all existing stock at the default branch', function () {
    // Created before branches were switched on - the single-site case.
    $product = Product::factory()->create(['stock_quantity' => 40]);

    expect(shelf(defaultBranch(), $product))->toBe(40)
        ->and((int) $product->stock_quantity)->toBe(40);
});

test('a stock movement only touches the acting branch, and the total stays the sum', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    // 30 at the default branch...
    $product = atBranch($main, fn () => Product::factory()->create(['stock_quantity' => 30]));
    // ...and 12 more delivered to Lekki, which raises the group total.
    atBranch($lekki, fn () => $product->increment('stock_quantity', 12));

    $product->refresh();

    expect(shelf($main, $product))->toBe(30)
        ->and(shelf($lekki, $product))->toBe(12)
        // The invariant: the product column is the sum of the shelves.
        ->and((int) $product->stock_quantity)->toBe(42);

    // Sell four at Lekki: only Lekki's shelf moves.
    atBranch($lekki, fn () => $product->decrement('stock_quantity', 4));

    $product->refresh();

    expect(shelf($main, $product))->toBe(30)
        ->and(shelf($lekki, $product))->toBe(8)
        ->and((int) $product->stock_quantity)->toBe(38);
});

test('a sale at one branch decrements that branch stock only', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    $product = atBranch($main, fn () => Product::factory()->create(['stock_quantity' => 10, 'sale_price' => 100]));
    atBranch($lekki, fn () => $product->increment('stock_quantity', 10));

    $cashier = staffAt($lekki, 'cashier');

    // Through the real till, as a real request, so the acting branch comes from
    // the middleware rather than from a test wrapper.
    $this->actingAs($cashier)
        ->withSession(['cart' => cartOf([[$product, 3]])])
        ->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 100000])
        ->assertRedirect();

    $product->refresh();

    expect(shelf($lekki, $product))->toBe(7)
        // Untouched: the goods came off the shelf the cashier was standing at.
        ->and(shelf($main, $product))->toBe(10)
        ->and((int) $product->stock_quantity)->toBe(17);
});

test('stockAt answers per branch while stock_quantity stays the group total', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    $product = atBranch($main, fn () => Product::factory()->create(['stock_quantity' => 5]));
    atBranch($lekki, fn () => $product->increment('stock_quantity', 9));

    $product->refresh();

    expect($product->stockAt($main))->toBe(5)
        ->and($product->stockAt($lekki))->toBe(9)
        ->and((int) $product->stock_quantity)->toBe(14)
        ->and($product->stockByBranch()->sum())->toBe(14);
});

test('withBranchStock loads a named branch figure in one query', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    $product = atBranch($main, fn () => Product::factory()->create(['stock_quantity' => 2]));
    atBranch($lekki, fn () => $product->increment('stock_quantity', 11));

    $loaded = Product::query()->withBranchStock($lekki)->findOrFail($product->id);

    expect((int) $loaded->branch_stock_quantity)->toBe(11)
        // Read off the loaded column, so a list of products costs no extra query.
        ->and($loaded->stockAt())->toBe(11);
});

test('the catalogue itself is shared, not split per branch', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    $product = atBranch($main, fn () => Product::factory()->create(['stock_quantity' => 1]));

    // One SKU, one barcode, one price, visible from both shops. Splitting the
    // catalogue would mean re-keying every product for every new shop.
    atBranch($lekki, function () use ($product) {
        expect(Product::whereKey($product->id)->exists())->toBeTrue()
            ->and(Product::count())->toBe(1);
    });
});

/*
|--------------------------------------------------------------------------
| Resolving which branch we are acting as
|--------------------------------------------------------------------------
*/

test('a user with no choice lands at their home branch', function () {
    $lekki = makeBranch('LEK');
    enableBranches();

    $cashier = staffAt($lekki, 'cashier');

    $this->actingAs($cashier)->get(route('pos.index'))->assertOk();

    expect(app(BranchContext::class)->id())->toBe($lekki->id);
});

test('a deactivated branch is not resolved, even from a stored preference', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    $manager = staffAt($lekki, 'manager');

    $this->actingAs($manager)
        ->post(route('branches.switch'), ['branch_id' => $lekki->id])
        ->assertRedirect();

    $lekki->forceFill(['is_active' => false])->save();

    // Closed is nobody's, so the stored choice is dropped and they fall back.
    $this->get(route('pos.index'))->assertOk();

    expect(app(BranchContext::class)->id())->toBe($main->id);
});

test('the till checks availability against the shelf it is standing at, not the group total', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    /*
     * 10 at MAIN, 1 at LEK. A cashier standing at LEK asks for 3.
     *
     * This was a real gap: the refusal check compared the quantity against
     * `products.stock_quantity`, which is the group total, so LEK was allowed
     * to sell stock sitting on MAIN's shelf and oversell down to -2. The
     * movement was always correct - it comes off LEK's shelf and only LEK's -
     * but the check in front of it was asking the wrong question.
     *
     * Both call sites now read `$product->stockAt()`, which answers for the
     * acting branch and falls back to the total when branches are switched off.
     */
    $product = atBranch($main, fn () => Product::factory()->create([
        'stock_quantity' => 10, 'sale_price' => 100, 'track_stock' => true, 'is_service' => false,
    ]));
    atBranch($lekki, fn () => $product->increment('stock_quantity', 1));

    $this->actingAs(staffAt($lekki, 'cashier'))
        ->withSession(['cart' => cartOf([[$product, 3]])])
        ->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 100000])
        // Refused: LEK has 1, whatever MAIN is holding.
        ->assertSessionHas('error');

    $product->refresh();

    // Nothing moved on either shelf, and the invariant is untouched.
    expect(shelf($lekki, $product))->toBe(1)
        ->and(shelf($main, $product))->toBe(10)
        ->and((int) $product->stock_quantity)->toBe(11)
        ->and(shelf($main, $product) + shelf($lekki, $product))->toBe(11);
});

test('the till still sells what its own shelf really has', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    // Guards the obvious over-correction: refusing everything at a branch.
    $product = atBranch($main, fn () => Product::factory()->create([
        'stock_quantity' => 2, 'sale_price' => 100, 'track_stock' => true, 'is_service' => false,
    ]));
    atBranch($lekki, fn () => $product->increment('stock_quantity', 5));

    $this->actingAs(staffAt($lekki, 'cashier'))
        ->withSession(['cart' => cartOf([[$product, 4]])])
        ->post(route('pos.checkout'), ['payment_method' => 'cash', 'paid_amount' => 100000])
        ->assertSessionMissing('error');

    $product->refresh();

    expect(shelf($lekki, $product))->toBe(1)
        ->and(shelf($main, $product))->toBe(2)
        ->and((int) $product->stock_quantity)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Reports
|--------------------------------------------------------------------------
|
| ReportService aggregates through DB::table() for speed, so the Eloquent global
| scope does not reach it and the branch has to be applied by hand. These are the
| tests that it is, and that the group-wide escape still works.
*/

test('reports answer for the branch being acted as', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    saleAt($main, 100.0);
    saleAt($lekki, 250.0);

    $reports = app(App\Services\ReportService::class);
    $start = now()->copy()->subDay();
    $end = now()->copy()->addDay();

    atBranch($main, function () use ($reports, $start, $end) {
        expect($reports->takings($start, $end)['gross'])->toEqual(100.0);
    });

    atBranch($lekki, function () use ($reports, $start, $end) {
        expect($reports->takings($start, $end)['gross'])->toEqual(250.0);
    });
});

test('a group-wide report sees every branch through the escape hatch', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    saleAt($main, 100.0);
    saleAt($lekki, 250.0);

    $reports = app(App\Services\ReportService::class);
    $branches = app(BranchContext::class);
    $start = now()->copy()->subDay();
    $end = now()->copy()->addDay();

    atBranch($main, function () use ($reports, $branches, $start, $end) {
        // The owner's consolidated figure. One escape hatch covers both the
        // Eloquent scope and the hand-applied filter in ReportService.
        expect($branches->groupWide(fn () => $reports->takings($start, $end)['gross']))->toEqual(350.0);
    });
});

test('an admin asking for scope=all gets the whole group on a report screen', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    saleAt($main, 100.0);
    saleAt($lekki, 250.0);

    $admin = User::factory()->admin()->create(['branch_id' => $main->id]);

    // Standing in MAIN: MAIN's takings.
    $this->actingAs($admin)
        ->get(route('reports.sales'))
        ->assertOk()
        ->assertSee(money(100.0))
        ->assertDontSee(money(250.0));

    // Asking for the group: both.
    $this->get(route('reports.sales', ['scope' => 'all']))
        ->assertOk()
        ->assertSee(money(350.0));
});

test('a manager cannot widen a report to the whole group', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    saleAt($main, 100.0);
    saleAt($lekki, 250.0);

    // A manager runs shops; an owner owns them. scope=all is quietly ignored.
    $this->actingAs(staffAt($main, 'manager'))
        ->get(route('reports.sales', ['scope' => 'all']))
        ->assertOk()
        ->assertDontSee(money(350.0));
});

test('what a customer owes stays group-wide, because the debt is to the business', function () {
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    // Sold on credit in two different shops to the same business.
    saleAt($main, 100.0, ['balance_due' => 100, 'payment_status' => Sale::PAYMENT_UNPAID, 'paid_amount' => 0]);
    saleAt($lekki, 250.0, ['balance_due' => 250, 'payment_status' => Sale::PAYMENT_UNPAID, 'paid_amount' => 0]);

    atBranch($main, function () {
        /*
         * Not 100. A customer can buy in one shop and pay in another, so
         * splitting the debt by where it was incurred would show two balances
         * for one person and chase them twice.
         */
        expect(app(App\Services\ReportService::class)->totalOwed()['owed'])->toEqual(350.0);
    });
});
