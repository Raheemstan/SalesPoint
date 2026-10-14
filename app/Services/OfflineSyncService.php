<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\OfflineSaleAttempt;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Replaying sales a till took while it had no network.
 *
 * WHAT A QUEUED SALE IS, AND IS NOT
 *
 * It is a record of **intent**: these products, these quantities, this customer,
 * this discount, these tenders, at this moment, by this cashier. It is never a
 * record of **value**. Every figure on the sale that gets banked - line totals,
 * discounts, tax, the service charge, the grand total, change, what is left
 * owing - is recomputed from the database by CheckoutService, which is the same
 * code the online till goes through. The `claimed_total` the device sends is
 * stored in the attempt payload as evidence and is never read to bank anything;
 * a device with a tampered or simply buggy total cannot move a penny.
 *
 * The one number taken from the queue is the unit price of a line, and it is
 * bounded - see CheckoutService::priceFor() for why that is both necessary and
 * safe.
 *
 * THE VERDICTS
 *
 * Each queued sale is answered on its own, so one malformed or refused sale
 * never takes the rest of the batch down with it:
 *
 *   accepted  - banked. The till may forget it. The response carries the real
 *               invoice number so the provisional reference on the customer's
 *               receipt can be reconciled.
 *   duplicate - this client_uuid was already banked. The till may forget it too,
 *               and the response names the sale it became. This is a normal
 *               event, not an error: it is what happens when the response to a
 *               successful sync is lost on the way back to the till.
 *   rejected  - no sale was created. The till keeps it, shows it as needing
 *               attention and does not retry it on its own, because retrying
 *               will not change the answer until a person does something.
 *
 * REJECTION POLICY, CASE BY CASE
 *
 * The rule behind all of it: the goods are already gone and the money is already
 * in the drawer, so the default is to bank the sale and make the discrepancy
 * visible. A refusal is for cases where banking it would create a *worse* and
 * less recoverable problem than refusing it.
 *
 *   Stock sold out meanwhile - BANKED (setting `offline_allow_negative_stock`,
 *     default on). Two tills offline selling the same last unit is the common
 *     case, and a shop would far rather see stock at -1 and correct it with a
 *     stock-take than lose the takings. An InventoryLog row is written either
 *     way, so the movement is on the record and the negative figure is visible
 *     on every stock screen. Switch the setting off and the sale is refused
 *     instead, with the same words the online till uses.
 *
 *   Price changed since - BANKED at the queued price, and noted. The sale was
 *     agreed with the customer at the old price; re-pricing it at today's figure
 *     would charge them something they never agreed to. Bounded by a tolerance
 *     (`offline_price_tolerance`, default 25%) so this cannot be used to bank a
 *     giveaway.
 *
 *   Product archived or made inactive since - BANKED. Withdrawing something from
 *     sale does not unmake a sale already made. The product row still exists and
 *     is read `withTrashed`, so the line, its cost snapshot and its stock
 *     movement are all still correct.
 *
 *   Product deleted outright - REJECTED. There is nothing to price it from,
 *     nothing to decrement and nothing to put on a sale line. Nothing can be
 *     banked honestly, so it is refused and the payload is kept so the sale can
 *     be re-entered by hand.
 *
 *   Customer over their credit limit - REJECTED. Unlike stock, banking this
 *     creates a receivable the shop may never collect, and the credit limit is
 *     the only control it has against exactly that. The refusal carries
 *     CreditService's own wording, so a manager can raise the limit or take a
 *     payment and have the till send it again.
 *
 *   Discount above the cashier's limit - REJECTED. The limit exists to stop a
 *     cashier discounting at will. If going offline bypassed it, the control
 *     would be worth nothing: pull the network cable and discount freely. A
 *     genuine over-limit discount is a thing a manager authorises, so it is
 *     refused and shown to one.
 *
 *   Rung up in the future, or longer ago than the window - REJECTED. A sale
 *     timestamped next March or last year means the device's clock is wrong, and
 *     an attributed sale is worse than no sale: it lands in a Z report and a tax
 *     period it does not belong to. The window is `offline_max_queue_age_hours`
 *     (default 7 days); a small future tolerance absorbs ordinary clock skew.
 *
 *   Shift since closed - BANKED, but stamped onto no shift, and said out loud.
 *     ShiftService freezes a shift's expected figures when it is signed off, so
 *     writing into it would quietly contradict a reconciliation somebody has
 *     already put their name to. Leaving it unstamped means the money shows in
 *     the day's takings but in no drawer, which is the truth and is what the
 *     note, the audit entry and the `notes` on the response are for. (Note that
 *     the till's own "a shift must be open to sell" setting is deliberately not
 *     applied on replay: it is a rule about starting a sale, and this sale has
 *     already happened.)
 */
