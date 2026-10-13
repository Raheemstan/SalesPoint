<?php

namespace App\Policies;

use App\Models\CustomerPayment;
use App\Models\User;

/**
 * Debt payments are a manager's job. A cashier takes money at the till, where
 * the sale is in front of them; collecting against an account means knowing what
 * the account owes, and reversing a payment rewrites what a customer is told
 * they owe - so both sit with managers and admins.
 */
class CustomerPaymentPolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function viewAny(User $user): bool
    {
        return $this->isManager($user);
    }

    public function view(User $user, CustomerPayment $payment): bool
    {
        return $this->isManager($user);
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    /** Reversing a payment: manager/admin only. */
    public function delete(User $user, CustomerPayment $payment): bool
    {
        return $this->isManager($user);
    }
}
