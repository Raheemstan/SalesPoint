<?php

namespace Database\Factories;

use App\Models\ParkedSale;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ParkedSale>
 */
class ParkedSaleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference' => ParkedSale::newReference(),
            'user_id' => User::factory()->cashier(),
            'customer_id' => null,
            'cart' => [],
            'discount' => null,
            'note' => null,
            'item_count' => 0,
            'total' => 0,
        ];
    }

    /**
     * A held cart holding real lines: ->holding([[$product, 2], [$other, 1]]).
     *
     * The stored shape is the session cart's, because that is what resume() puts
     * back - same keys, keyed by product id.
     *
     * @param  array<int, array{0: Product, 1: int, 2?: array<string, mixed>}>  $lines
     */
    public function holding(array $lines): static
    {
        $cart = [];
        $count = 0;
        $total = 0.0;

        foreach ($lines as $line) {
            [$product, $quantity] = $line;

            $cart[$product->id] = array_merge([
                'product_id' => $product->id,
                'name' => $product->name,
                'price' => (float) $product->sale_price,
                'quantity' => $quantity,
            ], $line[2] ?? []);

            $count += $quantity;
            $total += (float) $product->sale_price * $quantity;
        }

        return $this->state(fn () => [
            'cart' => $cart,
            'item_count' => $count,
            'total' => round($total, 2),
        ]);
    }
}
