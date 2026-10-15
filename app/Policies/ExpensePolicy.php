<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

/**
 * An expense is money leaving the business, and it lands directly in the
 * profit-and-loss report. Recording or editing one is a back-office job: a
 * cashier takes money in, never signs it out.
 *
 * This class was a `make:policy` stub that returned false from all seven
 * methods. Nothing called it, so nothing failed - it would have denied
 * everybody the moment someone wired the first Gate::authorize in. The routes
 * are already behind `role:admin|manager`; this is the second lock.
 */
class ExpensePolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->isManager($user);
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    /**
     * Not restricted to whoever recorded it: a manager correcting last week's
     * fuel entry is routine, and the audit log already carries who changed it.
     */
    public function update(User $user, Expense $expense): bool
    {
        return $this->isManager($user);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->isManager($user);
    }

    public function restore(User $user, Expense $expense): bool
    {
        return $this->isManager($user);
    }

    /** Nobody: the profit figure for a past period must stay reproducible. */
    public function forceDelete(User $user, Expense $expense): bool
    {
        return false;
    }
}
