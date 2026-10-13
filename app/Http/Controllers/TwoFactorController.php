<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Optional time-based two-factor authentication.
 *
 * Shape of the feature:
 *
 *  - Setting it up is a two-step dance. `enable` generates a secret and shows
 *    it; `confirm` takes a code computed from that secret and only then is 2FA
 *    switched on. Skipping the confirmation is how people lock themselves out
 *    of their own shop with a mistyped secret.
 *  - The challenge is a *half-authenticated* step: the password was right, so
 *    the user id is held in the session, but nobody is signed in until a code
 *    checks out. That is why these two routes sit outside the `auth` group.
 *  - Nothing here ever puts the secret or a recovery code in a response that
 *    was not the one-time "write these down" screen.
 *
 * The TOTP itself is in App\Services\TotpService.
 */
class TwoFactorController extends Controller
{
    /** Session keys for the half-authenticated state between password and code. */
    private const PENDING_ID = 'two_factor.pending_id';
    private const PENDING_REMEMBER = 'two_factor.remember';

    public function __construct(private TotpService $totp)
    {
    }

    // ------------------------------------------------------- the user's own 2FA

    public function show(Request $request)
    {
        $user = $request->user();

        return view('profile.two-factor', [
            'user' => $user,
            'enabled' => $user->hasTwoFactorEnabled(),
            // Set only while a setup is half-finished: the secret is held in the
            // session, not the response, until it has been confirmed.
            'pendingSecret' => $request->session()->get('two_factor.setup_secret'),
            'pendingUri' => $request->session()->get('two_factor.setup_uri'),
            'recoveryCodes' => $request->session()->get('two_factor.recovery_codes'),
            'required' => $this->isRequiredFor($user),
        ]);
    }

