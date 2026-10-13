<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who may administer accounts.
 *
 * Route middleware already restricts these routes to admins. This is the second
 * check, because middleware is a property of a URL and authorisation is a
 * property of an action: anything that later calls UserController from a
 * console command, a queued job or another route gets the same answer.
 *
 * The guard rails that depend on *what* is being changed - you may not take
 * your own admin role away, the last admin may not be demoted, deactivated or
 * deleted - are not here. A policy only sees the row, not the submitted value,
 * and those cases deserve a sentence explaining the refusal rather than a bare
 * 403. They live in UserController, next to the message they produce.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, User $target): bool
    {
        return $this->administers($user, $target);
    }

    public function resetPassword(User $user, User $target): bool
    {
        return $this->administers($user, $target);
    }

    public function toggleStatus(User $user, User $target): bool
    {
        return $this->administers($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        return $this->administers($user, $target);
    }

    /**
     * Only an admin, and never an account that has already been deleted:
     * resurrecting a soft-deleted user through an edit form would quietly give
     * them their login back.
     */
    private function administers(User $user, User $target): bool
    {
        return $user->hasRole('admin') && !$target->trashed();
    }
}
