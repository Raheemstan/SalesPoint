<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StockTransfer>
 */
class StockTransferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference' => 'TRF-' . now()->format('YmdHis') . '-' . $this->faker->unique()->bothify('??##'),
            'from_branch_id' => Branch::factory(),
            'to_branch_id' => Branch::factory(),
            'status' => StockTransfer::STATUS_IN_TRANSIT,
            'created_by' => User::factory()->manager(),
            'dispatched_at' => now(),
            'note' => null,
        ];
    }

    /**
     * A received transfer.
     *
     * NOTE: this only writes the *record*. It moves no stock, because moving
     * stock is StockTransferService's job and a factory bypassing it would
     * produce a shelf figure that nothing in the app could have produced. A test
     * about stock should dispatch and receive through the service.
     */
    public function received(): static
    {
        return $this->state(fn () => [
            'status' => StockTransfer::STATUS_RECEIVED,
            'received_at' => now(),
            'received_by' => User::factory()->manager(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => StockTransfer::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => User::factory()->manager(),
        ]);
    }
}
