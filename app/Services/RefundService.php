<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Sale;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class RefundService
{
    /**
     * Refund some or all of a sale.
     *
     * @param array<int, int>  $quantities sale_item_id => quantity to refund
     * @param array<int, bool> $restock    sale_item_id => put back into stock? (default true)
     *
     * @throws DomainException with a user-facing message when the refund isn't allowed
     */
    public function refund(Sale $sale, array $quantities, array $restock, string $reason, string $method, User $user): Refund
    {
        return $this->process($sale, $quantities, $restock, $reason, $method, $user, false);
    }

    /**
     * Void a sale: everything goes back, the sale is marked voided.
     * Only possible while nothing has been refunded yet; otherwise refund what is left.
     */
    public function void(Sale $sale, string $reason, string $method, User $user): Refund
    {
        return $this->process($sale, [], [], $reason, $method, $user, true);
    }

    private function process(Sale $sale, array $quantities, array $restock, string $reason, string $method, User $user, bool $void): Refund
    {
        return DB::transaction(function () use ($sale, $quantities, $restock, $reason, $method, $user, $void) {
            // Lock the sale so two managers can't refund the same items at once.
            $sale = Sale::lockForUpdate()->findOrFail($sale->id);

            if (!$sale->isRefundable()) {
                throw new DomainException('This sale has already been fully refunded or voided.');
            }

            if ($void && $sale->status !== Sale::STATUS_COMPLETED) {
                throw new DomainException('Only a sale with no refunds can be voided. Refund the remaining items instead.');
            }

            $items = $sale->items()->lockForUpdate()->get()->keyBy('id');

            if ($void) {
                $quantities = $items->map(fn($i) => $i->refundable_quantity)->all();
                $restock = $items->map(fn() => true)->all();
            }

            foreach (array_keys($quantities) as $itemId) {
                if (!$items->has($itemId)) {
                    throw new DomainException('One of the selected items does not belong to this sale.');
                }
            }

            $lines = [];
            $total = 0.0;
            $tax = 0.0;

            foreach ($items as $item) {
                $qty = (int) ($quantities[$item->id] ?? 0);

                if ($qty <= 0) {
                    continue;
                }

                if ($qty > $item->refundable_quantity) {
                    $name = $item->product->name ?? 'item';
                    throw new DomainException("Cannot refund {$qty} of {$name}: only {$item->refundable_quantity} left to refund.");
                }

                $final = $qty === $item->refundable_quantity;
                $taxAlready = (float) RefundItem::where('sale_item_id', $item->id)->sum('tax_amount');

                // The last unit(s) of a line take whatever is left, so rounding never leaves pennies behind.
                $amount = $final
                    ? round((float) $item->paid_total - (float) $item->refunded_amount, 2)
                    : round((float) $item->paid_total * $qty / $item->quantity, 2);
                $lineTax = $final
                    ? round((float) $item->tax_amount - $taxAlready, 2)
                    : round((float) $item->tax_amount * $qty / $item->quantity, 2);

                $lines[] = [$item, $qty, max(0, $amount), max(0, $lineTax), (bool) ($restock[$item->id] ?? true)];
                $total += max(0, $amount);
                $tax += max(0, $lineTax);
            }

            if (empty($lines)) {
                throw new DomainException('Select at least one item to refund.');
            }

            $total = round($total, 2);

            /*
             * On a sale that was taken on credit, returning goods cancels the debt
             * before it releases any cash: owe 600 of a 1,000 invoice, bring back
             * 300 of goods, and you simply owe 300 - nothing leaves the drawer.
             * Only the excess over what is still owed is actually paid out.
             */
            $creditApplied = app(CreditService::class)->applyRefund($sale, $total);

            $refund = Refund::create([
                'sale_id' => $sale->id,
                'user_id' => $user->id,
                // Stamp the drawer the money left, so end-of-day reconciliation can
                // tell a refund paid out today from one against an older sale.
                'shift_id' => app(ShiftService::class)->currentFor($user)?->id,
                'total_amount' => $total,
                'credit_applied' => $creditApplied,
                'tax_amount' => round($tax, 2),
                'method' => $method,
                'reason' => $reason,
                'is_void' => $void,
            ]);

            foreach ($lines as [$item, $qty, $amount, $lineTax, $restocked]) {
                RefundItem::create([
                    'refund_id' => $refund->id,
                    'sale_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $qty,
                    'amount' => $amount,
                    'tax_amount' => $lineTax,
                    'cost_price' => $item->cost_price,
                    'restocked' => $restocked,
                ]);

                $item->increment('refunded_quantity', $qty);
                $item->increment('refunded_amount', $amount);

                if ($restocked) {
                    // withTrashed: an archived product still needs its stock corrected.
                    Product::withTrashed()->lockForUpdate()->find($item->product_id)?->increment('stock_quantity', $qty);

                    InventoryLog::create([
                        'product_id' => $item->product_id,
                        'user_id' => $user->id,
                        'type' => 'IN',
                        'quantity' => $qty,
                        'reason' => ($void ? 'Void ' : 'Refund ') . $sale->invoice_number,
                    ]);
                }
            }

            $sale->refunded_amount = round((float) $sale->refunded_amount + $total, 2);

            $allBack = $sale->items()->whereColumn('refunded_quantity', '<', 'quantity')->doesntExist();

            if ($void) {
                $sale->status = Sale::STATUS_VOIDED;
                $sale->voided_at = now();
                $sale->voided_by = $user->id;
                $sale->void_reason = $reason;
            } else {
                $sale->status = $allBack ? Sale::STATUS_REFUNDED : Sale::STATUS_PARTIAL;
            }

            $sale->save();

            AuditLog::record(($void ? 'Voided sale ' : 'Refunded sale ') . $sale->invoice_number, 'sales', $sale->id);

            return $refund;
        });
    }
}
