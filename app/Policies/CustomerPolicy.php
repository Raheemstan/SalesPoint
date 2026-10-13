<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Who may do what with customers.
 *
 * A cashier needs to *find* a customer - the till cannot ring a credit sale
 * without one - but managing the account, and anything to do with the money
 * owed, belongs to a manager.
 *
 * Deleting is refused for anyone who has traded: see Customer::isDeletable(),
 * which the controller checks and offers deactivation instead.
 */
class CustomerPolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->isManager($user);
    }

    /**
     * The till's customer picker. Any signed-in user may look a customer up;
     * the JSON it returns is whitelisted to what the till needs (see
     * CustomerController::search), so notes and addresses stay with managers.
     */
    public function search(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->isManager($user);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->isManager($user);
    }
}
