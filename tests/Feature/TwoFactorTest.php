<?php

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Support\Facades\DB;

/*
 * Two-factor authentication end to end.
 *
 * The things that must hold, in order of how badly they would hurt:
 *
 *  - A correct password for a 2FA account must not be a session. If it is, the
 *    second factor is decoration.
 *  - Setting up must not be able to lock you out: 2FA only switches on after a
 *    code computed from the new secret has been typed back.
 *  - A six-digit code is a million guesses, so the challenge is rate limited.
 *  - A recovery code works once.
 *  - No secret and no recovery code ever appears in a response that was not the
 *    one-time "write these down" screen.
 */
beforeEach(function () {
    $this->totp = app(TotpService::class);
});

/** A user with two-factor already switched on, and their secret. */
function withTwoFactor(array $attributes = []): array
{
    $totp = app(TotpService::class);
    $secret = $totp->generateSecret();

    $user = User::factory()->create($attributes);
    $user->two_factor_secret = $secret;
    $user->two_factor_recovery_codes = $totp->generateRecoveryCodes();
    $user->two_factor_confirmed_at = now();
    $user->save();

    return [$user->fresh(), $secret];
}

// -------------------------------------------------------------------- setup

test('the setup screen offers to start, and starting shows a secret without switching 2FA on', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('two-factor.show'))->assertOk()->assertSee('Start setup');

    $this->post(route('two-factor.enable'))->assertRedirect(route('two-factor.show'));

    $user->refresh();

    expect($user->two_factor_secret)->not->toBeNull()
        // not confirmed, so not on: a mistyped secret cannot lock anyone out
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->hasTwoFactorEnabled())->toBeFalse();

    // The key is offered for manual entry, with the otpauth URI behind it.
    $this->get(route('two-factor.show'))->assertOk()
        ->assertSee('enter a setup key', escape: false)
        ->assertSee('otpauth://totp/', escape: false);
});

test('a correct code finishes setup and hands over recovery codes once', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post(route('two-factor.enable'));
    $secret = session('two_factor.setup_secret');

    $this->post(route('two-factor.confirm'), ['code' => $this->totp->codeAt($secret)])
        ->assertRedirect(route('two-factor.show'))
        ->assertSessionHas('success');

    $user->refresh();

    expect($user->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->two_factor_recovery_codes)->toHaveCount(8);

    expect(AuditLog::where('action', 'Enabled Two-Factor Authentication')->where('record_id', $user->id)->exists())
        ->toBeTrue();

    // Shown this once...
    $firstCode = $user->two_factor_recovery_codes[0];
    $this->get(route('two-factor.show'))->assertOk()->assertSee($firstCode);

    // ...and never again.
    $this->get(route('two-factor.show'))->assertOk()->assertDontSee($firstCode);
});

