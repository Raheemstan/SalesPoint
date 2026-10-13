<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockTake;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * One stock correction at a time, with a reason attached.
 *
 * This is the only sanctioned way to change a stock figure by hand (the product
 * form's stock field is for opening stock). Every call leaves three trails: the
 * adjustment itself (what and why), an inventory log (the movement, alongside
 * sales and purchases) and an audit log (who did it, from where).
 */
class StockAdjustmentService
{
    /**
     * Apply one adjustment.
     *
     * @param  string  $type      StockAdjustment::TYPE_INCREASE|TYPE_DECREASE
     * @param  int     $quantity  how many units to move; always positive
     *
     * @throws DomainException with a user-facing message when the move isn't allowed
     */
    public function adjust(
        Product $product,
        string $type,
        int $quantity,
        string $reason,
        ?string $note,
        User $user,
        ?StockTake $stockTake = null
    ): StockAdjustment {
        // Whitelist here as well as in the controller: a service is also called
        // from tests, commands and other services, which do no validation.
        if (!in_array($type, StockAdjustment::TYPES, true)) {
            throw new DomainException('Choose whether the stock goes up or down.');
        }

        if (!in_array($reason, StockAdjustment::REASONS, true)) {
            throw new DomainException('Choose a reason for the adjustment.');
        }

        if ($quantity < 1) {
            throw new DomainException('Enter a quantity of at least 1.');
        }

        return DB::transaction(function () use ($product, $type, $quantity, $reason, $note, $user, $stockTake) {
            // Re-read under a lock: the figure in $product may be seconds old, and
            // the "would this go below zero?" test has to run against the real one.
            $locked = Product::withTrashed()->lockForUpdate()->find($product->id);

            if (!$locked) {
                throw new DomainException('That item no longer exists.');
            }

            // A service (labour, a delivery fee) has no stock to adjust, and an
            // item with tracking switched off is deliberately not being counted.
            if (!$locked->tracksStock()) {
                throw new DomainException("{$locked->name} does not track stock, so it cannot be adjusted.");
            }

            $delta = $type === StockAdjustment::TYPE_DECREASE ? -$quantity : $quantity;

            if ((int) $locked->stock_quantity + $delta < 0) {
                throw new DomainException(
                    "Cannot remove {$quantity} from {$locked->name}: only {$locked->stock_quantity} in stock."
                );
            }

            $adjustment = $this->record($locked, $delta, $reason, $note, $user, $stockTake, (float) $locked->cost_price);

            AuditLog::record(
                ucfirst($type) . " stock of {$locked->name} by {$quantity} ({$reason})",
                'stock_adjustments',
                $adjustment->id
            );

            return $adjustment;
        });
    }

    /**
     * Write the rows for a stock move and update the product.
     *
     * Shared with StockTakeService::approve(), which has already locked the
     * product row, decided the delta and taken its own view of what the stock
     * cost - so this method trusts its caller and only does the writing. Call
     * it from inside a transaction, with the product row locked.
     *
     * @param  int  $delta  signed change; 0 writes nothing and returns null
     */
    public function record(
        Product $locked,
        int $delta,
        string $reason,
        ?string $note,
        User $user,
        ?StockTake $stockTake,
        float $costPrice
    ): ?StockAdjustment {
        if ($delta === 0) {
            return null;
        }

        $adjustment = StockAdjustment::create([
            'product_id' => $locked->id,
            'user_id' => $user->id,
            'type' => $delta > 0 ? StockAdjustment::TYPE_INCREASE : StockAdjustment::TYPE_DECREASE,
            'quantity' => abs($delta),
            'reason' => $reason,
            'note' => $note,
            'cost_price' => round($costPrice, 2),
            'stock_take_id' => $stockTake?->id,
        ]);

        $locked->stock_quantity = (int) $locked->stock_quantity + $delta;
        $locked->save();

        InventoryLog::create([
            'product_id' => $locked->id,
            'user_id' => $user->id,
            'type' => $delta > 0 ? 'IN' : 'OUT',
            'quantity' => abs($delta),
            // inventory_logs.reason is a plain varchar, so trim rather than risk
            // a database error on a long note.
            'reason' => mb_substr($stockTake
                ? "Stock-take {$stockTake->reference}"
                : 'Adjustment: ' . $reason . ($note ? ' - ' . $note : ''), 0, 255),
        ]);

        return $adjustment;
    }
}