class OfflineSyncService
{
    /**
     * How many queued sales one request may carry.
     *
     * Every sale in a batch locks product rows, so a batch is also a lock held
     * over a slow mobile connection. Small batches, replayed repeatedly, keep a
     * till that has been offline all day from blocking the one that is selling
     * right now. The client pages through its queue.
     */
    public const MAX_BATCH = 25;

    /** The most lines one queued sale may have, as a sanity bound on the payload. */
    public const MAX_LINES = 100;

    /**
     * How far ahead of the server a device's clock may be before its sales are
     * refused. Tills drift by minutes; they do not drift by days.
     */
    private const FUTURE_TOLERANCE_MINUTES = 15;

    public function __construct(private CheckoutService $checkouts)
    {
    }

    /**
     * Bank a batch of queued sales, one verdict each.
     *
     * @param  array<int, mixed>  $queued  the raw `sales` array from the request
     * @return array<int, array<string, mixed>>  verdicts, in the order they arrived
     */
    public function replay(array $queued, User $syncedBy): array
    {
        $receivedAt = now();
        $verdicts = [];

        foreach (array_values($queued) as $index => $payload) {
            $verdicts[] = $this->replayOne($payload, $syncedBy, $receivedAt, $index);
        }

        return $verdicts;
    }

    /**
     * One queued sale: validate it, try to bank it, record what happened.
     *
     * Nothing in here is allowed to throw. A batch is a batch of independent
     * sales, and one that cannot be understood must not stop the others from
     * reaching the drawer.
     *
     * @return array<string, mixed>
     */
    private function replayOne($payload, User $syncedBy, Carbon $receivedAt, int $index): array
    {
        if (!is_array($payload)) {
            return $this->reject(
                ['raw' => is_scalar($payload) ? (string) $payload : null, 'position' => $index],
                null,
                $syncedBy,
                $receivedAt,
                'This queued sale was not readable at all and has been kept for a person to look at.'
            );
        }

        $validator = Validator::make($payload, $this->rules(), $this->messages());

        if ($validator->fails()) {
            return $this->reject(
                $payload,
                is_string($payload['client_uuid'] ?? null) ? $payload['client_uuid'] : null,
                $syncedBy,
                $receivedAt,
                // The first message only: the cashier needs something to read, not a form.
                (string) $validator->errors()->first()
            );
        }

        $data = $validator->validated();
        $uuid = $data['client_uuid'];

        try {
            $at = Carbon::parse($data['rung_up_at']);
        } catch (Throwable $e) {
            return $this->reject($payload, $uuid, $syncedBy, $receivedAt, 'The time this sale was rung up could not be read.');
        }

        if ($refusal = $this->clockRefusal($at)) {
            return $this->reject($payload, $uuid, $syncedBy, $receivedAt, $refusal);
        }

        $cashierId = (int) ($data['cashier_id'] ?? $syncedBy->id);

        if (!Gate::forUser($syncedBy)->allows('syncFor', [OfflineSaleAttempt::class, $cashierId])) {
            return $this->reject(
                $payload,
                $uuid,
                $syncedBy,
                $receivedAt,
                'This sale was rung up by another cashier, so only a manager can send it up.'
            );
        }

        $cashier = User::find($cashierId);

        if (!$cashier) {
            return $this->reject($payload, $uuid, $syncedBy, $receivedAt, 'The cashier this sale belongs to no longer exists.');
        }

        try {
            return DB::transaction(function () use ($data, $payload, $uuid, $cashier, $syncedBy, $at, $receivedAt) {
                $notes = [];
                $shift = $this->shiftFor($data['shift_id'] ?? null, $cashier, $notes);

                ['sale' => $sale, 'notes' => $saleNotes] = $this->checkouts->create([
                    'lines' => $data['lines'],
                    'order_discount' => $this->discountFrom($data['order_discount'] ?? null),
                    'tenders' => $data['tenders'],
                    'customer_id' => $data['customer_id'] ?? null,
                    'on_credit' => (bool) ($data['on_credit'] ?? false),
                    /*
                     * Accepted whatever the date, unlike the online till, which
                     * refuses a due date in the past. A sale rung up on Friday for
                     * payment on Monday can reach the server on Tuesday; the honest
                     * record is the date that was agreed, and the receivables
                     * report will show it as overdue, which it is.
                     */
                    'due_date' => $data['due_date'] ?? null,
                    'service_charge' => $data['service_charge'] ?? null,
                ], [
                    'cashier' => $cashier,
                    // The moment the money changed hands, not the moment it synced.
                    'at' => $at,
                    'shift' => $shift,
                    // Deliberately not enforced on replay - see the class docblock.
                    'require_shift' => false,
                    'prices' => 'queued',
                    'price_tolerance' => $this->priceTolerance(),
                    'allow_negative_stock' => $this->allowsNegativeStock(),
                    'allow_archived_products' => true,
                    'client_uuid' => $uuid,
                    'synced_at' => $receivedAt,
                ]);

                $notes = array_merge($notes, $saleNotes);

                $attempt = $this->record($payload, $uuid, $syncedBy, $receivedAt, [
                    'status' => OfflineSaleAttempt::ACCEPTED,
                    'sale_id' => $sale->id,
                    // Kept on the accepted row too: "banked, but stock went
                    // negative" is exactly the sort of thing that must not be
                    // lost just because the sale went through.
                    'reason' => $notes ? implode(' ', $notes) : null,
                ]);

                AuditLog::record(sprintf(
                    'Banked offline %s rung up by %s on %s',
                    $sale->invoice_number,
                    $cashier->name,
                    $at->format('d M Y H:i')
                ), 'sales', $sale->id);

                return $this->verdict(OfflineSaleAttempt::ACCEPTED, $uuid, [
                    'sale_id' => $sale->id,
                    'invoice_number' => $sale->invoice_number,
                    'grand_total' => round((float) $sale->grand_total, 2),
                    'rung_up_at' => $at->toIso8601String(),
                    'notes' => $notes,
                    'attempt_id' => $attempt->id,
                ]);
            });
        } catch (QueryException $e) {
            /*
             * The unique index on sales.client_uuid has fired, which is the whole
             * point of it: this sale is already banked. No SELECT was done first,
             * deliberately - two devices (or two tabs, or a retry racing its own
             * first attempt) can both pass a pre-check and both insert, and only
             * the database can settle which one wins.
             *
             * The transaction has rolled back, so the second attempt left nothing
             * behind: no sale, no stock movement, no tender rows.
             */
            $existing = Sale::where('client_uuid', $uuid)->first();

            if ($existing) {
                $attempt = $this->record($payload, $uuid, $syncedBy, $receivedAt, [
                    'status' => OfflineSaleAttempt::DUPLICATE,
                    'sale_id' => $existing->id,
                    'reason' => 'Already banked as ' . $existing->invoice_number . '.',
                ]);

                return $this->verdict(OfflineSaleAttempt::DUPLICATE, $uuid, [
                    'sale_id' => $existing->id,
                    'invoice_number' => $existing->invoice_number,
                    'grand_total' => round((float) $existing->grand_total, 2),
                    'reason' => 'Already banked as ' . $existing->invoice_number . '.',
                    'attempt_id' => $attempt->id,
                ]);
            }

            // Some other database failure. Keep it, say so plainly, and leave the
            // detail in the log rather than on a till screen.
            Log::error('Offline sync failed to bank a sale', ['client_uuid' => $uuid, 'error' => $e->getMessage()]);

            return $this->reject(
                $payload,
                $uuid,
                $syncedBy,
                $receivedAt,
                'The server could not save this sale. It is still held - try syncing again.'
            );
        } catch (DomainException $e) {
            // A refusal written to be read by the person at the till.
            return $this->reject($payload, $uuid, $syncedBy, $receivedAt, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Offline sync hit an unexpected error', ['client_uuid' => $uuid, 'error' => $e->getMessage()]);

            return $this->reject(
                $payload,
                $uuid,
                $syncedBy,
                $receivedAt,
                'Something went wrong banking this sale. It is still held - show this to a manager.'
            );
        }
    }

    /**
     * What the till needs to show about offline sales: how many are waiting for
     * somebody, and what they were.
     *
     * Scoped like every other till screen - a cashier sees the batches they sent,
     * a manager sees all of them.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(User $user): array
    {
        $everyone = $user->hasAnyRole(['admin', 'manager']);

        $scope = fn () => OfflineSaleAttempt::query()
            ->when(!$everyone, fn ($query) => $query->where('user_id', $user->id));

        $rejected = $scope()->needsAttention()->latestFirst()->limit(25)->get();

        return [
            // The one figure the till indicator is driven by.
            'rejected' => $scope()->needsAttention()->count(),
            'accepted' => $scope()->where('status', OfflineSaleAttempt::ACCEPTED)->count(),
            'duplicates' => $scope()->where('status', OfflineSaleAttempt::DUPLICATE)->count(),
            'scope' => $everyone ? 'all' : 'mine',
            'needs_attention' => $rejected->map(fn (OfflineSaleAttempt $attempt) => [
                'id' => $attempt->id,
                'client_uuid' => $attempt->client_uuid,
                'reference' => $attempt->payload['reference'] ?? null,
                'reason' => $attempt->reason,
                'lines' => $attempt->lineCount(),
                // Labelled "claimed" on purpose: a refused sale has no
                // server-computed total, because the refusal is the server
                // declining to stand behind one.
                'claimed_total' => $attempt->claimedTotal(),
                'claimed_total_display' => money($attempt->claimedTotal()),
                'rung_up_at' => $attempt->payload['rung_up_at'] ?? null,
                'received_at' => $attempt->received_at?->toIso8601String(),
            ])->all(),
            // So the client never has to hardcode a server rule.
            'limits' => [
                'max_batch' => self::MAX_BATCH,
                'max_age_hours' => $this->maxAgeHours(),
                'allow_negative_stock' => $this->allowsNegativeStock(),
            ],
        ];
    }

    /**
     * The shape a queued sale must have. Anything else is refused on its own.
     *
     * Enums are whitelisted rather than filtered, amounts are bounded, and there
     * is deliberately no rule for a total: the client may send `claimed_total`
     * and it is kept as evidence, but no rule here validates it because nothing
     * downstream reads it.
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            /*
             * The device's id for this sale. Length and alphabet are pinned so a
             * client cannot smuggle something odd into a column the whole
             * idempotency guarantee rests on.
             */
            'client_uuid' => ['required', 'string', 'size:36', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'],

            // The provisional reference printed on the customer's receipt.
            'reference' => ['nullable', 'string', 'max:20'],

            'rung_up_at' => ['required', 'date'],
            'cashier_id' => ['nullable', 'integer', 'exists:users,id'],
            'shift_id' => ['nullable', 'integer'],

            'lines' => ['required', 'array', 'min:1', 'max:' . self::MAX_LINES],
            /*
             * `distinct` because the sale is priced and stocked line by line: two
             * lines of the same product would each be checked against the same
             * stock figure and then both decrement it, so a single unit could
             * satisfy both checks. The till's cart is keyed by product id and
             * cannot produce that, so refusing it costs nothing and closes the
             * hole - the same reasoning as PaymentSplitService refusing the same
             * tender twice.
             */
            'lines.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            // Nullable: a line with no price falls back to the catalogue.
            'lines.*.price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_type' => ['nullable', Rule::in(['percent', 'fixed', 'none'])],
            'lines.*.discount_value' => ['nullable', 'numeric', 'min:0'],

            'order_discount' => ['nullable', 'array'],
            'order_discount.type' => ['nullable', Rule::in(['percent', 'fixed', 'none'])],
            'order_discount.value' => ['nullable', 'numeric', 'min:0'],

            'tenders' => ['required', 'array', 'min:1', 'max:' . PaymentSplitService::MAX_TENDERS],
            'tenders.*.method' => ['required', Rule::in(SalePayment::METHODS)],
            'tenders.*.amount' => ['required', 'numeric', 'min:0'],
            'tenders.*.reference' => ['nullable', 'string', 'max:100'],

            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'on_credit' => ['nullable', 'boolean'],
            'due_date' => ['nullable', 'date'],

            'service_charge' => ['nullable', 'array'],
            'service_charge.type' => ['nullable', Rule::in(['percent', 'fixed', 'none'])],
            'service_charge.value' => ['nullable', 'numeric', 'min:0'],
            'service_charge.label' => ['nullable', 'string', 'max:60'],

            /*
             * What the till told the customer the sale came to. Validated loosely
             * because it is never used: it is stored with the attempt so a human
             * can compare it against the banked figure, and that is all. If this
             * and the recomputed total ever disagree, the recomputed one is right.
             */
            'claimed_total' => ['nullable', 'numeric'],
        ];
    }

