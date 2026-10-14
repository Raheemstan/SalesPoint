<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Which branch am I acting as?
 *
 * The single answer to that question. Everything else in the feature reads it:
 * App\Scopes\BranchScope filters queries by it, BelongsToBranch stamps new rows
 * with it, the stock mirror puts movements at it, and the header switcher
 * changes it.
 *
 * WHY THERE IS A RESOLVER AND NOT JUST A SESSION KEY
 *
 * Three reasons a bare `session('branch_id')` is not enough:
 *
 *  1. It has to be *checked*. A cashier must not reach another shop's takings by
 *     editing a cookie, and a branch can be deactivated, or someone's assignment
 *     withdrawn, while they are signed in. So the stored preference is validated
 *     against what the user is actually allowed on every request.
 *
 *  2. It has to answer outside a request. Commands, queued jobs, the seeder and
 *     the test suite all write branch-aware rows, and none of them has a session.
 *     Resolution therefore falls back through the user's home branch to the
 *     default branch, and always answers something.
 *
 *  3. Scoping and stamping are different questions. While `feature('branches')`
 *     is off the app still *stamps* rows with the default branch - that is what
 *     makes switching the feature on later work - but it must not *filter* by
 *     anything, or a single-site shop would start hiding its own data. Hence
 *     id() and scoping() being separate.
 *
 * Registered as a singleton (App\Providers\AppServiceProvider), so the answer is
 * worked out at most once per request.
 */
class BranchContext
{
    /** Where the chosen branch is remembered between requests. */
    public const SESSION_KEY = 'active_branch_id';

    private ?User $user = null;

    private ?Session $session = null;

    private ?int $branchId = null;

    private bool $resolved = false;

    /**
     * Depth of the current groupWide() region.
     *
     * A counter rather than a boolean so nesting works: a report that escapes
     * the scope and calls a service that also escapes it must not have the
     * scope switched back on halfway through by the inner one finishing.
     */
    private int $suppressed = 0;

    private ?Branch $branch = null;

    /*
    |--------------------------------------------------------------------------
    | The two questions
    |--------------------------------------------------------------------------
    */

    /** Is this shop running more than one branch? */
    public function enabled(): bool
    {
        /*
         * Not memoised. feature() already reads through a cache, and a Settings
         * save clears that cache mid-request - memoising here would mean a shop
         * switching branches on had to sign out and in again before anything
         * noticed.
         */
        return feature('branches');
    }

    /**
     * The branch to stamp new rows with. Always answers, feature or no feature.
     */
    public function id(): ?int
    {
        if (!$this->resolved) {
            $this->branchId = $this->resolve();
            $this->resolved = true;
        }

        return $this->branchId;
    }

    public function current(): ?Branch
    {
        $id = $this->id();

        if ($id === null) {
            return null;
        }

        if ($this->branch?->getKey() !== $id) {
            $this->branch = Branch::find($id);
        }

        return $this->branch;
    }

