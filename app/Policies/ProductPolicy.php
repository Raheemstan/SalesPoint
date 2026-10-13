<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * The catalogue is a manager's job: a cashier sells what is in it but never
 * changes a price, a cost or a stock level. The routes are already behind
 * `role:admin|manager`; this is the second lock.
 */
class ProductPolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->isManager($user);
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->isManager($user);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->isManager($user);
    }

    public function restore(User $user, Product $product): bool
    {
        return $this->isManager($user);
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return false;
    }
}