    /**
     * Refusals a cashier might actually see, in words rather than field names.
     *
     * Only the ones a working till can plausibly produce are reworded. The rest
     * fall back to Laravel's wording, which is clumsy on a till screen but
     * accurate, and only ever appears when a payload is malformed - at which
     * point the payload itself is on the attempt row for somebody to read.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'client_uuid.required' => 'This sale arrived with no id, so it cannot be banked safely.',
            'client_uuid.size' => 'This sale\'s id is not a valid one.',
            'client_uuid.regex' => 'This sale\'s id is not a valid one.',
            'rung_up_at.required' => 'This sale does not say when it was rung up.',
            'lines.required' => 'This sale has nothing on it.',
            'lines.min' => 'This sale has nothing on it.',
            'lines.*.product_id.distinct' => 'The same ' . strtolower(term('product'))
                . ' is on this sale twice. Put the whole quantity on one line.',
            'lines.*.quantity.min' => 'A line must be for at least one ' . strtolower(term('product')) . '.',
            'tenders.required' => 'This sale does not say how it was paid.',
            'tenders.*.method.in' => 'This sale was paid by a method this till does not know.',
            'customer_id.exists' => 'The ' . strtolower(term('customer')) . ' on this sale no longer exists.',
            'cashier_id.exists' => 'The cashier this sale belongs to no longer exists.',
        ];
    }

    /**
     * Why this timestamp cannot be trusted, or null if it can.
     *
     * A wrong clock is the one offline failure the server can actually detect, so
     * it is worth detecting: a sale dated six months out lands in the wrong Z
     * report, the wrong day's takings and the wrong tax period, and nobody would
     * think to look for it.
     */
    private function clockRefusal(Carbon $at): ?string
    {
        if ($at->isAfter(now()->addMinutes(self::FUTURE_TOLERANCE_MINUTES))) {
            return 'This sale is dated in the future (' . $at->format('d M Y H:i')
                . '), so the till\'s clock is wrong. Fix the clock and sync again.';
        }

        $hours = $this->maxAgeHours();

        if ($hours > 0 && $at->lt(now()->subHours($hours))) {
            return 'This sale was rung up on ' . $at->format('d M Y H:i')
                . ', which is older than this shop allows an offline sale to be banked automatically.';
        }

        return null;
    }

