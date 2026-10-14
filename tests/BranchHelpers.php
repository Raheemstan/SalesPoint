<?php

/**
 * Helpers shared by the three multi-branch test files.
 *
 * In their own file rather than in tests/Helpers.php so this feature's tests
 * carry their own scaffolding, and `require_once` at the top of each file rather
 * than in Pest.php so nothing outside multi-branch pays for them.
 */

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Services\BranchContext;
use Illuminate\Support\Str;

if (!function_exists('enableBranches')) {
    /**
     * Switch multi-branch on (or off) for this test.
     *
     * Through Setting::set() because that is how a shop does it: there is no
     * `branches` key in config/sectors.php, so the per-install `feature_<name>`
     * override is the whole mechanism. Saving a Setting flushes the settings
     * cache, so feature() sees it immediately.
     */
    function enableBranches(bool $on = true): void
    {
        Setting::set('feature_branches', $on ? '1' : '0');

        // The context caches the branch it resolved; the answer changes when the
        // feature does.
        app(BranchContext::class)->forget();
    }
}

if (!function_exists('defaultBranch')) {
    /** The branch the migration created, which every existing row belongs to. */
    function defaultBranch(): Branch
    {
        return Branch::default();
    }
}

if (!function_exists('makeBranch')) {
    /** A second (or third) shop. */
    function makeBranch(string $code, array $attributes = []): Branch
    {
        return Branch::factory()->create(array_merge([
            'code' => $code,
            'name' => $code . ' Branch',
        ], $attributes));
    }
}

if (!function_exists('atBranch')) {
    /**
     * Run a callback as though signed in at a branch.
     *
     * The same mechanism the transfer service uses, because "which branch" is
     * what decides where a stock movement lands as well as what is readable.
     *
     * @template TReturn
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    function atBranch(Branch $branch, callable $callback)
    {
        return app(BranchContext::class)->actingAs($branch, $callback);
    }
}

if (!function_exists('shelf')) {
    /** What one branch holds of one product, read straight from the table. */
    function shelf(Branch $branch, Product $product): int
    {
        return BranchStock::quantity((int) $branch->id, (int) $product->id);
    }
}

if (!function_exists('staffAt')) {
    /**
     * A user whose home branch is $branch.
     *
     * The factory deliberately leaves `branch_id` null (a single-site shop has
     * no opinion about it), so a multi-branch test has to say.
     */
    function staffAt(Branch $branch, string $role = 'cashier', array $alsoAt = []): User
    {
        $user = User::factory()->state(['role' => $role])->create(['branch_id' => $branch->id]);

        $user->branches()->syncWithoutDetaching(
            array_merge([$branch->id], array_map(fn (Branch $extra) => $extra->id, $alsoAt))
        );

        return $user;
    }
}

if (!function_exists('saleAt')) {
    /**
     * A banked sale filed at one branch, without going through the till.
     *
     * SaleFactory has no defaults (the real thing is always built by
     * CheckoutService), and several money columns are NOT NULL, so the minimum
     * is filled in here rather than in six tests. The `branch_id` is passed
     * explicitly on purpose - that is the path an explicit branch has to take,
     * and it means these fixtures do not depend on which branch is being acted
     * as while they are built.
     */
    function saleAt(Branch $branch, float $total = 100.0, array $attributes = []): Sale
    {
        return Sale::create(array_merge([
            'invoice_number' => 'INV-' . Str::upper(Str::random(10)),
            'user_id' => User::factory()->create()->id,
            'branch_id' => $branch->id,
            'total_amount' => $total,
            'grand_total' => $total,
            'payment_method' => 'cash',
            'paid_amount' => $total,
            'status' => Sale::STATUS_COMPLETED,
        ], $attributes));
    }
}

if (!function_exists('expenseFor')) {
    /** One expense, with the NOT NULL columns filled in. */
    function expenseFor(User $user, float $amount = 100.0, array $attributes = []): Expense
    {
        return Expense::create(array_merge([
            'name' => 'Power',
            'amount' => $amount,
            'expense_date' => today(),
            'user_id' => $user->id,
        ], $attributes));
    }
}
