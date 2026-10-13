<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

test('guests are sent to the login page', function () {
    foreach (['pos.index', 'dashboard', 'products.index', 'reports.sales', 'settings.index'] as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }
});

test('cashiers can use the POS and dashboard but nothing else', function () {
    $this->actingAs(User::factory()->cashier()->create());

    $this->get(route('pos.index'))->assertOk();
    $this->get(route('dashboard'))->assertOk();

    // regression: these used to be wide open because ->middleware() was chained after ->group()
    foreach ([
        'products.index', 'categories.index', 'purchases.index', 'purchases.create',
        'expenses.index', 'reports.sales', 'reports.daily', 'reports.movement',
        'reports.profit_loss', 'settings.index',
    ] as $route) {
        $this->get(route($route))->assertForbidden();
    }

    $this->post(route('settings.update'), ['store_name' => 'Hacked'])->assertForbidden();
    $this->post(route('products.store'), [])->assertForbidden();
    $this->post(route('settings.users.create'), [])->assertForbidden();
    $this->get(route('settings.backup.export', 'users'))->assertForbidden();
});

test('managers get reports, stock and settings but not user management or raw exports', function () {
    $this->actingAs(User::factory()->manager()->create());

    foreach (['products.index', 'categories.index', 'purchases.index', 'expenses.index',
              'reports.sales', 'reports.movement', 'reports.profit_loss', 'settings.index'] as $route) {
        $this->get(route($route))->assertOk();
    }

    $this->post(route('settings.users.create'), [])->assertForbidden();
    $this->post(route('settings.backup.download'))->assertForbidden();
    $this->get(route('settings.backup.export', 'users'))->assertForbidden();
});

test('admins can reach everything', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('settings.index'))->assertOk()->assertSee('Add User');
    $this->get(route('settings.backup.export', 'products'))->assertOk();
});

test('the role column and the Spatie role can never disagree', function () {
    $user = User::factory()->manager()->create();
    expect($user->hasRole('manager'))->toBeTrue()->and($user->hasRole('cashier'))->toBeFalse();

    $user->role = 'admin';
    $user->save();
    expect($user->fresh()->hasRole('admin'))->toBeTrue()->and($user->fresh()->hasRole('manager'))->toBeFalse();

    // default role is cashier
    expect(User::factory()->create()->hasRole('cashier'))->toBeTrue();
});

test('the seeded admin can actually reach admin pages', function () {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);

    $admin = User::where('email', 'test@example.com')->first();
    expect($admin->hasRole('admin'))->toBeTrue();

    $this->actingAs($admin)->get(route('reports.sales'))->assertOk();
});

test('the role seeder can be run twice', function () {
    $this->seed(\Database\Seeders\RoleSeeder::class);
    $this->seed(\Database\Seeders\RoleSeeder::class);

    expect(Role::count())->toBe(3);
});

test('the Spatie role middleware aliases are registered', function () {
    $aliases = app('router')->getMiddleware();
    expect($aliases)->toHaveKeys(['role', 'permission', 'role_or_permission']);
});