    /**
     * Which drawer a replayed sale belongs to.
     *
     * Only a shift that is still open and still belongs to this cashier is
     * stamped. A closed one is left alone and noted instead: its expected figures
     * were frozen when it was signed off (ShiftService::close), so writing a sale
     * into it now would contradict a reconciliation someone has already approved
     * without any screen saying so.
     *
     * @param  array<int, string>  $notes
     */
    private function shiftFor($shiftId, User $cashier, array &$notes): ?Shift
    {
        if (empty($shiftId)) {
            return null;
        }

        $shift = Shift::find((int) $shiftId);

        if (!$shift || (int) $shift->user_id !== (int) $cashier->id) {
            $notes[] = 'The shift this was rung up on could not be found, so it is not counted into any drawer.';

            return null;
        }

        if (!$shift->isOpen()) {
            $notes[] = sprintf(
                'Rung up during shift #%d, which has since been closed and signed off, so this sale is not counted into that drawer. The money is in the day\'s takings but in no reconciliation - check it against the drawer by hand.',
                $shift->id
            );

            return null;
        }

        return $shift;
    }

    /**
     * An order-level discount in the shape SaleCalculator wants, or null.
     *
     * `'none'` is what the till sends for "no discount", and SaleCalculator reads
     * it as zero anyway; normalising to null here keeps a meaningless type off
     * the sale row.
     *
     * @param  array<string, mixed>|null  $discount
     * @return array{type: string, value: float}|null
     */
    private function discountFrom(?array $discount): ?array
    {
        $type = $discount['type'] ?? null;
        $value = $discount['value'] ?? null;

        if (!is_string($type) || $type === 'none' || !is_numeric($value) || (float) $value == 0.0) {
            return null;
        }

        return ['type' => $type, 'value' => (float) $value];
    }

