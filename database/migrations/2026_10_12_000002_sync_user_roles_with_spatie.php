<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Existing installs stored the role only in users.role, while route
     * middleware checks Spatie roles. Backfill the Spatie side once.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (User::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        User::withTrashed()->each(function (User $user) {
            $user->syncRoles([$user->role ?: 'cashier']);
        });
    }

    public function down(): void
    {
        // Intentionally empty: role assignments are harmless to keep.
    }
};
