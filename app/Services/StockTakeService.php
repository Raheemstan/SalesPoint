<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Counted stock-takes: build a sheet, write counts on it, and reconcile it.
 *
 * The sheet is a frozen picture, not a live view. Each line keeps the quantity
 * the system believed when the sheet was created and the cost the stock was
 * carried at, so the value of a loss cannot move afterwards because somebody
 * edited a product.
 */
class StockTakeService
{
    public function __construct(private StockAdjustmentService $adjustments) {}

    /**
     * Build a draft sheet from current stock.
     *
     * Only stocked, active items go on it: a service has no stock to count, an
     * item with tracking switched off is deliberately not counted, and an
     * archived item is not on the shelf any more.
     *
     * @param  array{category_id?: int|null, supplier_id?: int|null, note?: string|null}  $filters
     *
     * @throws DomainException when the filters match nothing countable
     */
    public function createSheet(User $user, array $filters = []): StockTake
    {
        return DB::transaction(function () use ($user, $filters) {
            $products = Product::stocked()
                ->active()
                ->when(!empty($filters['category_id']), fn($q) => $q->where('category_id', $filters['category_id']))
                ->when(!empty($filters['supplier_id']), fn($q) => $q->where('supplier_id', $filters['supplier_id']))
                ->orderBy('name')
                ->get();

            if ($products->isEmpty()) {
                throw new DomainException('No countable items match that selection.');
            }

            $stockTake = StockTake::create([
                'reference' => $this->nextReference(),
                'user_id' => $user->id,
                'status' => StockTake::STATUS_DRAFT,
                'note' => $filters['note'] ?? null,
            ]);

            // One insert for the whole sheet: a shop with a few thousand lines
            // should not pay for a few thousand round trips.
            $now = now();
            $stockTake->items()->insert($products->map(fn(Product $product) => [
                'stock_take_id' => $stockTake->id,
                'product_id' => $product->id,
                'system_quantity' => (int) $product->stock_quantity,
                'counted_quantity' => null,
                'variance' => 0,
                'cost_price' => round((float) $product->cost_price, 2),
                'note' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            AuditLog::record("Started stock-take {$stockTake->reference} ({$products->count()} lines)", 'stock_takes', $stockTake->id);

            return $stockTake;
        });
    }

    /**
     * Save the counts typed onto a sheet.
     *
     * @param  array<int, int|string|null>  $counts  stock_take_item_id => counted quantity ('' / null = not counted)
     * @param  array<int, string|null>      $notes   stock_take_item_id => line note
     *
     * @throws DomainException when the sheet is already approved or cancelled
     */
    public function saveCounts(StockTake $stockTake, array $counts, array $notes = [], bool $submit = false): StockTake
    {
        if (!$stockTake->isEditable()) {
            throw new DomainException('This stock-take can no longer be changed.');
        }

        DB::transaction(function () use ($stockTake, $counts, $notes, $submit) {
            // Only touch lines that belong to this sheet: the ids come from a form.
            $items = $stockTake->items()->get()->keyBy('id');

            foreach ($items as $item) {
                if (!array_key_exists($item->id, $counts) && !array_key_exists($item->id, $notes)) {
                    continue;
                }

                $raw = $counts[$item->id] ?? null;
                $counted = ($raw === null || $raw === '') ? null : (int) $raw;

                $item->counted_quantity = $counted;
                // Variance is derived, never taken from the browser.
                $item->variance = $counted === null ? 0 : $counted - (int) $item->system_quantity;
                $item->note = ($notes[$item->id] ?? null) ?: null;
                $item->save();
            }

            $stockTake->counted_at = now();

            if ($submit) {
                $stockTake->status = StockTake::STATUS_SUBMITTED;
            }

            $stockTake->save();
        });

        return $stockTake->refresh();
    }

    /**
     * Approve a sheet and move the stock.
     *
     * HOW THE STOCK IS MOVED - the one decision that matters here.
     *
     * Stock keeps moving while a count is in progress: the sheet says the system
     * believed 10, staff counted 8, and in between a customer bought 3. There are
     * two ways to settle that:
     *
     *   a) set the stock to the counted figure (8) - which silently undoes the
     *      sale, because those 3 units come back from nowhere;
     *   b) apply the variance as a delta (8 - 10 = -2) to whatever the stock is
     *      *now* (7), giving 5.
     *
     * We do (b). The variance is the error the count discovered; sales, purchases
     * and refunds that happened afterwards are real movements that have their own
     * logs, and approving a count must not reverse them. For the ordinary case -
     * nothing moved between counting and approving - (a) and (b) give exactly the
     * same answer, so nothing is lost by being careful.
     *
     * If the delta would push the stock below zero (the count said 2, then 5 were
     * sold) we stop at zero and record the delta we actually applied, so the logs
     * always add up to the stock column.
     *
     * @throws DomainException when the sheet is already approved or cancelled
     */
    public function approve(StockTake $stockTake, User $approver): StockTake
    {
        return DB::transaction(function () use ($stockTake, $approver) {
            // Lock and re-read the sheet: two managers hitting approve at the same
            // moment must not both get through and double the correction.
            $sheet = StockTake::lockForUpdate()->find($stockTake->id);

            if (!$sheet) {
                throw new DomainException('That stock-take no longer exists.');
            }

            if ($sheet->isApproved()) {
                throw new DomainException('This stock-take has already been approved.');
            }

            if ($sheet->status === StockTake::STATUS_CANCELLED) {
                throw new DomainException('This stock-take was cancelled.');
            }

            $items = $sheet->items()->whereNotNull('counted_quantity')->get();

            if ($items->isEmpty()) {
                throw new DomainException('Nothing has been counted on this stock-take yet.');
            }

            $moved = 0;

            foreach ($items as $item) {
                if ($item->variance === 0) {
                    continue; // counted and correct: nothing to move, nothing to log
                }

                $product = Product::withTrashed()->lockForUpdate()->find($item->product_id);

                if (!$product || !$product->tracksStock()) {
                    // The item was archived or turned into a service mid-count.
                    continue;
                }

                // (b) above: the variance as a delta, clamped at zero.
                $delta = max($item->variance, -(int) $product->stock_quantity);

                $this->adjustments->record(
                    $product,
                    $delta,
                    'count',
                    $item->note,
                    $approver,
                    $sheet,
                    (float) $item->cost_price   // the cost the stock was carried at when counted
                );

                $moved++;
            }

            $sheet->status = StockTake::STATUS_APPROVED;
            $sheet->approved_by = $approver->id;
            $sheet->approved_at = now();
            $sheet->save();

            AuditLog::record(
                "Approved stock-take {$sheet->reference}: {$moved} item(s) corrected, variance " . money($sheet->varianceValue(), false),
                'stock_takes',
                $sheet->id
            );

            return $sheet;
        });
    }

    /**
     * Cancel a sheet. Nothing has moved, so there is nothing to put back - but
     * the sheet is kept, because "we counted and then abandoned it" is itself
     * worth knowing.
     *
     * @throws DomainException when the sheet is already approved
     */
    public function cancel(StockTake $stockTake): StockTake
    {
        if ($stockTake->isApproved()) {
            throw new DomainException('An approved stock-take cannot be cancelled; adjust the stock instead.');
        }

        $stockTake->update(['status' => StockTake::STATUS_CANCELLED]);

        AuditLog::record("Cancelled stock-take {$stockTake->reference}", 'stock_takes', $stockTake->id);

        return $stockTake;
    }

    /**
     * The headline figures for a sheet, valued at the snapshot cost.
     *
     * `variance_value` is negative when stock is missing: that is what the
     * shrinkage cost the shop.
     *
     * @return array{lines: int, counted: int, variance_units: int, short_lines: int, over_lines: int, variance_value: float, shrinkage_value: float}
     */
    public function summary(StockTake $stockTake): array
    {
        $items = $stockTake->relationLoaded('items') ? $stockTake->items : $stockTake->items()->get();
        $counted = $items->filter(fn(StockTakeItem $item) => $item->isCounted());

        return [
            'lines' => $items->count(),
            'counted' => $counted->count(),
            'variance_units' => (int) $counted->sum('variance'),
            'short_lines' => $counted->where('variance', '<', 0)->count(),
            'over_lines' => $counted->where('variance', '>', 0)->count(),
            'variance_value' => round((float) $counted->sum(fn(StockTakeItem $item) => $item->variance_value), 2),
            // Just the losses, which is the number a manager is actually asked about.
            'shrinkage_value' => round((float) $counted->where('variance', '<', 0)
                ->sum(fn(StockTakeItem $item) => $item->variance_value), 2),
        ];
    }

    /**
     * A readable, unique reference: ST-20261013-001.
     *
     * The column is unique, so on the (unlikely) clash of two sheets started in
     * the same instant we fall back to a random suffix rather than failing.
     */
    private function nextReference(): string
    {
        $prefix = 'ST-' . now()->format('Ymd');
        $sequence = StockTake::where('reference', 'like', $prefix . '%')->count() + 1;
        $reference = $prefix . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);

        while (StockTake::where('reference', $reference)->exists()) {
            $reference = $prefix . '-' . Str::upper(Str::random(4));
        }

        return $reference;
    }
}
