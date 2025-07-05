<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Factories\ProductFactory;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => 'admin',
            'password' => bcrypt('password'),
        ]);
        $this->call([
            RoleSeeder::class,
        ]);
        ProductFactory::new()->count(50)->create();
    }
}
