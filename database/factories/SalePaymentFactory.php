<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SalePayment>
 */
class SalePaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'method' => $this->faker->randomElement(SalePayment::METHODS),
            'amount' => $this->faker->randomFloat(2, 1, 5000),
            'reference' => null,
        ];
    }

    /** A tender on one method: ->of('transfer', 1500). */
    public function of(string $method, float $amount): static
    {
        return $this->state(fn () => ['method' => $method, 'amount' => round($amount, 2)]);
    }
}
