<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function viewAny(User $user): bool
    {
        return true; // the list itself is scoped per role in the controller
    }

    /** Managers see every sale; cashiers only their own sales from today (enough to reprint a receipt). */
    public function view(User $user, Sale $sale): bool
    {
        return $this->isManager($user)
            || ($sale->user_id === $user->id && $sale->created_at->isToday());
    }

    public function refund(User $user, Sale $sale): bool
    {
        return $this->isManager($user);
    }

    public function void(User $user, Sale $sale): bool
    {
        return $this->isManager($user);
    }
}
