<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'phone' => $this->faker->unique()->numerify('080########'),
            'email' => $this->faker->unique()->safeEmail(),
            'address' => $this->faker->address(),
            // 0 = no credit limit, which is the sensible default for a shop
            // that has not thought about limits yet.
            'credit_limit' => 0,
            'is_active' => true,
        ];
    }

    /** A credit limit, in the shop's currency. */
    public function limit(float $amount): static
    {
        return $this->state(fn() => ['credit_limit' => $amount]);
    }

    public function inactive(): static
    {
        return $this->state(fn() => ['is_active' => false]);
    }

    /** A walk-in who gave a name and nothing else. */
    public function walkIn(): static
    {
        return $this->state(fn() => ['phone' => null, 'email' => null, 'address' => null]);
    }
}
