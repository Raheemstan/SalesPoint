<?php

namespace App\Policies;

use App\Models\StockTake;
use App\Models\User;

/**
 * Who may count stock, and - the part that matters - who may approve a count.
 *
 * APPROVAL IS THE CONTROL. A stock-take is how stock legitimately disappears
 * without a sale, so whoever can approve one can hide a theft. We therefore
 * apply four eyes: a manager may approve any sheet *except one they counted
 * themselves*. An admin may approve anything, because an admin can already
 * change any figure in the system and a shop with a single admin must not be
 * locked out of reconciling its own stock.
 *
 * Cashiers get nothing here at all.
 */
class StockTakePolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, StockTake $stockTake): bool
    {
        return $this->isManager($user);
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    /** Typing counts in: only while the sheet is still open. */
    public function update(User $user, StockTake $stockTake): bool
    {
        return $this->isManager($user) && $stockTake->isEditable();
    }

    /** Four eyes: not the person who counted, unless they are an admin. */
    public function approve(User $user, StockTake $stockTake): bool
    {
        if (!$this->isManager($user) || !$stockTake->isEditable()) {
            return false;
        }

        return $user->hasRole('admin') || $stockTake->user_id !== $user->id;
    }

    /**
     * Deleting a sheet is really "cancel": an approved one has moved stock and
     * is part of the audit trail, so it stays.
     */
    public function delete(User $user, StockTake $stockTake): bool
    {
        return $this->isManager($user) && !$stockTake->isApproved();
    }
}
