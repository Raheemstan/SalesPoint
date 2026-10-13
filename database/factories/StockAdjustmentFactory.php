<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StockAdjustment>
 */
class StockAdjustmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'user_id' => User::factory()->manager(),
            'type' => $this->faker->randomElement(StockAdjustment::TYPES),
            'quantity' => $this->faker->numberBetween(1, 20),
            'reason' => $this->faker->randomElement(StockAdjustment::REASONS),
            'note' => null,
            'cost_price' => $this->faker->randomFloat(2, 1, 100),
            'stock_take_id' => null,
        ];
    }

    public function decrease(int $quantity = 1, string $reason = 'damage'): static
    {
        return $this->state(fn() => [
            'type' => StockAdjustment::TYPE_DECREASE,
            'quantity' => $quantity,
            'reason' => $reason,
        ]);
    }

    public function increase(int $quantity = 1, string $reason = 'correction'): static
    {
        return $this->state(fn() => [
            'type' => StockAdjustment::TYPE_INCREASE,
            'quantity' => $quantity,
            'reason' => $reason,
        ]);
    }
}
