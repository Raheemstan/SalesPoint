<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who may read the audit log.
 *
 * Admins only. It is a record of who did what, including who changed whose
 * password, so a manager being able to read it would let them check whether
 * anyone had noticed them.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
