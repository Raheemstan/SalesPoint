<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * Categories are part of the catalogue, so they follow ProductPolicy exactly:
 * a manager or an admin maintains them, a cashier never does. Renaming a
 * department reprints every shelf label and re-cuts every report grouped by
 * it, and deleting one cascades to its products.
 *
 * This class was a `make:policy` stub that returned false from all seven
 * methods. Nothing called it, so nothing failed - it would have denied
 * everybody the moment someone wired the first Gate::authorize in. The routes
 * are already behind `role:admin|manager`; this is the second lock.
 */
class CategoryPolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, Category $category): bool
    {
        return $this->isManager($user);
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->isManager($user);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->isManager($user);
    }

    public function restore(User $user, Category $category): bool
    {
        return $this->isManager($user);
    }

    /**
     * Nobody, matching ProductPolicy: a category is referenced by products,
     * inventory logs and reports, so erasing the row rather than soft-deleting
     * it leaves history pointing at nothing.
     */
    public function forceDelete(User $user, Category $category): bool
    {
        return false;
    }
}
