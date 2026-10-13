<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
            'cost_price' => $this->faker->randomFloat(2, 1, 100),
            'sale_price' => $this->faker->randomFloat(2, 1, 200),
            'stock_quantity' => $this->faker->numberBetween(0, 1000),

        ];
    }

    /**
     * A variant of a style: a product with a parent.
     *
     * The style's category is inherited, as VariantService does, so a test does
     * not end up with a shirt and its own sleeves in different departments. The
     * parent is left exactly as it is: tests that care about a style becoming a
     * grouping row (its stock moved onto its variants, tracking switched off)
     * should go through VariantService, which is what does that.
     */
    public function variantOf(Product $parent, string $label = 'Red / XL'): static
    {
        return $this->state(fn() => [
            'parent_id' => $parent->id,
            'variant_label' => $label,
            'name' => $parent->name . ' - ' . $label,
            'sku' => Str::upper($parent->sku . '-' . Str::slug($label, '-')) . '-' . Str::upper(Str::random(3)),
            'category_id' => $parent->category_id,
            'supplier_id' => $parent->supplier_id,
            // products.unit is NOT NULL with a default, and a parent built by the
            // factory has not been read back, so its copy of the column is null.
            'unit' => $parent->unit ?: 'piece',
        ]);
    }

    /** An item whose units are individually identified: a phone, not a phone case. */
    public function serialTracked(): static
    {
        return $this->state(fn() => ['serial_required' => true]);
    }
}