test('a wrong code during setup leaves 2FA off', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post(route('two-factor.enable'));

    $this->post(route('two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

test('the setup page never renders the stored secret of an enabled account', function () {
    [$user, $secret] = withTwoFactor();
    $this->actingAs($user);

    $this->get(route('two-factor.show'))->assertOk()
        ->assertDontSee($secret)
        ->assertDontSee($user->two_factor_recovery_codes[0]);
});

// -------------------------------------------------------------------- login

test('a correct password for a 2FA account lands on the challenge, not a session', function () {
    [$user, $secret] = withTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.challenge'));

    // The password was right and the user is still not signed in.
    $this->assertGuest();

    $this->get(route('two-factor.challenge'))->assertOk()->assertSee('One more step');
});

test('a correct code completes the login and stamps last_login_at', function () {
    [$user, $secret] = withTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->post(route('two-factor.verify'), ['code' => $this->totp->codeAt($secret)])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user->fresh());
    expect($user->fresh()->last_login_at)->not->toBeNull();

    expect(AuditLog::where('action', 'Signed In With Two-Factor Code')->exists())->toBeTrue();
});

test('a wrong code is rejected and the user stays signed out', function () {
    [$user, $secret] = withTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');

    $this->assertGuest();
});

test('an empty challenge form is rejected', function () {
    [$user] = withTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post(route('two-factor.verify'), [])->assertSessionHasErrors('code');

    $this->assertGuest();
});

test('the challenge is rate limited after five wrong codes', function () {
    [$user, $secret] = withTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    for ($i = 0; $i < 5; $i++) {
        $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
    }

    // The sixth attempt is refused before the code is even looked at - so even
    // the *correct* code is turned away while the lockout lasts.
    $response = $this->post(route('two-factor.verify'), ['code' => $this->totp->codeAt($secret)]);

    $response->assertSessionHasErrors('code');
    expect(session('errors')->first('code'))->toContain('Too many attempts');

    $this->assertGuest();
});

test('reaching the challenge without passing a password sends you to login', function () {
    $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    $this->post(route('two-factor.verify'), ['code' => '123456'])->assertRedirect(route('login'));
});

test('a user deactivated between password and code does not get in', function () {
    [$user, $secret] = withTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.challenge'));

    $user->forceFill(['is_active' => false])->save();

    $this->post(route('two-factor.verify'), ['code' => $this->totp->codeAt($secret)])
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a user without 2FA signs in as before', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user->fresh());
});

// ----------------------------------------------------------- recovery codes

test('a recovery code signs you in and cannot be replayed', function () {
    [$user, $secret] = withTwoFactor();
    $code = $user->two_factor_recovery_codes[0];

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->post(route('two-factor.verify'), ['recovery_code' => $code])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user->fresh());

    expect($user->fresh()->two_factor_recovery_codes)->toHaveCount(7)
        ->not->toContain($code);

    expect(AuditLog::where('action', 'Signed In With Recovery Code')->exists())->toBeTrue();

    // The same code a second time: the login must be refused.
    $this->post(route('logout'));
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->post(route('two-factor.verify'), ['recovery_code' => $code])->assertSessionHasErrors('code');

    $this->assertGuest();
});

test('recovery codes can be regenerated, which retires the old set', function () {
    [$user] = withTwoFactor();
    $old = $user->two_factor_recovery_codes;

    $this->actingAs($user);

    $this->post(route('two-factor.recovery-codes'))->assertSessionHas('success');

    $new = $user->fresh()->two_factor_recovery_codes;

    expect($new)->toHaveCount(8)
        ->and(array_intersect($old, $new))->toBeEmpty();

    expect(AuditLog::where('action', 'Regenerated Two-Factor Recovery Codes')->exists())->toBeTrue();
});

// -------------------------------------------------------------- disabling

test('disabling needs the current password', function () {
    [$user] = withTwoFactor();
    $this->actingAs($user);

    $this->delete(route('two-factor.disable'), ['password' => 'not-my-password'])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->delete(route('two-factor.disable'), ['password' => 'password'])
        ->assertSessionHas('success');

    $user->refresh();

    expect($user->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull();

    expect(AuditLog::where('action', 'Disabled Two-Factor Authentication')->exists())->toBeTrue();
});

test('an admin cannot disable 2FA while the shop requires it', function () {
    Setting::set('require_2fa_admins', '1');

    [$admin] = withTwoFactor(['role' => 'admin']);
    $this->actingAs($admin);

    $this->delete(route('two-factor.disable'), ['password' => 'password'])->assertSessionHas('error');

    expect($admin->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

// --------------------------------------------------------------- enforcement

test('when the shop requires it, an admin without 2FA is taken to the setup screen', function () {
    Setting::set('require_2fa_admins', '1');

    $admin = User::factory()->admin()->create();

    $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.show'));

    // They are signed in - locking them out of the screen that lets them comply
    // would leave nobody able to fix it.
    $this->assertAuthenticatedAs($admin->fresh());
});

test('requiring it for admins does not affect managers or cashiers', function () {
    Setting::set('require_2fa_admins', '1');

    $cashier = User::factory()->cashier()->create();

    $this->post('/login', ['email' => $cashier->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
});

test('the requirement is a setting, validated and off by default', function () {
    expect(setting('require_2fa_admins', '0'))->toBe('0');

    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('settings.update'), ['require_2fa_admins' => '1'])->assertSessionHasNoErrors();
    expect(setting('require_2fa_admins'))->toBe('1');

    $this->post(route('settings.update'), ['store_name' => 'Shop']); // box absent = unticked
    expect(setting('require_2fa_admins'))->toBe('0');
});

// -------------------------------------------------------------------- storage

test('the secret and recovery codes are encrypted at rest', function () {
    [$user, $secret] = withTwoFactor();

    $raw = DB::table('users')->where('id', $user->id)->first();

    expect($raw->two_factor_secret)->not->toBe($secret)
        ->and($raw->two_factor_secret)->not->toContain($secret)
        ->and($raw->two_factor_recovery_codes)->not->toContain($user->two_factor_recovery_codes[0])
        // but the model reads them back
        ->and($user->fresh()->two_factor_secret)->toBe($secret);
});

test('the secret is hidden from serialisation', function () {
    [$user] = withTwoFactor();

    $json = $user->toJson();

    expect($json)->not->toContain('two_factor_secret')
        ->not->toContain('two_factor_recovery_codes')
        ->not->toContain('password');
});

test('the users CSV export carries no two-factor columns', function () {
    [$user, $secret] = withTwoFactor(['role' => 'admin']);
    $this->actingAs($user);

    $csv = $this->get(route('settings.backup.export', 'users'))->assertOk()->streamedContent();

    expect($csv)->not->toContain('two_factor')->not->toContain($secret);
});
