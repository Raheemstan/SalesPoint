<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'sku' => $this->faker->unique()->bothify('SKU-###'),
            'barcode' => $this->faker->unique()->ean13(),
            'category_id' => Category::factory()->create()->id,
            'purchase_price' => $this->faker->randomFloat(2, 1, 100),
            'sale_price' => $this->faker->randomFloat(2, 1, 200),
            'stock_quantity' => $this->faker->numberBetween(0, 1000),
            'description' => $this->faker->sentence(),
        ];
    }
}
