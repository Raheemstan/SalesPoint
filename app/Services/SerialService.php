<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemSerial;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Serial / IMEI capture.
 *
 * An electronics shop sells units that are individually identifiable, and is
 * asked months later "when did this handset go out, and to whom?". So each unit
 * sold gets a row against its sale line (see SaleItemSerial).
 *
 * Everything that writes serials comes through here - the post-sale entry screen
 * today, the till when it is ready - so that the two rules that matter are
 * enforced in one place:
 *
 *   1. a line of 3 phones has exactly 3 serials, not 2 and not 4;
 *   2. a serial belongs to one physical unit, so the same one cannot be recorded
 *      against the same product twice, in this sale or any other.
 *
 * Rule 2 is also a unique index, because a race between two tills would slip
 * past any check made in PHP.
 */
class SerialService
{
    /**
     * Record the serials for one sale line, replacing whatever was there.
     *
     * Replacing rather than adding is deliberate: the realistic correction is
     * "the second IMEI was typed wrong", and the screen posts the whole line, so
     * the line's own serials are cleared and rewritten inside one transaction.
     * A serial that was already on this line may therefore be re-used by it.
     *
     * @param  array<int, string|null>  $serials  one per unit; blanks are dropped
     * @return Collection<int, SaleItemSerial>
     *
     * @throws DomainException when the count does not match the line, a serial is
     *                         repeated, or a serial is already on another sale
     */
    public function record(SaleItem $item, array $serials): Collection
    {
        $values = $this->clean($serials);
        $quantity = (int) $item->quantity;

        if (count($values) !== $quantity) {
            throw new DomainException(
                "This line is for {$quantity} unit(s), so it needs {$quantity} serial number(s); " .
                count($values) . ' given.'
            );
        }

        $duplicate = $this->firstRepeat($values);

        if ($duplicate !== null) {
            throw new DomainException("Serial \"{$duplicate}\" is entered twice on the same line.");
        }

        return DB::transaction(function () use ($item, $values) {
            // Lock the serials already recorded for this product so two people
            // cannot each be told their serial is free and then both save it.
            $clash = SaleItemSerial::forProduct($item->product_id)
                ->whereIn('serial', $values)
                ->where('sale_item_id', '!=', $item->id)
                ->lockForUpdate()
                ->first();

            if ($clash) {
                throw new DomainException($this->clashMessage($clash));
            }

            $item->serials()->delete();

            try {
                $rows = collect($values)->map(fn(string $serial) => $item->serials()->create([
                    'product_id' => $item->product_id,
                    'serial' => $serial,
                ]));
            } catch (UniqueConstraintViolationException $e) {
                // The (product_id, serial) index had the last word: another till
                // recorded one of these between the check above and this write.
                throw new DomainException(
                    'One of those serials was recorded against this item a moment ago. Reload and check the numbers.'
                );
            }

            AuditLog::record(
                "Recorded {$rows->count()} serial(s) for sale line {$item->id}",
                'sale_item_serials',
                $item->id
            );

            return $rows;
        });
    }

    /**
     * Forget a line's serials. Its own method rather than record() with an empty
     * array, because clearing a record on purpose and failing to fill one in are
     * different things and only one of them should be easy to do by accident.
     */
    public function clear(SaleItem $item): int
    {
        $removed = $item->serials()->delete();

        if ($removed) {
            AuditLog::record("Cleared {$removed} serial(s) from sale line {$item->id}", 'sale_item_serials', $item->id);
        }

        return (int) $removed;
    }

    /**
     * Record serials for a whole sale in one go - the entry point the till will
     * call at checkout, and the one the post-sale screen uses now.
     *
     * Lines are keyed by sale_item_id. A line that is absent is left as it is; a
     * line whose boxes were all left blank is cleared. All of it is one
     * transaction, so a sale cannot end up half-recorded.
     *
     * @param  array<int|string, array<int, string|null>>  $byItem  sale_item_id => serials
     * @return int how many serials are recorded against the sale afterwards
     *
     * @throws DomainException on the first line that does not add up
     */
    public function recordForSale(Sale $sale, array $byItem): int
    {
        return DB::transaction(function () use ($sale, $byItem) {
            // Only lines of *this* sale: the ids arrive from a form.
            $items = $sale->items()->get()->keyBy('id');

            foreach ($byItem as $itemId => $serials) {
                $item = $items->get((int) $itemId);

                if (!$item) {
                    continue;
                }

                $values = $this->clean(is_array($serials) ? $serials : [$serials]);

                if (empty($values)) {
                    $this->clear($item);
                    continue;
                }

                $this->record($item, $values);
            }

            return (int) SaleItemSerial::whereIn('sale_item_id', $items->keys())->count();
        });
    }

    /**
     * Which lines of this sale still owe serials, as sale_item_id => how many.
     *
     * Only lines whose product asks for them (Product::requiresSerials()), so a
     * shop that records IMEIs on phones is not nagged about phone cases.
     *
     * @return array<int, int>
     */
    public function missingFor(Sale $sale): array
    {
        $items = $sale->relationLoaded('items') ? $sale->items : $sale->items()->with('product')->get();
        $missing = [];

        foreach ($items as $item) {
            if ($item->requiresSerials() && $item->missingSerials() > 0) {
                $missing[$item->id] = $item->missingSerials();
            }
        }

        return $missing;
    }

    /**
     * Which sale did this serial go out on? The warranty question, and the reason
     * any of this is recorded.
     *
     * @return Collection<int, SaleItemSerial> one row per product that has used
     *         this serial (normally exactly one)
     */
    public function lookup(?string $serial): Collection
    {
        if (trim((string) $serial) === '') {
            return collect();
        }

        return SaleItemSerial::with(['product', 'saleItem.sale.user', 'saleItem.sale.customer'])
            ->matching($serial)
            ->get();
    }

    /** Is this serial free to be recorded against this product? */
    public function isAvailable(Product $product, ?string $serial, ?SaleItem $exceptLine = null): bool
    {
        $value = SaleItemSerial::normalise($serial);

        if ($value === '') {
            return false;
        }

        return !SaleItemSerial::forProduct($product->id)
            ->matching($value)
            ->when($exceptLine, fn($q) => $q->where('sale_item_id', '!=', $exceptLine->id))
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Normalise and drop blanks. The screen posts one empty box per unit when
     * nothing has been entered yet, and an empty box is not a serial.
     *
     * @param  array<int, string|null>  $serials
     * @return array<int, string>
     */
    private function clean(array $serials): array
    {
        return collect($serials)
            ->map(fn($serial) => SaleItemSerial::normalise(is_string($serial) ? $serial : (string) $serial))
            ->filter(fn(string $serial) => $serial !== '')
            ->values()
            ->all();
    }

    /** The first value that appears twice, or null. */
    private function firstRepeat(array $values): ?string
    {
        $seen = [];

        foreach ($values as $value) {
            if (isset($seen[$value])) {
                return $value;
            }

            $seen[$value] = true;
        }

        return null;
    }

    private function clashMessage(SaleItemSerial $clash): string
    {
        $invoice = $clash->saleItem?->sale?->invoice_number;

        return "Serial \"{$clash->serial}\" has already been sold" .
            ($invoice ? " on {$invoice}" : '') .
            '. Each unit can only go out once; check the number.';
    }
}
