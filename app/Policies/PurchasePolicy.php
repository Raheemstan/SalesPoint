<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;

/**
 * Buying is a manager's job: a cashier must not see cost prices, let alone
 * change a delivery after the fact. The routes are already behind
 * `role:admin|manager`; this is the second lock.
 */
class PurchasePolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $this->isManager($user);
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function update(User $user, Purchase $purchase): bool
    {
        return $this->isManager($user);
    }

    public function delete(User $user, Purchase $purchase): bool
    {
        return $this->isManager($user);
    }

    /** Sending goods back takes stock out, so it needs the same trust as a delete. */
    public function return(User $user, Purchase $purchase): bool
    {
        return $this->isManager($user);
    }

    public function restore(User $user, Purchase $purchase): bool
    {
        return $this->isManager($user);
    }

    public function forceDelete(User $user, Purchase $purchase): bool
    {
        return false;
    }
}
