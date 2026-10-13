<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Shift>
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->cashier(),
            'opened_at' => now(),
            'opening_float' => 5000,
            'status' => Shift::STATUS_OPEN,
        ];
    }

    /** An open shift: nothing counted, nothing expected yet. */
    public function open(): static
    {
        return $this->state(fn () => [
            'status' => Shift::STATUS_OPEN,
            'closed_at' => null,
            'closed_by' => null,
            'counted_cash' => null,
            'counted_pos' => null,
            'counted_transfer' => null,
        ]);
    }

    /**
     * A closed shift that balances: counted matches expected on every tender, and
     * cash is expected to hold at least the float it opened with.
     *
     * Pass ['cash' => 7500, ...] for other expected figures, or override the
     * counted_* columns on create() to produce a variance on purpose.
     *
     * The per-attribute closures matter: they are evaluated after create()'s own
     * overrides are merged in, so passing user_id or opening_float here is picked up.
     */
    public function closed(array $expected = []): static
    {
        $figure = fn (string $method, array $attributes) => round(
            (float) ($expected[$method] ?? ($method === 'cash' ? ($attributes['opening_float'] ?? 0) : 0)),
            2
        );

        return $this->state(fn () => [
            'status' => Shift::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => fn (array $attributes) => $attributes['user_id'],
            'expected_cash' => fn (array $attributes) => $figure('cash', $attributes),
            'expected_pos' => fn (array $attributes) => $figure('pos', $attributes),
            'expected_transfer' => fn (array $attributes) => $figure('transfer', $attributes),
            'counted_cash' => fn (array $attributes) => $figure('cash', $attributes),
            'counted_pos' => fn (array $attributes) => $figure('pos', $attributes),
            'counted_transfer' => fn (array $attributes) => $figure('transfer', $attributes),
        ]);
    }
}
