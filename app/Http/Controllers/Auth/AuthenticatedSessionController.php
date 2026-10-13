<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TwoFactorController;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     *
     * Three things happen here that did not before:
     *
     *  - A deactivated account is turned away. Auth::attempt only checks the
     *    password, so without this a user who had been deactivated could still
     *    sign in, which would make the whole feature decorative.
     *  - A user with two-factor on is *not* signed in by a correct password.
     *    They are parked in a half-authenticated session and sent to the
     *    challenge; the session only becomes a login once a code checks out.
     *  - last_login_at is stamped, so an admin can see which accounts are
     *    actually in use before deciding which to retire.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $user = Auth::user();

        if (!$user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Thrown as a validation error so it lands back on the login form
            // in the place the user is already looking.
            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Please speak to an administrator.',
            ]);
        }

        if ($user->hasTwoFactorEnabled()) {
            // Undo the login the password earned: a correct password is one
            // factor, and one factor is not a session.
            Auth::guard('web')->logout();

            TwoFactorController::beginChallenge($request, $user, $request->boolean('remember'));

            return redirect()->route('two-factor.challenge');
        }

        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        AuditLog::record('Signed In', 'users', $user->id);

        /*
         * The shop can require 2FA for administrators. An admin who has not set
         * it up yet is let in but taken straight to the setup screen rather
         * than being locked out of the app that would let them comply.
         */
        if (app(TwoFactorController::class)->isRequiredFor($user)) {
            return redirect()->route('two-factor.show')
                ->with('error', 'This shop requires administrators to use two-factor authentication. Please set it up now.');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect(route('login', absolute: false))
            ->with('status', 'You have been logged out successfully.');
    }
}
