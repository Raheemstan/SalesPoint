<?php

namespace App\Policies;

use App\Models\ParkedSale;
use App\Models\User;

/**
 * Who may touch a held cart.
 *
 * Same shape as SalePolicy and ShiftPolicy - a cashier is confined to their own
 * records, a manager sees everyone's - with one rule that is deliberately
 * stricter for everybody, managers included:
 *
 *   **only the cashier who parked a cart may resume it.**
 *
 * Resuming is not reading: it loads the cart into *your* till, and the sale that
 * comes out the other end is rung up under your name, during your shift, against
 * your drawer. A manager who needs a held sale gone can delete it; a manager who
 * wants to serve that customer rings the sale up themselves. Letting a second
 * person resume it would also mean two tills could hold the same cart.
 */
class ParkedSalePolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function viewAny(User $user): bool
    {
        return true; // the list itself is scoped per role in the controller
    }

    /** Managers see every held cart (they have to clear up abandoned ones); a cashier sees their own. */
    public function view(User $user, ParkedSale $parked): bool
    {
        return $this->isManager($user) || $parked->user_id === $user->id;
    }

    /** Anyone who can work the till can park what is in front of them. */
    public function create(User $user): bool
    {
        return true;
    }

    /** Only the cashier who parked it - see the class docblock. */
    public function resume(User $user, ParkedSale $parked): bool
    {
        return $parked->user_id === $user->id;
    }

    /** Your own, or anyone's if you are a manager: abandoned carts have to be clearable. */
    public function delete(User $user, ParkedSale $parked): bool
    {
        return $this->isManager($user) || $parked->user_id === $user->id;
    }
}
