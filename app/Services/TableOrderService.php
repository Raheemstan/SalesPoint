<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Table;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The rules of table service.
 *
 * Everything that decides what a table order may do lives here, so the
 * controllers stay thin and the rules can be unit-tested without a browser.
 *
 * Three things are worth knowing before reading on:
 *
 * 1. NO STOCK MOVES HERE. An order is a list of intentions, not a sale. Stock
 *    comes off when the till rings the order up, which is why a cancelled order
 *    costs the records nothing and why there are no InventoryLog writes in this
 *    file. It is also the reason settling goes *through* the till rather than
 *    around it: the till is where the stock check and the lock already live.
 *
 * 2. PRICES ARE SNAPSHOTS. A line stores the price at the moment it was ordered
 *    and that figure is never refreshed from the product. Re-pricing the menu
 *    during service must not change a bill that guests already agreed to.
 *
 * 3. MONEY MATHS IS BORROWED. Order totals go through SaleCalculator, the same
 *    one the till uses, so a table bill and its eventual receipt can never
 *    disagree about tax. The one sum this file does itself is the service
 *    charge, which is an addition rather than a discount and so has no home in
 *    SaleCalculator (see serviceCharge(), unit-tested).
 */
class TableOrderService
{
    /**
     * Open an order on a table.
     *
     * Locked: two servers tapping the same empty table at the same moment must
     * not produce two orders on it, which would then both collect food and only
     * one of them get paid.
     *
     * @throws DomainException when the table cannot be seated
     */
    public function open(Table $table, User $user, int $guests, ?int $customerId = null, ?string $note = null): TableOrder
    {
        if ($guests < 1) {
            throw new DomainException('An order needs at least one guest.');
        }

        return DB::transaction(function () use ($table, $user, $guests, $customerId, $note) {
            $locked = Table::lockForUpdate()->find($table->id);

            if (!$locked) {
                throw new DomainException('That table no longer exists.');
            }

            if (!$locked->is_active) {
                throw new DomainException("{$locked->name} is out of service.");
            }

            if (TableOrder::open()->where('table_id', $locked->id)->exists()) {
                throw new DomainException("{$locked->name} already has an open order. Open that one instead.");
            }

            $order = TableOrder::create([
                'reference' => $this->nextReference(),
                'table_id' => $locked->id,
                'user_id' => $user->id,
                'customer_id' => $customerId,
                'status' => TableOrder::STATUS_OPEN,
                'guests' => $guests,
                'note' => $note,
                'opened_at' => now(),
            ]);

            AuditLog::record(
                "Opened {$order->reference} on {$locked->name} for {$guests} guest(s)",
                'table_orders',
                $order->id
            );

            return $order;
        });
    }

