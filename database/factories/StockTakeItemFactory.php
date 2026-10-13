<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockTake;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StockTakeItem>
 */
class StockTakeItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'stock_take_id' => StockTake::factory(),
            'product_id' => Product::factory(),
            'system_quantity' => $this->faker->numberBetween(0, 100),
            'counted_quantity' => null,
            'variance' => 0,
            'cost_price' => $this->faker->randomFloat(2, 1, 100),
            'note' => null,
        ];
    }

    /** A line with a figure written against it, variance kept consistent. */
    public function counted(int $counted): static
    {
        return $this->state(fn(array $attributes) => [
            'counted_quantity' => $counted,
            'variance' => $counted - (int) $attributes['system_quantity'],
        ]);
    }
}
