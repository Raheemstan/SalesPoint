<?php

namespace Database\Factories;

use App\Models\Purchase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PurchaseReturn>
 */
class PurchaseReturnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'purchase_id' => Purchase::factory(),
            // Denormalised from the purchase, so it must agree with it: take the
            // supplier from whichever purchase was resolved above.
            'supplier_id' => fn(array $attributes) => Purchase::withTrashed()->find($attributes['purchase_id'])?->supplier_id,
            'user_id' => User::factory(),
            'reference' => $this->faker->unique()->bothify('RTN-#####'),
            'total_amount' => $this->faker->randomFloat(2, 10, 500),
            'reason' => 'Damaged on delivery',
        ];
    }
}
