<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creating a sale. The only place in the app that does it.
 *
 * WHY THIS IS A SERVICE AND NOT A CONTROLLER METHOD
 *
 * This was the body of POSController::checkout(). It moved here the moment a
 * second caller appeared - the offline sync endpoint, which replays sales a till
 * took while the network was down. Two implementations of "create a sale" would
 * have had to agree, forever, about stock locking, discount limits, tax
 * rounding, split tenders, change, credit limits, shift stamping, the inventory
 * log and the audit trail. They would not have, and the one that drifted would
 * have been wrong about money.
 *
 * So there is one path, and the differences between an online sale and a
 * replayed one are *options* on it rather than a second copy of it:
 *
 *   - where the unit price comes from (the catalogue, or the queue - see below);
 *   - whether stock may go negative;
 *   - whether an archived product may still be sold;
 *   - which moment the sale is stamped with;
 *   - which shift it belongs to, if any.
 *
 * WHAT IS NEVER AN OPTION
 *
 * The caller states *intent*: which products, what quantities, which tenders,
 * which customer. Every figure is worked out here - subtotal, line and order
 * discounts, tax, the service charge, the grand total, change, what is left
 * owing. A total arriving from outside is not read, not compared and not
 * stored. That is as true of a sale replayed from a device that spent the night
 * in a drawer as it is of a form post, and it is the reason the offline feature
 * can be trusted at all.
 *
 * ON THE QUEUED PRICE, WHICH IS THE ONE HARD CASE
 *
 * `prices => 'queued'` lets a replayed line be priced at the figure the till
 * charged, not today's catalogue price. That sounds like trusting money from the
 * client, and it is the one place where a number from outside is used - so it is
 * bounded. The justification: a price agreed across a counter at nine in the
 * morning is a fact about that transaction, and the shop re-pricing at noon does
 * not change what the customer paid. Re-pricing the line at the catalogue figure
 * would silently charge them something they never agreed to. But a queued price
 * is still only accepted within a tolerance of the current price (see
 * priceFor()), because a payload claiming a 10,000 item sold for 1 is not a
 * re-price, it is a tampered or corrupted queue.
 */
class CheckoutService
{
    /**
     * How far a queued price may sit from today's catalogue price before the
     * sale is refused, as a percentage. See the class docblock and priceFor().
     */
    public const DEFAULT_PRICE_TOLERANCE = 25.0;

    public function __construct(
        private CreditService $credit,
        private ShiftService $shifts,
        private PaymentSplitService $split,
        private TableOrderService $tables,
    ) {
    }

