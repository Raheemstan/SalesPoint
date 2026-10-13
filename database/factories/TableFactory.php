<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Table>
 */
class TableFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Unique: the column is, and a floor full of "T1"s is not a floor.
            'name' => 'T' . $this->faker->unique()->numberBetween(1, 9999),
            'area' => null,
            'seats' => 4,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /** Taken out of service: still named, cannot be seated. */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
