<?php

namespace App\Policies;

use App\Models\SaleItemSerial;
use App\Models\User;

/**
 * Serial / IMEI capture and lookup is a manager's job.
 *
 * A cashier sells the unit; recording which physical unit went out, and above
 * all *correcting* that afterwards, is not something the person at the till may
 * do alone - the record is what a warranty claim is settled on, so being able to
 * retype it is being able to rewrite history.
 *
 * (The lookup is gated the same way for now. A shop that wants its counter staff
 * answering "when was this sold?" can move the lookup route out of the
 * manager-only group and relax viewAny here; nothing else depends on it.)
 */
class SaleItemSerialPolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    /** The lookup screen: search a serial, see the sale it went out on. */
    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, SaleItemSerial $serial): bool
    {
        return $this->isManager($user);
    }

    /** Recording serials against a sale line, after the fact or from the till. */
    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    /** Correcting a line's serials (a mistyped IMEI is the usual reason). */
    public function update(User $user, SaleItemSerial $serial): bool
    {
        return $this->isManager($user);
    }

    public function delete(User $user, SaleItemSerial $serial): bool
    {
        return $this->isManager($user);
    }
}
