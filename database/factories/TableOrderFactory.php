<?php

namespace Database\Factories;

use App\Models\Table;
use App\Models\TableOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TableOrder>
 */
class TableOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference' => 'TBL-' . now()->format('Ymd') . '-' . Str::upper(Str::random(5)),
            'table_id' => Table::factory(),
            'user_id' => User::factory()->cashier(),
            'status' => TableOrder::STATUS_OPEN,
            'guests' => 2,
            'opened_at' => now(),
        ];
    }

    public function settled(): static
    {
        return $this->state(fn () => [
            'status' => TableOrder::STATUS_SETTLED,
            'settled_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => TableOrder::STATUS_CANCELLED]);
    }
}