    /**
     * Add something to the order, at today's price, frozen.
     *
     * An identical line that the kitchen has not seen yet is merged instead of
     * repeated - a server tapping "Coke" three times means three Cokes on one
     * line, not three lines. The merge only happens when the note, the course
     * *and* the price all match, so neither a special request nor a mid-service
     * re-price can be silently folded into an older line.
     *
     * @throws DomainException when the order is closed or the item unsellable
     */
    public function addItem(
        TableOrder $order,
        Product $product,
        int $quantity,
        ?string $note = null,
        ?string $course = null
    ): TableOrderItem {
        $this->assertOpen($order);
        $course = $this->assertCourse($course);

        if ($quantity < 1) {
            throw new DomainException('Enter a quantity of at least 1.');
        }

        return DB::transaction(function () use ($order, $product, $quantity, $note, $course) {
            /*
             * Re-read under a lock rather than trusting the instance handed in.
             * The caller's copy may be seconds old, and this is the moment the
             * price is frozen onto the line - it has to be the price that is
             * really in the database now, not the one that was on the screen
             * when the server started tapping.
             */
            $locked = Product::lockForUpdate()->find($product->id);

            if (!$locked) {
                throw new DomainException('That item is no longer on the menu.');
            }

            // Null means the column was never written, which the schema default
            // says is active; only an explicit false takes an item off the menu.
            if (($locked->is_active ?? true) === false) {
                throw new DomainException("{$locked->name} is not on the menu.");
            }

            $price = round((float) $locked->sale_price, 2);

            $existing = $order->items()
                ->whereNull('sent_to_kitchen_at')
                ->where('product_id', $locked->id)
                ->where('note', $note)
                ->where('course', $course)
                ->where('price', $price)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->increment('quantity', $quantity);

                return $existing->refresh();
            }

            return $order->items()->create([
                'product_id' => $locked->id,
                'quantity' => $quantity,
                'price' => $price,
                'note' => $note,
                'course' => $course,
            ]);
        });
    }

    /**
     * Change a line that has not gone to the kitchen.
     *
     * DECISION: a line the kitchen has already seen cannot be edited at all.
     *
     * Raising its quantity would put food on the bill that nobody is cooking;
     * lowering it would quietly drop food that has already been made, and
     * rewriting its note is meaningless once the ticket has been printed. The
     * only honest way to change a sent line is to remove it with a reason, which
     * leaves a record that something was cooked and not sold (see removeItem).
     * To reduce a sent line, remove it and add the smaller quantity back - that
     * re-sends to the kitchen, which is correct: it is new information for them.
     *
     * @throws DomainException when the line is locked or the order is closed
     */
    public function updateItem(TableOrderItem $item, int $quantity, ?string $note = null, ?string $course = null): TableOrderItem
    {
        $this->assertOpen($item->order);
        $course = $this->assertCourse($course);

        if ($item->isSent()) {
            throw new DomainException(
                'That has already gone to the kitchen, so it cannot be changed. Remove it with a reason instead.'
            );
        }

        if ($quantity < 1) {
            throw new DomainException('Enter a quantity of at least 1.');
        }

        $item->update([
            'quantity' => $quantity,
            'note' => $note,
            'course' => $course,
        ]);

        return $item;
    }

    /**
     * Take a line off the order.
     *
     * An unsent line is a typo: it disappears with no ceremony. A sent line is a
     * plate of food, so it needs a reason and leaves an audit entry - that entry
     * is the only trace that the kitchen made something the shop never sold.
     *
     * @throws DomainException when a sent line is removed without a reason
     */
    public function removeItem(TableOrderItem $item, ?string $reason): void
    {
        $this->assertOpen($item->order);

        $reason = trim((string) $reason);

        if ($item->isSent()) {
            if ($reason === '') {
                throw new DomainException('Say why the kitchen-sent item is being removed - the food has been made.');
            }

            $name = $item->product->name ?? 'item';

            AuditLog::record(
                "Removed kitchen-sent {$item->quantity} x {$name} from {$item->order->reference}: {$reason}",
                'table_order_items',
                $item->id
            );
        }

        $item->delete();
    }

    /**
     * Stamp the not-yet-sent lines and hand them back for the prep ticket.
     *
     * Only the new lines are stamped and only the new lines are returned: the
     * kitchen must never be told twice to cook the same plate, so a second send
     * with nothing new produces nothing at all.
     *
     * @return Collection<int, TableOrderItem> the lines this send covers, empty when there were none
     */
    public function sendToKitchen(TableOrder $order): Collection
    {
        $this->assertOpen($order);

        return DB::transaction(function () use ($order) {
            $pending = $order->pendingItems()->with('product')->lockForUpdate()->get();

            if ($pending->isEmpty()) {
                return $pending;
            }

            $sentAt = now();
            TableOrderItem::whereIn('id', $pending->pluck('id'))->update(['sent_to_kitchen_at' => $sentAt]);

            AuditLog::record(
                "Sent {$pending->count()} line(s) of {$order->reference} to the kitchen",
                'table_orders',
                $order->id
            );

            // Hand back the in-memory copies with the stamp applied, so the ticket
            // can print the time without another query.
            return $pending->each(fn (TableOrderItem $item) => $item->sent_to_kitchen_at = $sentAt);
        });
    }

    /**
     * Move an order to another free table.
     *
     * Guests change tables constantly - a two becomes a four, a terrace table
     * gets rained on. Both tables are locked so a transfer cannot land on a table
     * somebody else is seating at the same instant.
     *
     * @throws DomainException when the destination cannot take the order
     */
    public function transfer(TableOrder $order, Table $target): TableOrder
    {
        $this->assertOpen($order);

        return DB::transaction(function () use ($order, $target) {
            // Ordered by id so two simultaneous transfers between the same pair of
            // tables cannot each hold the lock the other is waiting for.
            $ids = collect([$order->table_id, $target->id])->unique()->sort()->values()->all();
            Table::whereIn('id', $ids)->lockForUpdate()->get();

            $locked = Table::find($target->id);

            if (!$locked) {
                throw new DomainException('That table no longer exists.');
            }

            if ($locked->id === $order->table_id) {
                throw new DomainException('The order is already on that table.');
            }

            if (!$locked->is_active) {
                throw new DomainException("{$locked->name} is out of service.");
            }

            if (TableOrder::open()->where('table_id', $locked->id)->exists()) {
                throw new DomainException("{$locked->name} is occupied. Choose a free table.");
            }

            $from = $order->table->name ?? '-';
            $order->update(['table_id' => $locked->id]);

            AuditLog::record(
                "Moved {$order->reference} from {$from} to {$locked->name}",
                'table_orders',
                $order->id
            );

            return $order->refresh();
        });
    }

    /**
     * Cancel an order: the guests left, or it was opened by mistake.
     *
     * Cancelling is bookkeeping, not a refund - no money was ever taken and no
     * stock was ever moved, so there is nothing to reverse. The reason and the
     * audit entry are the whole point: a table that collected food and then
     * cancelled is something a manager should be able to find.
     *
     * @throws DomainException when the order is not open
     */
    public function cancel(TableOrder $order, string $reason): TableOrder
    {
        $this->assertOpen($order);

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Say why the order is being cancelled.');
        }

        $items = $order->items()->count();
        $order->update(['status' => TableOrder::STATUS_CANCELLED]);

        AuditLog::record(
            "Cancelled {$order->reference} ({$items} line(s), " . money($this->totals($order)['grand_total'], false) . "): {$reason}",
            'table_orders',
            $order->id
        );

        return $order->refresh();
    }

    /**
     * Mark an order as paid for, pointing at the sale that paid for it.
     *
     * Called from App\Observers\TableOrderSaleObserver once the till has actually
     * created the sale - never from the settle request itself, because at that
     * point no money has been taken.
     */
    public function markSettled(TableOrder $order, int $saleId): TableOrder
    {
        $order->update([
            'status' => TableOrder::STATUS_SETTLED,
            'settled_at' => now(),
            'sale_id' => $saleId,
        ]);

        return $order;
    }

    /**
     * The running total, through the same calculator the till uses.
     *
     * Line prices come from the snapshots, so this is what the guests will be
     * asked for. No discounts are applied: a table order carries none, because
     * discounting is a till decision made when the bill is presented.
     */
    public function totals(TableOrder $order): array
    {
        $lines = [];

        foreach ($order->items as $item) {
            $lines[$item->id] = [
                'price' => (float) $item->price,
                'quantity' => (int) $item->quantity,
            ];
        }

        return SaleCalculator::calculate($lines, null, (float) setting('tax_rate', 0));
    }

    /**
     * A tip or service charge on top of the bill.
     *
     * The one piece of money maths this feature owns, which is why it is here
     * with unit tests rather than inline in a controller. It mirrors the
     * percent/fixed vocabulary of SaleCalculator's discounts so the two read the
     * same way, but it is an addition: SaleCalculator only ever takes money off.
     *
     * The percentage is taken on the bill as the guests see it (tax included),
     * because that is the figure somebody tapping "10%" is looking at.
     *
     * @param  float  $of  the order total the charge is a percentage of
     * @throws DomainException when the type or value is not usable
     */
    public function serviceCharge(float $of, ?string $type, $value): float
    {
        if ($type === null || $type === '' || $type === 'none' || $value === null || $value === '' || (float) $value == 0.0) {
            return 0.0;
        }

        if (!in_array($type, SaleCalculator::TYPES, true)) {
            throw new DomainException('Service charge must be a percentage or a fixed amount.');
        }

        $value = (float) $value;

        if ($value < 0) {
            throw new DomainException('A service charge cannot be negative.');
        }

        if ($type === 'percent') {
            if ($value > 100) {
                throw new DomainException('A service charge cannot exceed 100%.');
            }

            return round(max(0.0, $of) * $value / 100, 2);
        }

        return round($value, 2);
    }

    /**
     * The order as the session cart the till expects.
     *
     * The shape is dictated by POSController::resolveCart() - see
     * TableOrderController::settle() for why the handover works this way.
     *
     * The cart is keyed by product id, so two lines of the same product (a round
     * of drinks at 7, another at 9) MUST be added together here. Letting the
     * second line overwrite the first would undercharge the guests by a whole
     * round, which is the kind of bug that is only noticed at cash-up.
     *
     * @return array<int, array<string, mixed>> keyed by product id
     */
    public function cartFor(TableOrder $order): array
    {
        $cart = [];

        foreach ($order->items()->with('product')->get() as $item) {
            $product = $item->product;

            if (!$product) {
                // The menu item was deleted mid-service. The till would drop it
                // from the cart anyway, so leave it out rather than hand over a
                // line that cannot be priced.
                continue;
            }

            $id = (int) $product->id;

            if (isset($cart[$id])) {
                $cart[$id]['quantity'] += (int) $item->quantity;
                continue;
            }

            $cart[$id] = [
                'product_id' => $id,
                'name' => $product->name,
                'price' => (float) $item->price,
                'quantity' => (int) $item->quantity,
                'discount_type' => null,
                'discount_value' => null,
            ];
        }

        return $cart;
    }

    /** @throws DomainException when the order has been settled or cancelled */
    private function assertOpen(TableOrder $order): void
    {
        if (!$order->isOpen()) {
            throw new DomainException('That order is closed, so it cannot be changed.');
        }
    }

    /**
     * Whitelist the course. Validated here as well as in the controller because a
     * service is also called from tests and commands, which validate nothing.
     *
     * @throws DomainException on an unknown course
     */
    private function assertCourse(?string $course): ?string
    {
        if ($course === null || $course === '') {
            return null;
        }

        if (!in_array($course, TableOrderItem::COURSES, true)) {
            throw new DomainException('Choose a course the kitchen recognises.');
        }

        return $course;
    }

    /**
     * A readable, unique reference: TBL-20261013-001.
     *
     * Same shape as the stock-take references. The column is unique, so on the
     * unlikely clash of two orders opened in the same instant we fall back to a
     * random suffix rather than failing in a server's hand.
     */
    private function nextReference(): string
    {
        $prefix = 'TBL-' . now()->format('Ymd');
        $sequence = TableOrder::where('reference', 'like', $prefix . '%')->count() + 1;
        $reference = $prefix . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);

        while (TableOrder::where('reference', $reference)->exists()) {
            $reference = $prefix . '-' . Str::upper(Str::random(4));
        }

        return $reference;
    }
}
