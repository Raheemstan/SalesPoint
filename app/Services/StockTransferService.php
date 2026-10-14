<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moving stock between shops.
 *
 * THE ONE RULE THIS SERVICE EXISTS TO ENFORCE
 *
 * Stock in transit is never counted in two places at once. It leaves the source
 * when the van is loaded and arrives at the destination when somebody there says
 * it did; for the hours in between it is on neither shelf. Everything below
 * follows from that:
 *
 *   - dispatch() takes the goods off the source's shelf immediately, so the
 *     source cannot sell what has already gone;
 *   - receive() is the only thing that puts them on the destination's shelf, so
 *     the destination cannot sell what has not arrived;
 *   - a transfer may be received exactly once, under a lock on its own row;
 *   - cancel() is a recall by the sender, and puts the goods back where they
 *     came from.
 *
 * HOW THE STOCK ACTUALLY MOVES, AND WHY IT LOOKS LIKE NOTHING SPECIAL
 *
 * By doing exactly what the till does: `$product->decrement('stock_quantity')`.
 * That is deliberate. App\Observers\ProductStockObserver mirrors every change to
 * the product total onto the acting branch, so a movement made while acting as
 * the source comes off the source's shelf, and one made while acting as the
 * destination lands on the destination's. Writing `branch_stock` directly here
 * would be a second way of moving stock, and the two would eventually disagree.
 *
 * Each half is therefore wrapped in BranchContext::actingAs(), which is the only
 * place in the app that says out loud which shelf a movement belongs to.
 *
 * WHO MAY DO WHAT, AND WHY IT IS ABOUT PLACE AND NOT ROLE
 *
 * You send from where you are and you receive where you are. A manager standing
 * in Ikeja cannot confirm that goods arrived in Lekki - nobody there has looked
 * in the box. So dispatch() requires the acting branch to be the source and
 * receive() requires it to be the destination; switch branch first (which is
 * what the header switcher is for). The role check is separate, and lives in
 * App\Policies\StockTransferPolicy.
 */
class StockTransferService
{
    public function __construct(private BranchContext $branches)
    {
    }

