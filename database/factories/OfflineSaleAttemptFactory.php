<?php

namespace Database\Factories;

use App\Models\OfflineSaleAttempt;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OfflineSaleAttempt>
 */
class OfflineSaleAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'user_id' => User::factory()->cashier(),
            'payload' => [
                'client_uuid' => (string) Str::uuid(),
                'reference' => 'OFF-' . Str::upper(Str::random(4)),
                'rung_up_at' => now()->subHour()->toIso8601String(),
                'lines' => [],
                'tenders' => [['method' => 'cash', 'amount' => 0]],
                'claimed_total' => 0,
            ],
            'status' => OfflineSaleAttempt::ACCEPTED,
            'reason' => null,
            'sale_id' => null,
            'received_at' => now(),
        ];
    }

    /** Banked, pointing at the sale it became. */
    public function accepted(?Sale $sale = null): static
    {
        return $this->state(fn () => [
            'status' => OfflineSaleAttempt::ACCEPTED,
            'sale_id' => $sale?->id,
            'reason' => null,
        ]);
    }

    /** Refused, with the words the cashier is shown. */
    public function rejected(string $reason = 'Stock had already gone.'): static
    {
        return $this->state(fn () => [
            'status' => OfflineSaleAttempt::REJECTED,
            'reason' => $reason,
            'sale_id' => null,
        ]);
    }

    /** A replay of something already banked. */
    public function duplicate(?Sale $sale = null): static
    {
        return $this->state(fn () => [
            'status' => OfflineSaleAttempt::DUPLICATE,
            'sale_id' => $sale?->id,
            'reason' => 'Already banked as ' . ($sale?->invoice_number ?? 'an earlier invoice') . '.',
        ]);
    }

    /**
     * A payload with real lines on it: ->forLines([[$product, 2]]).
     *
     * The shape is the one OfflineSyncService validates, so a payload built here
     * can be posted straight at /pos/sync.
     *
     * @param  array<int, array{0: Product, 1: int}>  $lines
     */
    public function forLines(array $lines): static
    {
        return $this->state(function (array $attributes) use ($lines) {
            $payload = $attributes['payload'];
            $payload['lines'] = [];
            $total = 0.0;

            foreach ($lines as [$product, $quantity]) {
                $payload['lines'][] = [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'price' => (float) $product->sale_price,
                ];

                $total += (float) $product->sale_price * $quantity;
            }

            $payload['client_uuid'] = $attributes['client_uuid'];
            $payload['claimed_total'] = round($total, 2);
            $payload['tenders'] = [['method' => 'cash', 'amount' => round($total, 2)]];

            return ['payload' => $payload];
        });
    }
}
