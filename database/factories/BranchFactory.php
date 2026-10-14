<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        $code = 'B' . $this->faker->unique()->numberBetween(1, 99999);

        return [
            'name' => $code . ' Branch',
            // Unique: the column is, and two shops answering to one code is
            // exactly the confusion the code exists to prevent.
            'code' => $code,
            'address' => null,
            'phone' => null,
            'is_active' => true,
            /*
             * Never the default. The migration already created the one default
             * branch, and a factory quietly minting a second would move where
             * every new row lands - which is the hardest kind of test failure to
             * read. Use Branch::makeDefault() when a test means it.
             */
            'is_default' => false,
            'sort_order' => 0,
        ];
    }

    /** Closed: still named, still carries its history, cannot be stood in. */
    public function closed(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