    /**
     * Raise a transfer and send it.
     *
     * Raising and dispatching are one step on purpose: a transfer that has been
     * written down but not sent is just a note, and a shop with a "pending"
     * state has to decide whether the stock is reserved - which is a promise
     * this app cannot keep, because the till will happily sell it.
     *
     * @param  array<int, int>  $quantities  product id => units to send
     *
     * @throws DomainException with a message written to be shown to the sender
     */
    public function dispatch(Branch $from, Branch $to, array $quantities, User $user, ?string $note = null): StockTransfer
    {
        if ($from->getKey() === $to->getKey()) {
            throw new DomainException('Choose a different branch to send the stock to.');
        }

        if (!$to->is_active) {
            throw new DomainException($to->name . ' is closed, so stock cannot be sent there.');
        }

        $this->assertActingAs($from, 'send stock from');

        // Drop the blank boxes a form posts for every product on the page.
        $wanted = collect($quantities)
            ->map(fn ($quantity) => (int) $quantity)
            ->filter(fn (int $quantity) => $quantity > 0);

        if ($wanted->isEmpty()) {
            throw new DomainException('Enter a quantity for at least one ' . strtolower(term('product')) . '.');
        }

        return DB::transaction(function () use ($from, $to, $wanted, $user, $note) {
            $transfer = StockTransfer::create([
                'reference' => $this->reference(),
                'from_branch_id' => $from->getKey(),
                'to_branch_id' => $to->getKey(),
                'status' => StockTransfer::STATUS_IN_TRANSIT,
                'created_by' => $user->getKey(),
                'dispatched_at' => now(),
                'note' => $note,
            ]);

            foreach ($wanted as $productId => $quantity) {
                // Locked for the same reason the till locks it: two people must
                // not both send the last box.
                $product = Product::lockForUpdate()->find((int) $productId);

                if (!$product) {
                    throw new DomainException('A ' . strtolower(term('product')) . ' on this transfer no longer exists.');
                }

                $this->assertTransferable($product);

                $available = BranchStock::quantity((int) $from->getKey(), (int) $product->getKey());

                // Over-sending refused: the point of the whole feature is that a
                // shelf figure means something.
                if ($quantity > $available) {
                    throw new DomainException(sprintf(
                        'Cannot send %d of %s: %s only has %d.',
                        $quantity,
                        $product->name,
                        $from->name,
                        $available
                    ));
                }

                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->getKey(),
                    'product_id' => $product->getKey(),
                    'quantity' => $quantity,
                    // Snapshot - see StockTransferItem.
                    'cost_price' => (float) $product->cost_price,
                ]);

                $this->moveStock($from, $product, -$quantity, $user, sprintf(
                    'Transfer %s out to %s',
                    $transfer->reference,
                    $to->name
                ));
            }

            AuditLog::record(
                "Dispatched stock transfer {$transfer->reference} from {$from->name} to {$to->name}",
                'stock_transfers',
                (int) $transfer->getKey()
            );

            return $transfer->load('items.product');
        });
    }

    /**
     * Confirm the goods arrived, and put them on the shelf.
     *
     * Received in full. A short delivery is deliberately *not* handled here: the
     * difference has to be written off somewhere, and the sanctioned way for
     * stock to disappear in this app is a stock adjustment with a reason and an
     * author (App\Services\StockAdjustmentService). Quietly receiving fewer than
     * were sent would make the shortfall vanish with nobody's name on it.
     *
     * @throws DomainException when it cannot be received
     */
    public function receive(StockTransfer $transfer, User $user): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user) {
            /*
             * Re-read under a lock, and check the status *here* rather than
             * before the transaction. This is what makes "received twice"
             * impossible rather than unlikely: two people tapping Receive at the
             * same moment both pass a check made outside a lock.
             */
            $locked = StockTransfer::lockForUpdate()->find($transfer->getKey());

            if (!$locked) {
                throw new DomainException('That transfer no longer exists.');
            }

            if ($locked->isReceived()) {
                throw new DomainException(
                    "Transfer {$locked->reference} was already received on "
                    . $locked->received_at?->format('d M Y H:i') . '.'
                );
            }

            if (!$locked->isInTransit()) {
                throw new DomainException("Transfer {$locked->reference} was cancelled, so it cannot be received.");
            }

            $to = $locked->toBranch;
            $from = $locked->fromBranch;

            $this->assertActingAs($to, 'receive stock at');

            foreach ($locked->items as $item) {
                $product = Product::withTrashed()->lockForUpdate()->find($item->product_id);

                if (!$product) {
                    // The goods are real even if the catalogue row has gone;
                    // there is simply nowhere to put the figure.
                    continue;
                }

                $this->moveStock($to, $product, $item->quantity, $user, sprintf(
                    'Transfer %s in from %s',
                    $locked->reference,
                    $from?->name ?? 'another branch'
                ));
            }

            $locked->forceFill([
                'status' => StockTransfer::STATUS_RECEIVED,
                'received_by' => $user->getKey(),
                'received_at' => now(),
            ])->save();

            AuditLog::record(
                "Received stock transfer {$locked->reference} at {$to?->name}",
                'stock_transfers',
                (int) $locked->getKey()
            );

            return $locked;
        });
    }

    /**
     * Recall a transfer that has not been received, putting the stock back.
     *
     * The sender's call, not the destination's: the goods are either still on the
     * van or back on the source's floor, and only the shop that sent them knows
     * which. A received transfer is history and cannot be undone - send one back
     * the other way instead.
     *
     * @throws DomainException when it cannot be cancelled
     */
    public function cancel(StockTransfer $transfer, User $user, ?string $reason = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user, $reason) {
            $locked = StockTransfer::lockForUpdate()->find($transfer->getKey());

            if (!$locked) {
                throw new DomainException('That transfer no longer exists.');
            }

            if ($locked->isReceived()) {
                throw new DomainException(
                    "Transfer {$locked->reference} has been received, so it cannot be cancelled. "
                    . 'Send the stock back the other way instead.'
                );
            }

            if ($locked->isCancelled()) {
                throw new DomainException("Transfer {$locked->reference} was already cancelled.");
            }

            $from = $locked->fromBranch;

            $this->assertActingAs($from, 'cancel a transfer from');

            foreach ($locked->items as $item) {
                $product = Product::withTrashed()->lockForUpdate()->find($item->product_id);

                if (!$product) {
                    continue;
                }

                $this->moveStock($from, $product, $item->quantity, $user, sprintf(
                    'Transfer %s cancelled, stock returned',
                    $locked->reference
                ));
            }

            $locked->forceFill([
                'status' => StockTransfer::STATUS_CANCELLED,
                'cancelled_by' => $user->getKey(),
                'cancelled_at' => now(),
                'note' => $reason ? Str::limit(trim($reason), 255, '') : $locked->note,
            ])->save();

            AuditLog::record(
                "Cancelled stock transfer {$locked->reference}",
                'stock_transfers',
                (int) $locked->getKey()
            );

            return $locked;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | What the screens need
    |--------------------------------------------------------------------------
    */

    /**
     * Products this branch has something of, with how many, for the send form.
     *
     * Only what is actually on the shelf: offering a product with none of it is
     * an invitation to type a number that will be refused.
     *
     * @return Collection<int, Product>
     */
    public function sendable(Branch $from, ?string $search = null, int $limit = 200): Collection
    {
        return Product::query()
            ->stocked()
            ->active()
            // The far branch's shelf, read deliberately: `branch_stock` is
            // branch-scoped, and this screen is the case the escape hatch is for.
            ->withBranchStock($from)
            ->when($search, fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', '%' . $search . '%')
                ->orWhere('sku', 'like', '%' . $search . '%')
                ->orWhere('barcode', 'like', '%' . $search . '%')))
            // Only what is really on the shelf. A WHERE EXISTS rather than a
            // HAVING on the subselect above: HAVING without a GROUP BY is not
            // something both SQLite and MySQL agree about.
            ->stockedAt($from)
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * Units of each product currently on a van to or from a branch.
     *
     * The answer to "the figures do not add up" - in-transit stock is counted on
     * no shelf at all, so it has to be visible somewhere.
     *
     * @return Collection<int, int>  product id => units in transit
     */
    public function inTransit(Branch $branch, bool $incoming = true): Collection
    {
        return DB::table('stock_transfer_items')
            ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_items.stock_transfer_id')
            ->where('stock_transfers.status', StockTransfer::STATUS_IN_TRANSIT)
            ->where(
                $incoming ? 'stock_transfers.to_branch_id' : 'stock_transfers.from_branch_id',
                $branch->getKey()
            )
            ->groupBy('stock_transfer_items.product_id')
            ->selectRaw('stock_transfer_items.product_id, SUM(stock_transfer_items.quantity) as units')
            ->pluck('units', 'product_id')
            ->map(fn ($units) => (int) $units);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * One signed stock movement at a named branch, with its inventory log.
     *
     * The branch is named rather than assumed: actingAs() is what makes the
     * observer put the movement on the right shelf, and what stamps the
     * inventory log with the right branch. Without it, receiving a transfer
     * would credit whichever branch the receiver's session happened to hold.
     */
    private function moveStock(Branch $branch, Product $product, int $delta, User $user, string $reason): void
    {
        $this->branches->actingAs($branch, function () use ($product, $delta, $user, $reason) {
            // The same primitive the till, purchases and refunds use. See the
            // class docblock.
            $delta > 0
                ? $product->increment('stock_quantity', $delta)
                : $product->decrement('stock_quantity', -$delta);

            InventoryLog::create([
                'product_id' => $product->getKey(),
                'user_id' => $user->getKey(),
                'type' => $delta > 0 ? 'IN' : 'OUT',
                'quantity' => abs($delta),
                // inventory_logs.reason is a plain varchar.
                'reason' => mb_substr($reason, 0, 255),
            ]);
        });
    }

    /**
     * You can only move stock where you are standing.
     *
     * @throws DomainException naming the branch they need to switch to
     */
    private function assertActingAs(?Branch $branch, string $action): void
    {
        if ($branch === null) {
            throw new DomainException('That branch no longer exists.');
        }

        if ($this->branches->id() !== $branch->getKey()) {
            throw new DomainException(sprintf(
                'Switch to %s to %s it.',
                $branch->name,
                $action
            ));
        }
    }

    /** @throws DomainException when this product cannot be on a van */
    private function assertTransferable(Product $product): void
    {
        if (!$product->tracksStock()) {
            throw new DomainException("{$product->name} has no stock level, so it cannot be transferred.");
        }

        // A style holds no stock of its own - its variants do. Sending the style
        // would move a figure that is always zero.
        if (!$product->isSellable()) {
            throw new DomainException("{$product->name} has variants; send the individual ones instead.");
        }
    }

    /**
     * A short reference staff can read down a phone.
     *
     * The same shape as an invoice number, and unique in the database, so a
     * collision is a failed insert rather than two transfers answering to one
     * name.
     */
    private function reference(): string
    {
        return 'TRF-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4));
    }
}
