<?php

use App\Models\AuditLog;
use App\Models\Sale;
use App\Models\User;

/*
 * User management, and in particular the ways it must refuse.
 *
 * Deleting a user used to be the only way to stop them signing in, which took
 * their sales history with them. The replacement is deactivation, so most of
 * what is tested here is the set of guard rails that keep a shop from locking
 * itself out: your own admin role, your own account, and the last admin.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $this->actingAs($this->admin);
});

// --------------------------------------------------------------------- editing

test('an admin can edit a user name, email and role', function () {
    $user = User::factory()->cashier()->create();

    $this->put(route('settings.users.update', $user), [
        'name' => 'Bola Cashier',
        'email' => 'bola@example.com',
        'role' => 'manager',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $user->refresh();

    expect($user->name)->toBe('Bola Cashier')
        ->and($user->email)->toBe('bola@example.com')
        ->and($user->role)->toBe('manager')
        // the model keeps users.role and the Spatie role in step
        ->and($user->hasRole('manager'))->toBeTrue()
        ->and($user->hasRole('cashier'))->toBeFalse();

    expect(AuditLog::where('action', 'Updated User')->where('record_id', $user->id)->exists())->toBeTrue();
});

test('a user can be saved without changing their email (unique except self)', function () {
    $user = User::factory()->cashier()->create(['email' => 'same@example.com']);

    $this->put(route('settings.users.update', $user), [
        'name' => 'Renamed',
        'email' => 'same@example.com',
        'role' => 'cashier',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('Renamed');
});

test('editing rejects a duplicate email, a missing name and an unknown role', function () {
    $other = User::factory()->cashier()->create(['email' => 'taken@example.com']);
    $user = User::factory()->cashier()->create();

    $base = ['name' => 'X', 'email' => $user->email, 'role' => 'cashier'];

    $this->put(route('settings.users.update', $user), ['email' => 'taken@example.com'] + $base)
        ->assertSessionHasErrors('email');

    $this->put(route('settings.users.update', $user), ['name' => ''] + $base)
        ->assertSessionHasErrors('name');

    $this->put(route('settings.users.update', $user), ['role' => 'owner'] + $base)
        ->assertSessionHasErrors('role');
});

test('an admin cannot remove their own admin role', function () {
    // Another admin exists, so this is not the "last admin" rule being hit.
    User::factory()->admin()->create();

    $this->put(route('settings.users.update', $this->admin), [
        'name' => $this->admin->name,
        'email' => $this->admin->email,
        'role' => 'manager',
    ])->assertSessionHas('error');

    expect($this->admin->fresh()->role)->toBe('admin')
        ->and($this->admin->fresh()->hasRole('admin'))->toBeTrue();
});

test('the last admin cannot be demoted, even by themselves', function () {
    expect(User::where('role', 'admin')->count())->toBe(1);

    $this->put(route('settings.users.update', $this->admin), [
        'name' => $this->admin->name,
        'email' => $this->admin->email,
        'role' => 'cashier',
    ])->assertSessionHas('error');

    expect($this->admin->fresh()->role)->toBe('admin');
});

test('a second admin can demote the other one', function () {
    $other = User::factory()->admin()->create();

    $this->put(route('settings.users.update', $other), [
        'name' => $other->name,
        'email' => $other->email,
        'role' => 'manager',
    ])->assertSessionHas('success');

    expect($other->fresh()->role)->toBe('manager');
});

test('an admin can still change their own name and email', function () {
    $this->put(route('settings.users.update', $this->admin), [
        'name' => 'Ada Renamed',
        'email' => 'ada2@example.com',
        'role' => 'admin',
    ])->assertSessionHas('success');

    expect($this->admin->fresh()->name)->toBe('Ada Renamed');
});

// -------------------------------------------------------------- password reset

test('an admin can set a new password and the user can sign in with it', function () {
    $user = User::factory()->cashier()->create();

    $this->post(route('settings.users.password', $user), [
        'password' => 'a-Strong-pass-123',
        'password_confirmation' => 'a-Strong-pass-123',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    // Sign out the admin, then sign in as the user with the new password.
    $this->post(route('logout'));

    $this->post('/login', ['email' => $user->email, 'password' => 'a-Strong-pass-123'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user->fresh());
});

test('a reset password is held to the same strength rules as a chosen one', function () {
    $user = User::factory()->cashier()->create();

    $this->post(route('settings.users.password', $user), [
        'password' => '123',
        'password_confirmation' => '123',
    ])->assertSessionHasErrors('password');

    $this->post(route('settings.users.password', $user), [
        'password' => 'a-Strong-pass-123',
        'password_confirmation' => 'does-not-match',
    ])->assertSessionHasErrors('password');
});

test('a password reset is recorded without the password itself', function () {
    $user = User::factory()->cashier()->create();

    $this->post(route('settings.users.password', $user), [
        'password' => 'a-Strong-pass-123',
        'password_confirmation' => 'a-Strong-pass-123',
    ]);

    $log = AuditLog::where('action', 'Reset User Password')->where('record_id', $user->id)->first();

    expect($log)->not->toBeNull();

    // Nothing in the row may carry the password or its hash.
    $row = implode('|', array_map('strval', $log->getAttributes()));
    expect($row)->not->toContain('a-Strong-pass-123')
        ->and($row)->not->toContain($user->fresh()->password);
});

test('a reset invalidates the remember-me token, so an old cookie cannot be replayed', function () {
    $user = User::factory()->cashier()->create(['remember_token' => 'old-token-value']);

    $this->post(route('settings.users.password', $user), [
        'password' => 'a-Strong-pass-123',
        'password_confirmation' => 'a-Strong-pass-123',
    ]);

    expect($user->fresh()->remember_token)->not->toBe('old-token-value');
});

// ------------------------------------------------------------ activate/deactivate

test('deactivating keeps the sales history and only stops the login', function () {
    $user = User::factory()->cashier()->create();

    // Built by hand: the shared SaleFactory has no defaults, and this test only
    // needs a row that points at the user.
    $sale = Sale::create([
        'invoice_number' => 'INV-DEACTIVATE-1',
        'user_id' => $user->id,
        'total_amount' => 1000,
        'grand_total' => 1000,
        'payment_method' => 'cash',
        'paid_amount' => 1000,
    ]);

    $this->post(route('settings.users.status', $user))->assertSessionHas('success');

    expect($user->fresh()->is_active)->toBeFalse()
        ->and($user->fresh()->trashed())->toBeFalse()
        // the point of deactivating rather than deleting
        ->and(Sale::find($sale->id))->not->toBeNull()
        ->and(Sale::find($sale->id)->user_id)->toBe($user->id);

    expect(AuditLog::where('action', 'Deactivated User')->where('record_id', $user->id)->exists())->toBeTrue();
});

test('a deactivated user can be reactivated', function () {
    $user = User::factory()->cashier()->create(['is_active' => false]);

    $this->post(route('settings.users.status', $user))->assertSessionHas('success');

    expect($user->fresh()->is_active)->toBeTrue();
    expect(AuditLog::where('action', 'Reactivated User')->where('record_id', $user->id)->exists())->toBeTrue();
});

test('an admin cannot deactivate themselves', function () {
    User::factory()->admin()->create(); // not the last admin either

    $this->post(route('settings.users.status', $this->admin))->assertSessionHas('error');

    expect($this->admin->fresh()->is_active)->toBeTrue();
});

test('the last active admin cannot be deactivated', function () {
    // A deactivated admin is no cover: they cannot sign in to put things right.
    $sleeping = User::factory()->admin()->create(['is_active' => false]);
    $other = User::factory()->admin()->create();

    $this->actingAs($other);

    $this->post(route('settings.users.status', $this->admin))->assertSessionHas('success');
    expect($this->admin->fresh()->is_active)->toBeFalse();

    // $other is now the only admin who can sign in.
    $this->post(route('settings.users.status', $other))->assertSessionHas('error');
    expect($other->fresh()->is_active)->toBeTrue();
});

// ---------------------------------------------------------------- login blocking

test('a deactivated user cannot log in, even with the right password', function () {
    $user = User::factory()->cashier()->create(['is_active' => false]);

    $this->post(route('logout'));

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a user deactivated mid-session is turned away on their next request', function () {
    $user = User::factory()->cashier()->create();

    $this->actingAs($user);
    $this->get(route('pos.index'))->assertOk();

    // An admin deactivates them while they are working.
    $user->forceFill(['is_active' => false])->save();

    $this->get(route('pos.index'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('an XHR from a deactivated user gets a 401 rather than a login page', function () {
    $user = User::factory()->cashier()->create();
    $this->actingAs($user);

    $user->forceFill(['is_active' => false])->save();

    $this->getJson(route('pos.search', ['term' => 'x']))->assertStatus(401);
});

test('last_login_at is stamped on a successful login', function () {
    $user = User::factory()->cashier()->create();
    expect($user->last_login_at)->toBeNull();

    $this->post(route('logout'));
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

// --------------------------------------------------------------------- deleting

test('deleting still works and is still guarded', function () {
    $this->delete(route('settings.users.delete', $this->admin))->assertSessionHas('error');
    expect($this->admin->fresh()->trashed())->toBeFalse();

    $other = User::factory()->cashier()->create();
    $this->delete(route('settings.users.delete', $other))->assertSessionHas('success');
    expect($other->fresh()->trashed())->toBeTrue();
});

// ---------------------------------------------------------------- authorisation

test('a manager cannot reach any user-management route', function () {
    $manager = User::factory()->manager()->create();
    $victim = User::factory()->cashier()->create();

    $this->actingAs($manager);

    $this->post(route('settings.users.create'), [])->assertForbidden();
    $this->put(route('settings.users.update', $victim), [])->assertForbidden();
    $this->post(route('settings.users.password', $victim), [])->assertForbidden();
    $this->post(route('settings.users.status', $victim))->assertForbidden();
    $this->delete(route('settings.users.delete', $victim))->assertForbidden();

    expect($victim->fresh()->is_active)->toBeTrue();
});

test('a cashier cannot reach any user-management route', function () {
    $victim = User::factory()->cashier()->create();

    $this->actingAs(User::factory()->cashier()->create());

    $this->put(route('settings.users.update', $victim), [])->assertForbidden();
    $this->post(route('settings.users.password', $victim), [])->assertForbidden();
    $this->post(route('settings.users.status', $victim))->assertForbidden();
});

test('the users tab lists role, status and the last sign-in', function () {
    User::factory()->cashier()->create(['name' => 'Bola Cashier', 'is_active' => false]);

    $this->get(route('settings.index'))->assertOk()
        ->assertSee('Bola Cashier')
        ->assertSee('Deactivated')
        ->assertSee('Reset password')
        ->assertSee('Add User');
});
