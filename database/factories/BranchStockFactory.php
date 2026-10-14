<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BranchStock>
 */
class BranchStockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'product_id' => Product::factory(),
            'stock_quantity' => 0,
            // Null means "use the product's reorder level", which is what nearly
            // every row should be.
            'alert_quantity' => null,
        ];
    }
}