    /**
     * Should branch-aware queries be filtered right now?
     *
     * False for a single-site shop, which is what keeps the scope inert and
     * every existing query behaving exactly as it did before this feature
     * existed. Also false inside a groupWide() region - see below.
     */
    public function scoping(): bool
    {
        return $this->suppressed === 0 && $this->enabled() && $this->id() !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Establishing the context
    |--------------------------------------------------------------------------
    */

    /**
     * Called once per request by App\Http\Middleware\SetActiveBranch.
     *
     * Deliberately does no database work: it only records who is asking and
     * where their preference is kept, and leaves the answer to be worked out
     * lazily. Most requests in a single-site shop therefore pay nothing at all
     * for this feature.
     */
    public function establish(?User $user, ?Session $session): void
    {
        $this->user = $user;
        $this->session = $session;
        $this->forget();
    }

    /** Throw away the resolved answer; the next read works it out again. */
    public function forget(): void
    {
        $this->resolved = false;
        $this->branchId = null;
        $this->branch = null;
    }

    /**
     * Change the acting branch and remember it.
     *
     * Refuses a branch the user may not use. The caller (BranchController) has
     * already said no with a 403; this is the backstop that means no other
     * caller can get it wrong.
     */
    public function switchTo(Branch $branch): bool
    {
        if (!$this->allows($branch)) {
            return false;
        }

        $this->session?->put(self::SESSION_KEY, $branch->getKey());

        $this->branchId = $branch->getKey();
        $this->branch = $branch;
        $this->resolved = true;

        return true;
    }

    /** May the signed-in user act as this branch? */
    public function allows(Branch $branch): bool
    {
        return $this->user?->canUseBranch($branch) ?? false;
    }

    /**
     * The branches the signed-in user may switch between, in switcher order.
     *
     * @return Collection<int, Branch>
     */
    public function available(): Collection
    {
        return $this->user?->availableBranches() ?? collect();
    }

    /*
    |--------------------------------------------------------------------------
    | Escaping the scope
    |--------------------------------------------------------------------------
    |
    | Two ways out, and no third. Both are named so that
    |
    |     grep -rn 'withoutBranchScope\|groupWide' app/
    |
    | lists every query in the app that deliberately sees more than one branch.
    | That grep is the audit: if a query is not in it, it is scoped.
    |
    |   - withoutBranchScope() (the local scope on BelongsToBranch) is for one
    |     query. Prefer it: the escape is visible at the query it applies to.
    |   - groupWide() is for a region that runs many queries - a group-wide
    |     report, the transfer screen reading stock at both ends - where
    |     repeating the escape on each one would be the thing that misses one.
    */

    /**
     * Run a callback with branch filtering switched off.
     *
     * Restores the previous state even if the callback throws, so a failed
     * report cannot leave the rest of the request reading every branch's data.
     *
     * @template TReturn
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function groupWide(callable $callback)
    {
        $this->suppressed++;

        try {
            return $callback();
        } finally {
            $this->suppressed--;
        }
    }

    /**
     * Run a callback as though signed in at another branch.
     *
     * Needed because "which branch" decides both what is read *and* where stock
     * movements land, so anything acting on another shop's behalf has to say so
     * rather than hope. Used by the tests, and available to a command that has
     * to put a movement somewhere other than where it is running.
     *
     * @template TReturn
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function actingAs(Branch|int|null $branch, callable $callback)
    {
        $previous = [$this->resolved, $this->branchId, $this->branch];

        $this->branchId = $branch instanceof Branch ? $branch->getKey() : $branch;
        $this->branch = $branch instanceof Branch ? $branch : null;
        $this->resolved = true;

        try {
            return $callback();
        } finally {
            [$this->resolved, $this->branchId, $this->branch] = $previous;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Work out the acting branch, most specific source first.
     *
     * Wrapped in a catch, and that is not laziness: on an install where this
     * migration has not run yet there is no `branches` table, and a till that
     * cannot take money because a new feature cannot find its own table is a
     * worse outcome than one acting as no branch. With no branch, stamping
     * writes null and scoping() is false - which is exactly how the app behaved
     * before any of this existed.
     */
    private function resolve(): ?int
    {
        try {
            if (!$this->enabled()) {
                // Single-site: always the default branch, whatever is in the
                // session. A stale preference from a spell of multi-branch
                // trading must not quietly redirect a single shop's data.
                return Branch::default()?->getKey();
            }

            return $this->fromSession()
                ?? $this->fromUser()
                ?? Branch::default()?->getKey();
        } catch (Throwable) {
            return null;
        }
    }

    /** Their last choice, if they are still allowed it. */
    private function fromSession(): ?int
    {
        $id = $this->session?->get(self::SESSION_KEY);

        if (!$id) {
            return null;
        }

        $branch = Branch::find($id);

        // Re-checked on every request on purpose: a branch can be closed, or an
        // assignment withdrawn, while somebody is signed in.
        if ($branch && $branch->is_active && $this->allows($branch)) {
            return $branch->getKey();
        }

        // Drop it rather than keep refusing it every request.
        $this->session?->forget(self::SESSION_KEY);

        return null;
    }

    /** Their home branch, else the first shop they are assigned to. */
    private function fromUser(): ?int
    {
        if ($this->user === null) {
            return null;
        }

        $home = $this->user->branch;

        if ($home && $home->is_active) {
            return $home->getKey();
        }

        return $this->user->availableBranches()->first()?->getKey();
    }
}
