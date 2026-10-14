<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockTransfer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StockTransferItem>
 */
class StockTransferItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'stock_transfer_id' => StockTransfer::factory(),
            'product_id' => Product::factory(),
            'quantity' => 1,
            'cost_price' => 0,
        ];
    }
}
