<?php

namespace App\Policies;

use App\Models\OfflineSaleAttempt;
use App\Models\User;

/**
 * Who may see and replay offline sales.
 *
 * Same shape as SalePolicy and ParkedSalePolicy - a cashier is confined to their
 * own work, a manager sees everyone's - with one rule that is specific to this
 * feature:
 *
 *   **a cashier may only sync sales they rang up themselves.**
 *
 * The queue lives on a device, and a device can be shared, so a batch arriving
 * from one login may contain sales taken under another. Honouring the cashier id
 * in the payload is what gives a replayed sale the right owner - but if any
 * cashier could name any cashier, the id would be worthless: a sale could be
 * parked on somebody else's shift, somebody else's drawer and somebody else's
 * discount limit. So only a manager may post a batch on another cashier's
 * behalf, which is the real case (a supervisor clearing a till's backlog at the
 * end of the day), and a cashier syncing someone else's sale is refused and
 * recorded rather than quietly reattributed.
 */
class OfflineSaleAttemptPolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    /** Everyone who can work a till needs to see what their device is holding. */
    public function viewAny(User $user): bool
    {
        return true; // the listing itself is scoped per role - see OfflineSyncController::status
    }

    public function view(User $user, OfflineSaleAttempt $attempt): bool
    {
        return $this->isManager($user) || $attempt->user_id === $user->id;
    }

    /** Anyone who can sell can send up what they sold while the line was down. */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * May this user replay a sale rung up by `$cashierId`? See the class docblock.
     */
    public function syncFor(User $user, int $cashierId): bool
    {
        return $cashierId === $user->id || $this->isManager($user);
    }
}
