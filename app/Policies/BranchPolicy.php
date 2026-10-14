<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

/**
 * Who may run the shops, and who may stand in them.
 *
 * TWO DIFFERENT QUESTIONS, AND THEY DO NOT HAVE THE SAME ANSWER
 *
 * *Managing* branches - opening one, closing one, renaming one - is a structural
 * decision about the business, so it is an admin's. A manager can see the list,
 * because every report they read is now "which shop?", but cannot change what
 * the shops are.
 *
 * *Switching* to a branch is a different question entirely: it is about where
 * somebody is standing today, and the answer lives on the user (canUseBranch).
 * A cashier may switch between the shops they are assigned to and no others -
 * that is the control that stops a till operator reading another shop's takings
 * or selling off its shelf - while an admin or manager may stand anywhere,
 * because running several sites is the job.
 *
 * Cashiers get nothing on this screen at all.
 */
class BranchPolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    /** Managers read the list; every per-branch report starts from it. */
    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, Branch $branch): bool
    {
        return $this->isManager($user);
    }

    /** Opening a shop is an owner's decision. */
    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Closing a shop.
     *
     * Never a real delete, and the controller does not offer one: a branch is
     * stamped on every sale, drawer and stock movement it ever saw, and removing
     * the row would orphan all of it. The ability is "may deactivate", and the
     * default branch is excluded because something has to be left for new rows
     * to fall back to.
     */
    public function delete(User $user, Branch $branch): bool
    {
        return $user->hasRole('admin') && !$branch->is_default;
    }

    /**
     * May this person act as this branch?
     *
     * Delegated to the user, because the same rule has to hold in three places
     * that are not all policy checks: this screen, the header switcher deciding
     * what to draw, and BranchContext validating a remembered choice on every
     * single request.
     */
    public function switchTo(User $user, Branch $branch): bool
    {
        return $user->canUseBranch($branch);
    }
}
