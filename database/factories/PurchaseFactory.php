<?php

namespace Database\Factories;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Purchase>
 */
class PurchaseFactory extends Factory
{
    public function definition(): array
    {
        $total = $this->faker->randomFloat(2, 100, 5000);

        return [
            'invoice_number' => $this->faker->unique()->bothify('PO-#####'),
            'supplier_id' => Supplier::factory(),
            'user_id' => User::factory(),
            'total_amount' => $total,
            'purchase_date' => now(),
            // The default mirrors the controller: a purchase typed in at the
            // counter is normally one that has already been delivered.
            'status' => Purchase::STATUS_RECEIVED,
            'amount_paid' => 0,
            'balance_due' => $total,
            'received_at' => now(),
        ];
    }

    /** Raised with the supplier but not delivered: no stock has moved. */
    public function ordered(): static
    {
        return $this->state(fn() => [
            'status' => Purchase::STATUS_ORDERED,
            'received_at' => null,
            'expected_at' => now()->addWeek(),
        ]);
    }

    /** Settled in full when it was recorded. */
    public function paid(): static
    {
        return $this->state(fn(array $attributes) => [
            'amount_paid' => $attributes['total_amount'],
            'balance_due' => 0,
        ]);
    }
}
