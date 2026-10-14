<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;

/**
 * Who may move stock between shops.
 *
 * Managers and admins, and nobody else: a transfer is the one operation that
 * legitimately removes stock from a shop without a sale, so whoever can raise
 * one can empty a shelf. Cashiers get none of it.
 *
 * THE OTHER HALF OF THE CONTROL IS NOT HERE
 *
 * A role is only half the question. The rest is *where you are standing* - you
 * may only send from the branch you are acting as and only receive at the one
 * the goods were sent to - and that lives in App\Services\StockTransferService,
 * because it has to hold for every caller and not just for a request that
 * happened to pass through a policy.
 */
class StockTransferPolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    /**
     * Both ends can read a transfer, and only they can.
     *
     * The destination has to see what is coming, the source has to see what it
     * sent. A third branch has no business reading either - except an admin,
     * who is looking at the group rather than at a shop.
     */
    public function view(User $user, StockTransfer $transfer): bool
    {
        if (!$this->isManager($user)) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        return in_array(
            (int) ($user->branch_id ?? 0),
            [(int) $transfer->from_branch_id, (int) $transfer->to_branch_id],
            true
        ) || $this->assignedToEitherEnd($user, $transfer);
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    /** Confirming goods arrived. The service checks you are at the right end. */
    public function receive(User $user, StockTransfer $transfer): bool
    {
        return $this->isManager($user) && $transfer->isInTransit();
    }

    /** Recalling stock that has not landed yet. */
    public function cancel(User $user, StockTransfer $transfer): bool
    {
        return $this->isManager($user) && $transfer->isInTransit();
    }

    /** Is this person assigned to work at either end of this transfer? */
    private function assignedToEitherEnd(User $user, StockTransfer $transfer): bool
    {
        return $user->branches()
            ->whereIn('branches.id', [$transfer->from_branch_id, $transfer->to_branch_id])
            ->exists();
    }
}
