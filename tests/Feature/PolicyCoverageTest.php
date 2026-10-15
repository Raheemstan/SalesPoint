<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Policies that are actually asked
|--------------------------------------------------------------------------
|
| CategoryPolicy and ExpensePolicy were untouched `make:policy` stubs: seven
| methods each, all `return false`. Nothing was broken by that, because neither
| controller consulted a policy at all - the only thing standing between a
| cashier and the expense book was `role:admin|manager` on the route group.
|
| A deny-everything policy that nobody calls is worse than no policy: the next
| person to add a Gate::authorize gets a 403 for every role including their own,
| and the quickest-looking fix is to loosen the route instead of the policy.
|
| So this file asserts both halves, because each is useless alone:
|
|   1. the rule - manager and admin may manage, a cashier may not;
|   2. that the controller really asks - a deny-all policy swapped in at
|      runtime must turn a manager's request into a 403. If someone deletes a
|      Gate::authorize line, part 1 still passes and this part fails.
|
*/

/**
 * Stands in for a real policy to prove the controller consults one.
 *
 * Registered with Gate::policy(), which takes precedence over Laravel's
 * model/policy auto-discovery.
 */
class DenyEverythingPolicy
{
    public function viewAny($user): bool
    {
        return false;
    }

    public function view($user, $model): bool
    {
        return false;
    }

    public function create($user): bool
    {
        return false;
    }

    public function update($user, $model): bool
    {
        return false;
    }

    public function delete($user, $model): bool
    {
        return false;
    }
}

/** The abilities both of these policies share. */
function manageAbilities(): array
{
    return ['viewAny', 'view', 'create', 'update', 'delete', 'restore'];
}

/** One expense, with the NOT NULL columns filled in. */
function anExpense(): Expense
{
    return Expense::create([
        'name' => 'Diesel',
        'amount' => 5000,
        'expense_date' => today(),
        'user_id' => User::factory()->manager()->create()->id,
    ]);
}

/*
|--------------------------------------------------------------------------
| The rule
|--------------------------------------------------------------------------
*/

test('a manager and an admin may manage categories; a cashier may not', function () {
    $category = Category::factory()->create();

    foreach (['manager', 'admin'] as $role) {
        $user = User::factory()->state(['role' => $role])->create();

        foreach (manageAbilities() as $ability) {
            expect(Gate::forUser($user)->allows($ability, [Category::class, $category]))
                ->toBeTrue("a {$role} should be allowed to {$ability} a category");
        }
    }

    $cashier = User::factory()->cashier()->create();

    foreach (manageAbilities() as $ability) {
        expect(Gate::forUser($cashier)->allows($ability, [Category::class, $category]))
            ->toBeFalse("a cashier should not be allowed to {$ability} a category");
    }
});

test('a manager and an admin may manage expenses; a cashier may not', function () {
    $expense = anExpense();

    foreach (['manager', 'admin'] as $role) {
        $user = User::factory()->state(['role' => $role])->create();

        foreach (manageAbilities() as $ability) {
            expect(Gate::forUser($user)->allows($ability, [Expense::class, $expense]))
                ->toBeTrue("a {$role} should be allowed to {$ability} an expense");
        }
    }

    $cashier = User::factory()->cashier()->create();

    foreach (manageAbilities() as $ability) {
        expect(Gate::forUser($cashier)->allows($ability, [Expense::class, $expense]))
            ->toBeFalse("a cashier should not be allowed to {$ability} an expense");
    }
});

test('nobody may force-delete a category or an expense', function () {
    /*
    | Both are referenced by history - products and inventory logs by category,
    | the profit-and-loss figure for a closed period by expense - so erasing the
    | row outright is never the right answer, admin included.
    */
    $admin = User::factory()->admin()->create();

    expect(Gate::forUser($admin)->allows('forceDelete', Category::factory()->create()))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('forceDelete', anExpense()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| That the controllers ask
|--------------------------------------------------------------------------
*/

test('CategoryController refuses a manager when the policy denies', function () {
    Gate::policy(Category::class, DenyEverythingPolicy::class);

    $category = Category::factory()->create();
    $this->actingAs(User::factory()->manager()->create());

    $this->get(route('categories.index'))->assertForbidden();
    $this->post(route('categories.store'), ['name' => 'Drinks'])->assertForbidden();
    $this->put(route('categories.update', $category), ['name' => 'Snacks'])->assertForbidden();
    $this->delete(route('categories.destroy', $category))->assertForbidden();

    expect(Category::where('name', 'Drinks')->exists())->toBeFalse()
        ->and($category->fresh()->name)->not->toBe('Snacks');
});

test('ExpenseController refuses a manager when the policy denies', function () {
    Gate::policy(Expense::class, DenyEverythingPolicy::class);

    $expense = anExpense();
    $this->actingAs(User::factory()->manager()->create());

    $this->get(route('expenses.index'))->assertForbidden();
    $this->post(route('expenses.store'), ['name' => 'Rent', 'amount' => 100, 'expense_date' => '2026-10-01'])
        ->assertForbidden();
    $this->put(route('expenses.update', $expense), ['name' => 'Edited', 'amount' => 1, 'expense_date' => '2026-10-01'])
        ->assertForbidden();
    $this->delete(route('expenses.destroy', $expense))->assertForbidden();

    expect(Expense::where('name', 'Rent')->exists())->toBeFalse()
        ->and($expense->fresh()->name)->toBe('Diesel');
});

test('a manager still gets through both screens with the real policies', function () {
    /*
    | The other half of the deny tests: proves the 403s above come from the
    | policy rather than from the Gate::authorize call being unconditionally
    | wrong (a mistyped ability name would 403 everybody, forever).
    */
    $category = Category::factory()->create();
    $expense = anExpense();

    $this->actingAs(User::factory()->manager()->create());

    $this->get(route('categories.index'))->assertOk();
    $this->post(route('categories.store'), ['name' => 'Drinks'])->assertSessionHas('success');
    $this->put(route('categories.update', $category), ['name' => 'Snacks'])->assertSessionHas('success');

    $this->get(route('expenses.index'))->assertOk();
    $this->post(route('expenses.store'), ['name' => 'Rent', 'amount' => 100, 'expense_date' => '2026-10-01'])
        ->assertSessionHas('success');
    $this->delete(route('expenses.destroy', $expense))->assertSessionHas('success');
});
