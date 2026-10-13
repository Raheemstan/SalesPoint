<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Supplier>
 */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'phone' => $this->faker->numerify('080########'),
            'email' => $this->faker->unique()->companyEmail(),
            // suppliers.address is NOT NULL.
            'address' => $this->faker->streetAddress(),
            'is_active' => true,
            'opening_balance' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn() => ['is_active' => false]);
    }

    public function owing(float $amount): static
    {
        return $this->state(fn() => ['opening_balance' => $amount]);
    }
}
