<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SupplierPayment>
 */
class SupplierPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'user_id' => User::factory(),
            'amount' => $this->faker->randomFloat(2, 100, 2000),
            'method' => 'cash',
            'reference' => null,
            'note' => null,
            'paid_at' => now(),
        ];
    }
}
