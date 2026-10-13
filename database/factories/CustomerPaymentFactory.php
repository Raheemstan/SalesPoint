<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomerPayment>
 *
 * Note: a payment made this way has no allocations, so it settles nothing.
 * Payments that have to add up should go through CreditService::applyPayment().
 */
class CustomerPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'user_id' => User::factory()->manager(),
            'amount' => $this->faker->randomFloat(2, 100, 50000),
            'method' => $this->faker->randomElement(['cash', 'pos', 'transfer']),
            'reference' => null,
            'note' => null,
            'paid_at' => now(),
        ];
    }

    public function method(string $method): static
    {
        return $this->state(fn() => ['method' => $method]);
    }
}
