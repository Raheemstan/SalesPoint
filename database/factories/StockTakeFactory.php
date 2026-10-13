<?php

namespace Database\Factories;

use App\Models\StockTake;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StockTake>
 */
class StockTakeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference' => 'ST-' . now()->format('Ymd') . '-' . Str::upper(Str::random(5)),
            'user_id' => User::factory()->manager(),
            'status' => StockTake::STATUS_DRAFT,
            'note' => null,
            'counted_at' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn() => [
            'status' => StockTake::STATUS_SUBMITTED,
            'counted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn() => [
            'status' => StockTake::STATUS_APPROVED,
            'counted_at' => now(),
            'approved_at' => now(),
            'approved_by' => User::factory()->admin(),
        ]);
    }
}
