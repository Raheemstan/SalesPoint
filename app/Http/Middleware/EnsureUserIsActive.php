<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turn away a user who has been deactivated.
 *
 * Deactivating is the replacement for deleting: the account keeps its sales
 * history but must not be able to sign in. Blocking it at login is only half
 * the job - someone who was already signed in when an admin deactivated them
 * would otherwise carry on working until their session happened to expire,
 * which is exactly the window that matters when the reason for deactivating
 * them was that they were caught taking money.
 *
 * Registered on the whole `web` group in bootstrap/app.php, so there is no
 * route anyone can reach by being deactivated on the way to it.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->isActive()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'Your account has been deactivated. Please speak to an administrator.';

            // An XHR mid-session (the POS saves over fetch) gets a 401 it can
            // act on rather than a login page rendered into a JSON parser.
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 401);
            }

            return redirect()->route('login')->with('status', $message);
        }

        return $next($request);
    }
}
