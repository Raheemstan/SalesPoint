<?php

namespace App\Policies;

use App\Models\StockAdjustment;
use App\Models\User;

/**
 * Managers and admins may adjust stock and read the history. A cashier may not:
 * the till already moves stock by selling, and an adjustment is how stock can
 * leave without being sold.
 *
 * Adjustments are never edited or deleted - a wrong one is corrected with
 * another adjustment, so the history stays the whole story.
 *
 * The three abilities at the bottom are the gate for the rest of stock control
 * (the reorder list and the bulk product CSV). They live here because they ask
 * the same question - "may this person change what we believe we have, outside
 * the till?" - and because a screen is not a model, so there is nothing else to
 * hang them on.
 */
class StockAdjustmentPolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, StockAdjustment $adjustment): bool
    {
        return $this->isManager($user);
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function update(User $user, StockAdjustment $adjustment): bool
    {
        return false;
    }

    public function delete(User $user, StockAdjustment $adjustment): bool
    {
        return false;
    }

    /** The reorder list: what has fallen to or below its reorder level. */
    public function reorder(User $user): bool
    {
        return $this->isManager($user);
    }

    /** Bulk product CSV: it creates products and their opening stock. */
    public function importProducts(User $user): bool
    {
        return $this->isManager($user);
    }

    /** Bulk product CSV out: the whole catalogue, cost prices included. */
    public function exportProducts(User $user): bool
    {
        return $this->isManager($user);
    }
}
