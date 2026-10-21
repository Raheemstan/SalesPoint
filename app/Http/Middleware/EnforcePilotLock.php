<?php

namespace App\Http\Middleware;

use App\Services\PilotStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Blocks every request once a pilot has expired or its dates have been tampered
 * with. Deliberately placed BEFORE session/auth in the middleware stack (see
 * bootstrap/app.php) so a locked pilot blocks the login page too, not just the
 * screens behind it.
 *
 * This never deletes or exports anything - the only action it ever takes is to
 * stop a response from going out and show a static page instead. All data stays
 * exactly where it was.
 */
class EnforcePilotLock
{
    public function handle(Request $request, Closure $next)
    {
        $status = new PilotStatus();

        if (!$status->enabled) {
            // See the note below on why this is unconditional even when there is
            // nothing to share: it overwrites any stale value from an earlier
            // request in the same long-running process.
            view()->share('pilotWarning', null);

            return $next($request);
        }

        if ($status->locked) {
            Log::warning('Pilot lock in effect: '.$status->reason());

            return $this->lockedResponse($request, $status);
        }

        // Always (re)share, including as null when there is nothing to warn about.
        // The View Factory's shared data is a container-lifetime singleton, not
        // reset per request by default - sharing unconditionally here means a
        // request made *after* the warning window has passed can never keep
        // showing a stale banner left over from an earlier request in the same
        // process (relevant to Octane/queue workers, and to the test suite).
        view()->share('pilotWarning', $status->warning ? $status : null);

        return $next($request);
    }

    private function lockedResponse(Request $request, PilotStatus $status)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'This pilot installation is no longer active. Please contact the vendor to continue.',
            ], 402); // 402 Payment Required - the closest honest HTTP status for this
        }

        return response()->view('pilot.locked', [
            'phone' => config('app.vendor_phone', '09071140264'),
            'email' => config('app.vendor_email'),
        ], 402);
    }
}