    /** Step 1: generate a secret and show it, without switching anything on. */
    public function enable(Request $request)
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return back()->with('error', 'Two-factor authentication is already on for your account.');
        }

        $secret = $this->totp->generateSecret();

        $user->two_factor_secret = $secret;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        /*
         * The secret goes in the session, not in the page, and the page reads it
         * back from there. It means a refresh or a back button does not re-roll
         * the secret the user has already scanned.
         */
        $request->session()->put('two_factor.setup_secret', $secret);
        $request->session()->put('two_factor.setup_uri', $this->totp->otpauthUri(
            setting('store_name') ?: config('app.name'),
            $user->email,
            $secret
        ));

        return redirect()->route('two-factor.show')
            ->with('success', 'Scan the code with your authenticator app, then enter a code below to finish.');
    }

    /** Step 2: a correct code proves the app and the server agree. */
    public function confirm(Request $request)
    {
        $user = $request->user();

        $request->validate(['code' => 'required|string|max:12']);

        if (!$user->two_factor_secret) {
            return back()->with('error', 'Start again: there is no two-factor setup in progress.');
        }

        if (!$this->totp->verify($user->two_factor_secret, $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => 'That code is not right. Check your phone\'s clock and try the current code.',
            ]);
        }

        $codes = $this->totp->generateRecoveryCodes();

        DB::transaction(function () use ($user, $codes) {
            $user->two_factor_recovery_codes = $codes;
            $user->two_factor_confirmed_at = now();
            $user->save();

            AuditLog::record('Enabled Two-Factor Authentication', 'users', $user->id);
        });

        $request->session()->forget(['two_factor.setup_secret', 'two_factor.setup_uri']);

        // Flashed, so they survive exactly one page view: this is the only time
        // the codes are ever shown.
        return redirect()->route('two-factor.show')
            ->with('two_factor.recovery_codes', $codes)
            ->with('success', 'Two-factor authentication is on. Save these recovery codes somewhere safe.');
    }

    /** Replace the recovery codes (after using one, or losing the list). */
    public function recoveryCodes(Request $request)
    {
        $user = $request->user();

        if (!$user->hasTwoFactorEnabled()) {
            return back()->with('error', 'Two-factor authentication is not on for your account.');
        }

        $codes = $this->totp->generateRecoveryCodes();

        $user->two_factor_recovery_codes = $codes;
        $user->save();

        AuditLog::record('Regenerated Two-Factor Recovery Codes', 'users', $user->id);

        return redirect()->route('two-factor.show')
            ->with('two_factor.recovery_codes', $codes)
            ->with('success', 'New recovery codes. The old ones no longer work.');
    }

    /**
     * Switch 2FA off. Needs the current password: an unlocked browser left on
     * the counter should not be enough to remove someone's second factor.
     */
    public function disable(Request $request)
    {
        $user = $request->user();

        $request->validate(['password' => 'required|string']);

        if (!Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => 'That is not your current password.']);
        }

        if ($this->isRequiredFor($user)) {
            return back()->with('error', 'Two-factor authentication is required for administrators in this shop\'s settings.');
        }

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->save();

        AuditLog::record('Disabled Two-Factor Authentication', 'users', $user->id);

        return redirect()->route('two-factor.show')->with('success', 'Two-factor authentication is off.');
    }

    // ------------------------------------------------- the challenge after login

    public function challenge(Request $request)
    {
        if (!$this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function verify(Request $request)
    {
        $user = $this->pendingUser($request);

        if (!$user) {
            return redirect()->route('login')->with('status', 'Please sign in again.');
        }

        $request->validate([
            'code' => 'nullable|string|max:12',
            'recovery_code' => 'nullable|string|max:32',
        ]);

        $this->ensureNotRateLimited($user);

        $code = (string) $request->input('code', '');
        $recovery = (string) $request->input('recovery_code', '');

        if ($code === '' && $recovery === '') {
            throw ValidationException::withMessages(['code' => 'Enter the code from your authenticator app, or a recovery code.']);
        }

        $usedRecovery = false;
        $passed = $code !== '' && $this->totp->verify((string) $user->two_factor_secret, $code);

        // A recovery code is only looked at when there was no working app code,
        // so a user who types both does not burn a recovery code needlessly.
        if (!$passed && $recovery !== '') {
            $passed = $usedRecovery = $this->spendRecoveryCode($user, $recovery);
        }

        if (!$passed) {
            RateLimiter::hit($this->throttleKey($user), 300);

            throw ValidationException::withMessages([
                'code' => 'That code is not right. Codes last 30 seconds, so make sure you are using the current one.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($user));

        $remember = (bool) $request->session()->pull(self::PENDING_REMEMBER, false);
        $request->session()->forget(self::PENDING_ID);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        AuditLog::record($usedRecovery ? 'Signed In With Recovery Code' : 'Signed In With Two-Factor Code', 'users', $user->id);

        return redirect()->intended(route('dashboard', absolute: false))
            ->with($usedRecovery ? 'success' : 'status', $usedRecovery
                ? 'You signed in with a recovery code. It has been used up - generate a new set below.'
                : 'Signed in.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Park a half-authenticated login and send the user to the challenge.
     *
     * Called from the login controller, which is the only place that knows the
     * password was right.
     */
    public static function beginChallenge(Request $request, User $user, bool $remember = false): void
    {
        // A brand new session id, so a session fixed before the password was
        // typed cannot be upgraded by passing the challenge in it.
        $request->session()->regenerate();
        $request->session()->put(self::PENDING_ID, $user->id);
        $request->session()->put(self::PENDING_REMEMBER, $remember);
    }

    /** Is 2FA compulsory for this user? (Settings > Security, admins only.) */
    public function isRequiredFor(User $user): bool
    {
        return setting('require_2fa_admins', '0') === '1' && $user->hasRole('admin');
    }

    /** The user who got their password right but has not passed the challenge. */
    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(self::PENDING_ID);

        if (!$id) {
            return null;
        }

        $user = User::find($id);

        // A user deactivated between password and code does not get in, and a
        // user whose 2FA was turned off in the meantime has nothing to answer.
        if (!$user || !$user->isActive() || !$user->hasTwoFactorEnabled()) {
            $request->session()->forget([self::PENDING_ID, self::PENDING_REMEMBER]);

            return null;
        }

        return $user;
    }

    /**
     * Spend a recovery code, if it is one of theirs.
     *
     * Single use: the code is removed from the stored list, so replaying it
     * finds nothing to match.
     */
    private function spendRecoveryCode(User $user, string $candidate): bool
    {
        $remaining = $this->totp->consumeRecoveryCode($user->two_factor_recovery_codes ?? [], $candidate);

        if ($remaining === null) {
            return false;
        }

        $user->two_factor_recovery_codes = $remaining;
        $user->save();

        return true;
    }

    /**
     * Five wrong codes and the challenge closes for five minutes.
     *
     * Without this a six-digit code is a million guesses, which a script gets
     * through long before the code expires - rate limiting is what makes a
     * short code safe. Keyed on the account, not just the IP: the attacker
     * chooses their IP, they do not choose whose account they are attacking.
     */
    private function ensureNotRateLimited(User $user): void
    {
        if (!RateLimiter::tooManyAttempts($this->throttleKey($user), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($user));

        throw ValidationException::withMessages([
            'code' => 'Too many attempts. Try again in ' . ceil($seconds / 60) . ' minute(s).',
        ]);
    }

    private function throttleKey(User $user): string
    {
        return 'two-factor:' . $user->id . '|' . request()->ip();
    }
}
