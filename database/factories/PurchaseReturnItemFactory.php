<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PurchaseReturnItem>
 */
class PurchaseReturnItemFactory extends Factory
{
    public function definition(): array
    {
        $quantity = $this->faker->numberBetween(1, 5);
        $cost = $this->faker->randomFloat(2, 1, 100);

        return [
            'purchase_return_id' => PurchaseReturn::factory(),
            'purchase_item_id' => PurchaseItem::factory(),
            'product_id' => Product::factory(),
            'quantity' => $quantity,
            'unit_cost' => $cost,
            'amount' => round($quantity * $cost, 2),
        ];
    }
}
