<?php

namespace App\Http\Middleware;

use App\Services\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establish which branch this request is acting as.
 *
 * One job, and it is deliberately tiny: hand the signed-in user and their
 * session to App\Services\BranchContext and let it work the answer out lazily.
 * Everything that follows - the global scope filtering reads, new rows being
 * stamped, stock movements landing on the right shelf - reads that one answer.
 *
 * WHY MIDDLEWARE AND NOT A SERVICE PROVIDER
 *
 * Because the answer depends on who is asking and what they last chose, and
 * neither of those exists when providers boot. It is appended to the `web`
 * group, so it runs after StartSession and after authentication, which is the
 * only point at which both are available.
 *
 * It does no database work. A single-site shop therefore pays nothing for this
 * feature on a request that does not touch branch-aware data, and nothing here
 * can fail on an install where the migration has not run yet.
 */
class SetActiveBranch
{
    public function __construct(private BranchContext $branches)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * Guests included, with a null user. The login screen and the two-factor
         * challenge run through here, and leaving the context from a previous
         * request in place would be worse than leaving it empty.
         *
         * `hasSession()` because console-driven and stateless requests have
         * none, and the resolver has to work without one anyway.
         */
        $this->branches->establish(
            $request->user(),
            $request->hasSession() ? $request->session() : null
        );

        return $next($request);
    }
}