    /**
     * Ring up a sale.
     *
     * Everything happens in one transaction with every product row locked, so
     * two tills cannot both sell the last unit and a refusal anywhere leaves no
     * trace: no sale, no stock movement, no inventory log, no tender rows.
     *
     * @param  array{
     *     lines: array<int|string, array{product_id?: int, quantity: int|string, price?: float|int|string|null, discount_type?: ?string, discount_value?: float|int|string|null}>,
     *     order_discount?: array{type: ?string, value: mixed}|null,
     *     tenders?: array<int, array{method?: mixed, amount?: mixed, reference?: mixed}>,
     *     customer_id?: int|string|null,
     *     on_credit?: bool,
     *     due_date?: ?string,
     *     service_charge?: array{type?: mixed, value?: mixed, label?: mixed}|null
     *   }  $input  what the cashier did - intent only, never value
     *
     * @param  array{
     *     cashier: User,
     *     at?: ?Carbon,
     *     shift?: 'current'|Shift|null,
     *     require_shift?: ?bool,
     *     prices?: 'catalogue'|'queued',
     *     price_tolerance?: ?float,
     *     allow_negative_stock?: bool,
     *     allow_archived_products?: bool,
     *     client_uuid?: ?string,
     *     synced_at?: ?Carbon
     *   }  $options
     *
     * @return array{sale: Sale, notes: array<int, string>}  `notes` is everything
     *         that was accepted but is worth a human knowing: stock driven
     *         negative, a price that had moved since. Empty on an ordinary sale.
     *
     * @throws DomainException when the sale cannot be taken, with a message
     *         written to be shown to the person at the till
     */
    public function create(array $input, array $options): array
    {
        $cashier = $options['cashier'] ?? null;

        if (!$cashier instanceof User) {
            // A programming error, not something a cashier can cause: every sale
            // belongs to somebody, and reports, shifts and policies all read it.
            throw new InvalidArgumentException('A sale must be created for a cashier.');
        }

        if (empty($input['lines'])) {
            throw new DomainException('There is nothing to sell.');
        }

        $queuedPrices = ($options['prices'] ?? 'catalogue') === 'queued';
        $allowNegativeStock = (bool) ($options['allow_negative_stock'] ?? false);
        $allowArchived = (bool) ($options['allow_archived_products'] ?? false);
        $tolerance = (float) ($options['price_tolerance'] ?? self::DEFAULT_PRICE_TOLERANCE);
        $at = $options['at'] ?? null;

        return DB::transaction(function () use (
            $input, $options, $cashier, $queuedPrices, $allowNegativeStock, $allowArchived, $tolerance, $at
        ) {
            $notes = [];
            $products = [];
            $lines = [];

            /*
             * Lock each product row so two cashiers can't both sell the last unit,
             * and price every line from the database rather than the session copy.
             */
            foreach ($input['lines'] as $key => $item) {
                $product = $this->lockProduct((int) ($item['product_id'] ?? $key), $allowArchived);

                if (!$product) {
                    throw new DomainException('A product in the cart no longer exists.');
                }

                $quantity = (int) $item['quantity'];

                if ($quantity < 1) {
                    throw new DomainException('A line must be for at least one ' . strtolower(term('product')) . '.');
                }

                // A service has no stock and so can never be short of it.
                if ($product->tracksStock() && $product->stock_quantity < $quantity) {
                    if (!$allowNegativeStock) {
                        throw new DomainException("{$product->name} is out of stock or has insufficient quantity.");
                    }

                    /*
                     * Deliberately banked anyway - see OfflineSyncService for the
                     * policy and the setting behind it. The note, the inventory
                     * log below and the resulting negative figure are what make
                     * the discrepancy visible to a stock-take.
                     */
                    $notes[] = sprintf(
                        '%s: %d sold with %d in stock, leaving %d.',
                        $product->name,
                        $quantity,
                        (int) $product->stock_quantity,
                        (int) $product->stock_quantity - $quantity
                    );
                }

                $products[$key] = $product;
                $lines[$key] = [
                    'price' => $queuedPrices
                        ? $this->priceFor($product, $item['price'] ?? null, $tolerance, $notes)
                        : (float) $product->sale_price,
                    'quantity' => $quantity,
                    'discount_type' => $item['discount_type'] ?? null,
                    'discount_value' => $item['discount_value'] ?? null,
                ];
            }

            $orderDiscount = $input['order_discount'] ?? null;
            $taxRate = (float) setting('tax_rate', 0);
            $totals = SaleCalculator::calculate($lines, $orderDiscount, $taxRate);

            $this->assertDiscountAllowed($totals, $cashier);

            /*
             * A tip or service charge goes on top of the bill, tax included.
             * Recomputed here from the type and value, never taken from the
             * quoted `amount` the caller was holding.
             */
            $service = $this->serviceChargeFor($totals['grand_total'], $input['service_charge'] ?? null);

            $grandTotal = round($totals['grand_total'] + $service['amount'], 2);

            $customerId = $input['customer_id'] ?? null;
            $customer = $customerId ? Customer::find($customerId) : null;

            $onCredit = (bool) ($input['on_credit'] ?? false);

            if ($onCredit && !feature('credit')) {
                throw new DomainException('Selling on credit is switched off for this shop.');
            }

            /*
             * The tenders decide what was taken, what goes back as change and
             * what is left owing. A short payment is only allowed when the
             * cashier asked for it; whether there is an account to put it on
             * is CreditService's call, not ours - it refuses a part-paid sale
             * with no customer and says so in words the cashier can act on.
             */
            $plan = $this->split->plan($input['tenders'] ?? [], $grandTotal, $onCredit);
            $paid = $plan['tendered'];

            // Said early so the cashier is not told after the drawer opens.
            // recordCreditSale() checks again under a lock, where it counts.
            if ($plan['credit'] > 0 && $customer) {
                $this->credit->assertCanTakeCredit($customer, $plan['credit']);
            }

            /*
             * array_key_exists, not `??`: an explicit null means "stamp no
             * shift", which is exactly what a replayed sale whose shift has been
             * signed off needs, and `??` would quietly turn it back into
             * "whatever shift is open now".
             */
            $shift = $this->resolveShift(
                array_key_exists('shift', $options) ? $options['shift'] : 'current',
                $cashier
            );

            if (!$shift && ($options['require_shift'] ?? $this->shifts->requiredToSell())) {
                throw new DomainException('No shift is open. Open a shift before selling.');
            }

            $rungUpAt = $at ?: now();

            $sale = new Sale([
                'invoice_number' => 'INV-' . $rungUpAt->format('YmdHis') . '-' . Str::upper(Str::random(4)),
                'user_id' => $cashier->id,
                'customer_id' => $customer?->id,
                'shift_id' => $shift?->id,
                'total_amount' => $totals['subtotal'],
                'tax_amount' => $totals['tax'],
                'discount_amount' => $totals['discount_total'],
                'grand_total' => $grandTotal,
                // The largest tender, so every existing report and filter still works.
                'payment_method' => $plan['dominant'],
                'paid_amount' => $paid,
                // Clamped: a sale that is part on credit has no change to give.
                'change_due' => $plan['change'],
                'due_date' => $input['due_date'] ?? null,
                'sale_date' => $rungUpAt,
                'status' => Sale::STATUS_COMPLETED,
                'tax_rate' => $taxRate,
                'discount_type' => $orderDiscount['type'] ?? null,
                'discount_value' => $orderDiscount['value'] ?? null,
                // Stored, not derived: a reprint renders from the sale row alone,
                // and the guest should see the charge named on the receipt.
                'service_charge' => $service['amount'],
                'service_charge_label' => $service['amount'] > 0 ? $service['label'] : null,
                /*
                 * The device's id for this sale. The unique index on it is what
                 * stops a replayed queue banking the same sale twice - see the
                 * 2026_10_14_000010 migration.
                 */
                'client_uuid' => $options['client_uuid'] ?? null,
                'synced_at' => $options['synced_at'] ?? null,
            ]);

            if ($at !== null) {
                /*
                 * A replayed sale belongs to the minute the cashier took the
                 * money, not to the minute the connection came back: the Z
                 * report, the daily takings and the customer's receipt all read
                 * these. `created_at` is guarded, so it is set on the instance
                 * rather than passed to create() - once dirty, Eloquent leaves
                 * it alone instead of stamping now().
                 */
                $sale->created_at = $at;
                $sale->updated_at = $at;
            }

            $sale->save();

            foreach ($lines as $key => $line) {
                $product = $products[$key];
                $calc = $totals['lines'][$key];

                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $line['quantity'],
                    'price' => $line['price'],
                    // Snapshot: profit must not change when the product's cost is edited later.
                    'cost_price' => $product->cost_price,
                    'total' => $calc['gross'],
                    'discount_amount' => $calc['discount_total'],
                    'tax_amount' => $calc['tax'],
                    'paid_total' => $calc['paid_total'],
                ]);

                // A service has no stock level, so there is no movement to record.
                if (!$product->tracksStock()) {
                    continue;
                }

                // Each line adjusts *its own* product.
                $product->decrement('stock_quantity', $line['quantity']);

                InventoryLog::create([
                    'product_id' => $product->id,
                    'user_id' => $cashier->id,
                    'type' => 'OUT',
                    'quantity' => $line['quantity'],
                    'reason' => 'Sale ' . $sale->invoice_number,
                ]);
            }

            // One row per tender that brought money in; the drawer figures read these.
            $this->split->record($sale, $plan);

            /*
             * The only thing allowed to write balance_due and payment_status.
             * It caps paid_amount at the grand total (the rest is change, which
             * is already in change_due), so paid_amount and the sale_payments
             * rows always come to the same figure.
             */
            $this->credit->recordCreditSale($sale, $paid);

            AuditLog::record('Created Sale', 'sales', $sale->id);

            return ['sale' => $sale, 'notes' => $notes];
        });
    }

    /**
     * The biggest discount this user may give, or null when they are not limited.
     *
     * Read off the user rather than off auth(), because a replayed sale is judged
     * against the limit of the cashier who rang it up, not of whoever synced it.
     */
    public function discountLimitFor(?User $user): ?float
    {
        // Managers and admins are not limited.
        if ($user?->hasAnyRole(['admin', 'manager'])) {
            return null;
        }

        return (float) setting('max_cashier_discount', 10);
    }

    /**
     * @throws DomainException when the discount on this sale needs a manager
     */
    public function assertDiscountAllowed(array $totals, ?User $user): void
    {
        $limit = $this->discountLimitFor($user);

        if ($limit !== null && SaleCalculator::discountPercent($totals) > $limit + 0.005) {
            throw new DomainException("Discounts above {$limit}% need a manager.");
        }
    }

    /**
     * The tip or service charge on this bill, recomputed from its type and value.
     *
     * The caller passes what the cashier chose ("10%"), never the amount they
     * were quoted, for the same reason no other money field from outside is
     * trusted. The maths belongs to the service that owns it and has the tests
     * for it - TableOrderService::serviceCharge takes the percentage on the bill
     * including tax, which is the number someone tapping "10%" is looking at.
     *
     * Gated on the sector feature: a shop that does not take tips cannot have one
     * arrive through a stale session key or a stale queued payload.
     *
     * @param  array{type?: mixed, value?: mixed, label?: mixed}|null  $charge
     * @return array{amount: float, label: ?string}
     *
     * @throws DomainException on a malformed charge
     */
    private function serviceChargeFor(float $billTotal, ?array $charge): array
    {
        $none = ['amount' => 0.0, 'label' => null];

        if (!is_array($charge) || !feature('tips')) {
            return $none;
        }

        $amount = $this->tables->serviceCharge(
            $billTotal,
            is_string($charge['type'] ?? null) ? $charge['type'] : null,
            $charge['value'] ?? null
        );

        if ($amount <= 0) {
            return $none;
        }

        $label = is_string($charge['label'] ?? null) && trim($charge['label']) !== ''
            ? Str::limit(trim($charge['label']), 60, '')
            : 'Service charge';

        return ['amount' => $amount, 'label' => $label];
    }

    /**
     * Lock one product row for this sale.
     *
     * Archived (soft-deleted) products are normally invisible, which is what
     * makes an online checkout refuse one: it has been withdrawn from sale. A
     * replayed offline sale is the opposite case - the goods left the shelf
     * before the product was withdrawn, so the row is read `withTrashed` and the
     * sale is banked against it. Refusing it would lose money that is already in
     * the drawer and leave the stock figure overstated for ever.
     */
    private function lockProduct(int $id, bool $allowArchived): ?Product
    {
        $query = $allowArchived ? Product::withTrashed() : Product::query();

        return $query->lockForUpdate()->find($id);
    }

    /**
     * What to charge one line of a replayed sale.
     *
     * The queued figure wins, because that is what the customer agreed to and
     * paid - but only within `$tolerance` percent of today's catalogue price.
     * Beyond that it is refused: a genuine re-price between ringing up and
     * syncing is small and the shop can correct one sale by hand, whereas a line
     * claiming to have sold a 10,000 item for 1 is a tampered or corrupted queue
     * and banking it would be a free giveaway with an audit trail that says the
     * server agreed to it.
     *
     * A missing or non-numeric price falls back to the catalogue, which is the
     * safe direction: the shop charges what it charges today rather than
     * whatever a malformed payload implied.
     *
     * @param  array<int, string>  $notes  appended to when the price had moved
     *
     * @throws DomainException when the queued price is not credible
     */
    private function priceFor(Product $product, $queued, float $tolerance, array &$notes): float
    {
        $catalogue = round((float) $product->sale_price, 2);

        if (!is_numeric($queued)) {
            return $catalogue;
        }

        $queued = round((float) $queued, 2);

        if ($queued < 0) {
            throw new DomainException($product->name . ' was queued at a negative price.');
        }

        if (abs($queued - $catalogue) < 0.005) {
            return $catalogue;
        }

        /*
         * The tolerance is measured against the larger of the two prices, so it
         * means the same thing whether the shop put the price up or took it down,
         * and so a catalogue price of 0 cannot make the window infinite.
         */
        $reference = max($catalogue, $queued);
        $drift = $reference > 0 ? abs($queued - $catalogue) / $reference * 100 : 0.0;

        if ($drift > $tolerance + 0.005) {
            throw new DomainException(sprintf(
                '%s was queued at %s but now costs %s. That is too far apart to bank automatically - ring it up by hand.',
                $product->name,
                money($queued),
                money($catalogue)
            ));
        }

        // Accepted, and said out loud: somebody should know the figures differ.
        $notes[] = sprintf(
            '%s was sold at %s and now costs %s; banked at the price the customer paid.',
            $product->name,
            money($queued),
            money($catalogue)
        );

        return $queued;
    }

    /**
     * Which drawer this sale belongs to.
     *
     *  - `'current'` (the online till): the cashier's open shift, resolved inside
     *    the transaction on purpose. currentFor() only ever returns an open
     *    shift, so a sale finishing just after a manager closed the drawer is
     *    never stamped into a reconciliation that has already been signed off.
     *  - a Shift: stamp that one. The caller has already checked it is open and
     *    belongs to this cashier (see OfflineSyncService::shiftFor).
     *  - null: stamp nothing. Used for a replayed sale whose shift has since been
     *    closed - the alternative would be writing into a signed-off Z report.
     */
    private function resolveShift($shift, User $cashier): ?Shift
    {
        if ($shift instanceof Shift) {
            return $shift;
        }

        return $shift === 'current' ? $this->shifts->currentFor($cashier) : null;
    }
}
