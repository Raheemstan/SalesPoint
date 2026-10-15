<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `definition()` used to return an empty array, so
     * `Expense::factory()->create()` failed on the NOT NULL columns and tests
     * built expenses by hand instead (see tests/BranchHelpers.php).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement([
                'Generator diesel', 'Shop rent', 'Electricity', 'Transport',
                'Cleaning', 'Repairs', 'Internet', 'Staff lunch',
            ]),
            'category' => $this->faker->randomElement(['Utilities', 'Rent', 'Logistics', 'Maintenance', 'Other']),
            'description' => null,
            'amount' => $this->faker->randomFloat(2, 500, 50000),
            'expense_date' => now()->toDateString(),
            // Expenses are recorded by whoever entered them, so a factory-built
            // one needs an owner or the NOT NULL column refuses it.
            'user_id' => User::factory(),
        ];
    }

    /** An expense on a given day, for date-range reports. */
    public function on(string $date): static
    {
        return $this->state(fn () => ['expense_date' => $date]);
    }

    public function of(float $amount): static
    {
        return $this->state(fn () => ['amount' => $amount]);
    }
}
