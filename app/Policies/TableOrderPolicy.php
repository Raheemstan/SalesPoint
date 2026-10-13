<?php

namespace App\Policies;

use App\Models\TableOrder;
use App\Models\User;

/**
 * Who may work on which table's order.
 *
 * DECISION: any member of staff may open, read and add to ANY open table order.
 * This is the one place where table service deliberately breaks the pattern that
 * SalePolicy and ShiftPolicy follow (a cashier is confined to their own records).
 *
 * The reason is that a restaurant floor does not work that way. A runner carries
 * food to a table they never took the order for; the bar adds a round to table 6
 * while the server for table 6 is upstairs; somebody goes on break mid-service
 * and their four tables still need serving; the guests at table 9 ask the first
 * person who walks past for another bottle of water. Scoping an order to the
 * server who opened it would mean either refusing all of that or handing every
 * member of staff a manager's role to get around it - and the second is what
 * really happens in shops where the software insists.
 *
 * So the server on `table_orders.user_id` is a record of who opened it, shown on
 * the floor view so staff know whose section a table is in. It is not ownership.
 *
 * What is still restricted:
 *   - cancelling a whole order needs a manager (it writes off everything on it)
 *   - a line the kitchen has already cooked needs a reason to remove, from
 *     anyone, and that reason is audited (enforced in TableOrderService)
 *   - a closed order is closed to everybody, manager included: it has become a
 *     sale, and a sale is corrected through a refund, not by editing the meal
 */
class TableOrderPolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Any staff member may read any order, open or closed.
     *
     * Reading a settled order is how a server answers "what did table 4 have?"
     * after the guests have gone, so it is not restricted to open ones.
     */
    public function view(User $user, TableOrder $order): bool
    {
        return true;
    }

    /** Anyone on shift can seat guests. */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Add to, edit, send to the kitchen, transfer.
     *
     * The only gate is that the order is still open. Once it has been paid for or
     * cancelled, nobody edits it - the money has moved on.
     */
    public function update(User $user, TableOrder $order): bool
    {
        return $order->isOpen();
    }

    /** Hand the bill to the till. Same rule as editing: it must still be open. */
    public function settle(User $user, TableOrder $order): bool
    {
        return $order->isOpen();
    }

    /**
     * Cancel the whole order, writing off whatever is on it.
     *
     * Manager only. This is the one action with no money trail to inspect
     * afterwards - no sale, no refund, just food that left the kitchen and was
     * never charged for - so it needs someone who can be asked about it.
     */
    public function delete(User $user, TableOrder $order): bool
    {
        return $order->isOpen() && $this->isManager($user);
    }
}
