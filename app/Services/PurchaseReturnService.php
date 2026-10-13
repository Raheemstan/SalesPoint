<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Sending goods back to a supplier: the mirror image of refunding a customer
 * (App\Services\RefundService), and built the same way for the same reasons.
 *
 * Stock goes OUT instead of IN, and the money reduces what the shop owes rather
 * than what it holds. The one rule worth stating twice: a line is credited at
 * the cost recorded on the purchase, never at the product's current cost. The
 * supplier refunds what they charged, and what they charged may have been last
 * season's price.
 */
class PurchaseReturnService
{
    /**
     * Send some or all of a purchase back.
     *
     * @param  array<int, int>  $quantities  purchase_item_id => quantity to return
     *
     * @throws DomainException with a user-facing message when the return isn't allowed
     */
    public function returnToSupplier(
        Purchase $purchase,
        array $quantities,
        string $reason,
        User $user,
        ?string $reference = null
    ): PurchaseReturn {
        return DB::transaction(function () use ($purchase, $quantities, $reason, $user, $reference) {
            // Lock the purchase so two managers can't send the same goods back twice.
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);

            if (!$purchase->isReturnable()) {
                throw new DomainException(
                    'Only a received purchase can be returned. Cancel the order instead if it never arrived.'
                );
            }

            $items = $purchase->purchaseItems()->lockForUpdate()->get()->keyBy('id');

            foreach (array_keys($quantities) as $itemId) {
                if (!$items->has($itemId)) {
                    throw new DomainException('One of the selected lines does not belong to this purchase.');
                }
            }

            $lines = [];
            $total = 0.0;

            foreach ($items as $item) {
                $qty = (int) ($quantities[$item->id] ?? 0);

                if ($qty <= 0) {
                    continue;
                }

                $left = $item->returnable_quantity;

                if ($qty > $left) {
                    $name = $item->product->name ?? 'item';
                    throw new DomainException("Cannot return {$qty} of {$name}: only {$left} of that line is left to return.");
                }

                $unitCost = round((float) $item->cost_price, 2);

                /*
                 * The last units of a line take whatever is left of its value, so
                 * returning a line in two goes credits back exactly what the line
                 * cost rather than leaving a penny behind.
                 */
                $amount = $qty === $left
                    ? round((float) $item->total - $item->returnedAmount(), 2)
                    : round($unitCost * $qty, 2);

                $amount = max(0, $amount);

                $lines[] = [$item, $qty, $unitCost, $amount];
                $total += $amount;
            }

            if (empty($lines)) {
                throw new DomainException('Select at least one line to return.');
            }

            $total = round($total, 2);

            $return = PurchaseReturn::create([
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'user_id' => $user->id,
                'reference' => $reference ?: $this->reference($purchase),
                'total_amount' => $total,
                'reason' => $reason,
            ]);

            foreach ($lines as [$item, $qty, $unitCost, $amount]) {
                // withTrashed: an archived product still needs its stock corrected.
                $product = Product::withTrashed()->lockForUpdate()->find($item->product_id);

                if (!$product) {
                    throw new DomainException('A product on this purchase no longer exists, so it cannot be returned.');
                }

                if ((int) $product->stock_quantity < $qty) {
                    throw new DomainException(
                        "Cannot return {$qty} of {$product->name}: only {$product->stock_quantity} left in stock, the rest has been sold."
                    );
                }

                PurchaseReturnItem::create([
                    'purchase_return_id' => $return->id,
                    'purchase_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'amount' => $amount,
                ]);

                $product->decrement('stock_quantity', $qty);

                InventoryLog::create([
                    'product_id' => $item->product_id,
                    'user_id' => $user->id,
                    'type' => 'OUT',
                    'quantity' => $qty,
                    'reason' => 'Returned to supplier ' . $purchase->invoice_number,
                ]);

                $item->increment('returned_quantity', $qty);
            }

            /*
             * The supplier's balance is derived (see SupplierLedgerService), so the
             * return reduces it the moment its rows exist. What has to be written
             * back is this invoice's own outstanding figure.
             */
            $purchase->balance_due = $purchase->outstanding();
            $purchase->save();

            AuditLog::record(
                'Returned ' . money($total) . ' of purchase #' . $purchase->invoice_number . ' to supplier',
                'purchase_returns',
                $return->id
            );

            return $return;
        });
    }

    /**
     * Returns are referred to by the purchase they came off, numbered in order:
     * the shopkeeper and the supplier are both looking at the same invoice.
     */
    private function reference(Purchase $purchase): string
    {
        $next = PurchaseReturn::where('purchase_id', $purchase->id)->count() + 1;

        return 'RTN-' . $purchase->invoice_number . '-' . $next;
    }
}
