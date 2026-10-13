<?php

namespace App\Policies;

use App\Models\Table;
use App\Models\User;

/**
 * Who may look at the floor, and who may change what the floor is made of.
 *
 * The split is deliberate and matches how a restaurant actually runs: every
 * member of staff needs the floor view (it is the home screen of their shift),
 * but adding, renaming or retiring a table is a decision about the premises and
 * belongs to whoever runs them.
 */
class TablePolicy
{
    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }

    /** Anyone on shift sees the floor. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function update(User $user, Table $table): bool
    {
        return $this->isManager($user);
    }

    /**
     * Deleting is a manager's call, and even then only for a table with no
     * history - the refusal for a table that is in use lives in the controller,
     * which can say *why* and offer to deactivate instead.
     */
    public function delete(User $user, Table $table): bool
    {
        return $this->isManager($user);
    }
}
