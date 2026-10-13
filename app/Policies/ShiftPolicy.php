<?php

namespace App\Policies;

use App\Models\Shift;
use App\Models\User;

/**
 * Who may see and sign off a drawer.
 *
 * Same shape as SalePolicy: a cashier is confined to their own records, a
 * manager sees everyone's. The one rule with teeth is that a closed shift is
 * closed for good - a reconciliation nobody can reopen is the only kind worth
 * signing.
 */
class ShiftPolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function viewAny(User $user): bool
    {
        return true; // the list itself is scoped per role in the controller
    }

    /** Managers see every drawer; a cashier sees only their own. */
    public function view(User $user, Shift $shift): bool
    {
        return $this->isManager($user) || $shift->user_id === $user->id;
    }

    /** Anyone who can work the till can open their own shift. */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * A cashier closes their own drawer; a manager can force-close anyone's (a
     * cashier who has gone home still leaves a drawer that has to be counted).
     *
     * Nobody closes a shift twice, whatever their role: the second close would
     * overwrite the counted figures and the variance the first one recorded,
     * which is exactly the audit trail this feature exists to keep.
     */
    public function close(User $user, Shift $shift): bool
    {
        if (!$shift->isOpen()) {
            return false;
        }

        return $this->isManager($user) || $shift->user_id === $user->id;
    }
}
