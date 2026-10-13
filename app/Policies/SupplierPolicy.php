<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

/**
 * Suppliers are back-office: a cashier never sees who the shop buys from or
 * what it owes them. The routes are already behind `role:admin|manager`; this
 * is the second lock, so a new route or an inline-add path cannot leak them.
 */
class SupplierPolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->isManager($user);
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->isManager($user);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->isManager($user);
    }

    /** Recording and reversing money paid out is the same level of trust. */
    public function pay(User $user, Supplier $supplier): bool
    {
        return $this->isManager($user);
    }

    public function restore(User $user, Supplier $supplier): bool
    {
        return $this->isManager($user);
    }

    public function forceDelete(User $user, Supplier $supplier): bool
    {
        return false;
    }
}
