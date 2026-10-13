<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\TableOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TableOrderItem>
 */
class TableOrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'table_order_id' => TableOrder::factory(),
            'product_id' => Product::factory(),
            'quantity' => 1,
            'price' => 1000,
            'sent_to_kitchen_at' => null,
        ];
    }

    /** Already printed for the kitchen: the food exists, so the line is locked. */
    public function sent(): static
    {
        return $this->state(fn () => ['sent_to_kitchen_at' => now()]);
    }
}