    /**
     * Bank the sale anyway when stock has gone since? Default yes.
     *
     * Off by default would mean a shop losing takings the first time two tills
     * were offline at once, which is the exact situation this feature exists for.
     * It is a setting rather than a silent choice because a shop that would
     * rather chase the stock than the money is entitled to say so.
     */
    private function allowsNegativeStock(): bool
    {
        return (string) setting('offline_allow_negative_stock', '1') !== '0';
    }

    /** The window a queued sale may be banked within. 0 switches the check off. */
    private function maxAgeHours(): int
    {
        return max(0, (int) setting('offline_max_queue_age_hours', 168));
    }

    /** How far a queued price may sit from today's price. See CheckoutService::priceFor(). */
    private function priceTolerance(): float
    {
        $tolerance = setting('offline_price_tolerance');

        return is_numeric($tolerance)
            ? max(0.0, (float) $tolerance)
            : CheckoutService::DEFAULT_PRICE_TOLERANCE;
    }

    /**
     * Write the refusal down, then answer it.
     *
     * A rejection that is not recorded is a sale that vanished, which for money
     * is the one outcome that must not be possible. The payload is stored exactly
     * as it arrived so the sale can be read back and re-entered by hand.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function reject(array $payload, ?string $uuid, User $syncedBy, Carbon $receivedAt, string $reason): array
    {
        $attempt = $this->record($payload, $uuid, $syncedBy, $receivedAt, [
            'status' => OfflineSaleAttempt::REJECTED,
            'reason' => $reason,
        ]);

        AuditLog::record('Refused an offline sale: ' . $reason, 'offline_sale_attempts', $attempt->id);

        return $this->verdict(OfflineSaleAttempt::REJECTED, $uuid, [
            'reason' => $reason,
            'attempt_id' => $attempt->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $verdict
     */
    private function record(array $payload, ?string $uuid, User $syncedBy, Carbon $receivedAt, array $verdict): OfflineSaleAttempt
    {
        return OfflineSaleAttempt::create(array_merge([
            /*
             * A payload with no readable uuid still gets a row - it is still a
             * sale somebody took money for. The placeholder makes it findable
             * without pretending it is a real device id.
             */
            'client_uuid' => $uuid ?? 'unreadable',
            'user_id' => $syncedBy->id,
            'payload' => $payload,
            'received_at' => $receivedAt,
        ], $verdict));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function verdict(string $status, ?string $uuid, array $extra = []): array
    {
        return array_merge([
            'client_uuid' => $uuid,
            'status' => $status,
            'reason' => null,
            'sale_id' => null,
            'invoice_number' => null,
            'notes' => [],
        ], $extra);
    }
}
