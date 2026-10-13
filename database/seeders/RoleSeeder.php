<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds. Safe to run more than once.
     */
    public function run(): void
    {
        foreach (User::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
