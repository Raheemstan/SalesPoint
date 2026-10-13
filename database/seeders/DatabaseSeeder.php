<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Database\Factories\ProductFactory;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Safe to run more than once: the admin is matched on email and updated
     * rather than duplicated, and the demo catalogue is only ever created on an
     * empty database (see below).
     */
    public function run(): void
    {
        // Roles first, so the admin user below gets its Spatie role.
        $this->call([
            RoleSeeder::class,
        ]);

        // Override these in .env for anything that is not a throwaway local install.
        // `role` is deliberately not mass-assignable, so set it explicitly.
        $admin = User::firstOrNew(['email' => env('SEED_ADMIN_EMAIL', 'test@example.com')]);
        $admin->forceFill([
            'name' => env('SEED_ADMIN_NAME', 'Administrator'),
            'role' => 'admin',
            'password' => env('SEED_ADMIN_PASSWORD', 'password'), // hashed by the model cast
        ])->save();

        $this->seedDemoCatalogue();
    }

    /**
     * Fifty made-up products, so a fresh local install has something to sell.
     *
     * This used to run unconditionally, which meant `--seed` on a live shop added
     * fifty fictional products to a real catalogue every time it was run. It is now
     * skipped unless the catalogue is empty, and can be turned off outright with
     * SEED_DEMO_DATA=false.
     */
    private function seedDemoCatalogue(): void
    {
        if (!filter_var(env('SEED_DEMO_DATA', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        if (Product::withTrashed()->exists()) {
            $this->command?->info('Products already exist; skipping the demo catalogue.');

            return;
        }

        ProductFactory::new()->count(50)->create();
    }
}
