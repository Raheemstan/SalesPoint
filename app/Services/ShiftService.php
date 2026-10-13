<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Till shifts and end-of-day reconciliation.
 *
 * The one number this service exists to produce is the variance: what the drawer
 * actually holds minus what the records say it should. Everything else is there to
 * make that number trustworthy, which means two rules:
 *
 *   - the expected side is always recomputed from the database, never accepted
 *     from the browser; only the *counted* figures come from a person;
 *   - the expected side is frozen into the shift row when it closes, so a later
 *     backdated sale, void or refund cannot rewrite a reconciliation that has
 *     already been signed off.
 *
 * Money is rounded to 2dp at each step, the same discipline as SaleCalculator.
 */
class ShiftService
{
    /**
     * Open a shift for a cashier.
     *
     * One open shift per user is the invariant the whole feature rests on: if a
     * cashier could have two, a sale would not belong to a single drawer and no
     * variance would mean anything.
     *
     * @throws DomainException when the user already has an open shift
     */
    public function open(User $user, float $float, ?string $note = null): Shift
    {
        if ($float < 0) {
            throw new DomainException('The opening float cannot be negative.');
        }

        return DB::transaction(function () use ($user, $float, $note) {
            /*
             * Lock the *user* row, not their shifts: on a first shift there is no
             * shift row to lock, so two requests arriving together would both find
             * nothing open and both insert. Locking the user serialises them.
             */
            User::whereKey($user->id)->lockForUpdate()->first();

            // Re-read inside the lock: whatever the caller saw a moment ago is stale.
            if (Shift::open()->where('user_id', $user->id)->exists()) {
                throw new DomainException('You already have an open shift. Close it before opening another.');
            }

            $shift = Shift::create([
                'user_id' => $user->id,
                'opened_at' => now(),
                'opening_float' => round($float, 2),
                'note' => $note,
                'status' => Shift::STATUS_OPEN,
            ]);

            AuditLog::record('Opened shift #' . $shift->id . ' with a float of ' . money($shift->opening_float), 'shifts', $shift->id);

            return $shift;
        });
    }

    /**
     * The user's open shift, or null.
     *
     * This is what the till stamps onto a sale (`shift_id`), and what the
     * "no shift is open" prompt keys off. One query, kept here so nothing has to
     * re-derive what "the current shift" means.
     */
    public function currentFor(User $user): ?Shift
    {
        return Shift::open()->where('user_id', $user->id)->first();
    }

    /**
     * Does this shop insist on an open shift before anything can be sold?
     *
     * Off by default on purpose. Shifts are a control, not a lock: switching this
     * on in an install that has never used shifts would stop every till until
     * someone works out why, so a shop opts in once it is counting drawers. When
     * it is on, checkout refuses to ring up a sale with no shift open.
     */
    public function requiredToSell(): bool
    {
        return (string) setting('require_open_shift', '0') === '1';
    }

    /**
     * What the drawer should hold, per tender.
     *
     * Returns one row per method so the arithmetic is visible rather than implied:
     *
     *   ['cash' => ['opening_float' => 5000.0, 'sales' => 1200.0, 'refunds' => 200.0, 'expected' => 6000.0], ...]
     *
     * Only cash carries the opening float - a POS terminal does not start the day
     * with change in it.
     *
     * @return array<string, array{opening_float: float, sales: float, refunds: float, expected: float}>
     */
    public function expectedByMethod(Shift $shift): array
    {
        $sales = $this->salesByMethod($shift);
        $refunds = $this->refundsByMethod($shift);

        $out = [];

        foreach (Shift::METHODS as $method) {
            $float = $method === 'cash' ? round((float) $shift->opening_float, 2) : 0.0;

            $out[$method] = [
                'opening_float' => $float,
                'sales' => $sales[$method],
                'refunds' => $refunds[$method],
                'expected' => round($float + $sales[$method] - $refunds[$method], 2),
            ];
        }

        return $out;
    }

    /**
     * Close the shift: freeze the expected figures, store what was counted, mark it done.
     *
     * @param  array<string, float|int|string|null>  $counted  keyed by method (cash|pos|transfer)
     *
     * @throws DomainException when the shift is already closed
     */
    public function close(Shift $shift, array $counted, ?string $note, User $closedBy): Shift
    {
        return DB::transaction(function () use ($shift, $counted, $note, $closedBy) {
            // Lock the row so two people hitting "close" together cannot both sign it off.
            $shift = Shift::lockForUpdate()->findOrFail($shift->id);

            if (!$shift->isOpen()) {
                throw new DomainException('This shift was already closed and cannot be closed again.');
            }

            $expected = $this->expectedByMethod($shift);

            foreach (Shift::METHODS as $method) {
                $shift->{'expected_' . $method} = $expected[$method]['expected'];
                $shift->{'counted_' . $method} = round((float) ($counted[$method] ?? 0), 2);
            }

            $shift->closed_at = now();
            $shift->closed_by = $closedBy->id;
            $shift->close_note = $note;
            $shift->status = Shift::STATUS_CLOSED;
            $shift->save();

            $variance = $shift->variance_total;
            $verdict = $variance == 0.0
                ? 'balanced'
                : ($variance > 0 ? 'over by ' . money($variance) : 'short by ' . money(abs($variance)));

            AuditLog::record('Closed shift #' . $shift->id . ' (' . $verdict . ')', 'shifts', $shift->id);

            return $shift;
        });
    }

    /**
     * Everything the Z report needs, in one array.
     *
     * `expected` is the authoritative figure: the frozen one once the shift is
     * closed, the live one while it is open. `expected_live` is always the live
     * recomputation, and `drifted` says the two disagree - which means something
     * was changed after the shift was signed off and is worth a look.
     */
    public function summarise(Shift $shift): array
    {
        $live = $this->expectedByMethod($shift);
        $frozen = !$shift->isOpen();

        $methods = [];
        $expectedTotal = 0.0;
        $countedTotal = null;
        $varianceTotal = null;
        $drifted = false;

        foreach (Shift::METHODS as $method) {
            $expected = $frozen
                ? round((float) $shift->{'expected_' . $method}, 2)
                : $live[$method]['expected'];

            $raw = $shift->{'counted_' . $method};
            $counted = $raw === null ? null : round((float) $raw, 2);
            $variance = $counted === null ? null : round($counted - $expected, 2);

            $methods[$method] = [
                'label' => Shift::methodLabel($method),
                'opening_float' => $live[$method]['opening_float'],
                'sales' => $live[$method]['sales'],
                'refunds' => $live[$method]['refunds'],
                'expected' => $expected,
                'expected_live' => $live[$method]['expected'],
                'counted' => $counted,
                'variance' => $variance,
            ];

            $expectedTotal += $expected;

            if ($counted !== null) {
                $countedTotal = round(($countedTotal ?? 0.0) + $counted, 2);
                $varianceTotal = round(($varianceTotal ?? 0.0) + $variance, 2);
            }

            if ($frozen && $expected !== $live[$method]['expected']) {
                $drifted = true;
            }
        }

        // Voided sales are not transactions, so they are reported on their own and
        // left out of every figure below.
        $shiftSales = DB::table('sales')->where('shift_id', $shift->id);
        $realSales = (clone $shiftSales)->where('status', '!=', Sale::STATUS_VOIDED);
        $voided = (clone $shiftSales)->where('status', Sale::STATUS_VOIDED);

        $gross = round((float) $realSales->sum('grand_total'), 2);

        // Money handed back out of this drawer, however it went back. A void of one of
        // this shift's own sales is excluded (see refundsByMethod) and shown separately.
        $refunds = round(array_sum(array_column($live, 'refunds')), 2);

        return [
            'sales_count' => $realSales->count(),
            'subtotal' => round((float) $realSales->sum('total_amount'), 2),
            'discounts' => round((float) $realSales->sum('discount_amount'), 2),
            'tax' => round((float) $realSales->sum('tax_amount'), 2),
            'gross' => $gross,
            'refunds' => $refunds,
            'voids_count' => $voided->count(),
            'voids' => round((float) $voided->sum('grand_total'), 2),
            'net' => round($gross - $refunds, 2),
            'methods' => $methods,
            'expected_total' => round($expectedTotal, 2),
            'counted_total' => $countedTotal,
            'variance_total' => $varianceTotal,
            'frozen' => $frozen,
            'drifted' => $drifted,
        ];
    }

    /**
     * Sales takings for the shift, per tender, voided sales excluded.
     *
     * EXTENSION POINT - split payments. A later job adds a `sale_payments` table
     * with one row per tender, so a sale can be half cash and half transfer. This
     * method already prefers those rows: any sale that has them is summed from
     * them, and only sales without them fall back to the single
     * `sales.payment_method` column. Nothing here needs changing when that table
     * lands, as long as it keeps `sale_id`, `method` and `amount`; if it names
     * them differently, this is the one place to adjust.
     *
     * @return array<string, float>
     */
    private function salesByMethod(Shift $shift): array
    {
        $totals = array_fill_keys(Shift::METHODS, 0.0);

        $tendered = $this->hasTenderRows();

        if ($tendered) {
            // Per-tender rows record money actually taken, so they need no adjusting.
            $rows = DB::table('sale_payments')
                ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
                ->where('sales.shift_id', $shift->id)
                ->where('sales.status', '!=', Sale::STATUS_VOIDED)
                ->groupBy('sale_payments.method')
                ->selectRaw('sale_payments.method as method, SUM(sale_payments.amount) as total')
                ->get();

            $this->addRows($totals, $rows);
        }

        /*
         * What the drawer actually took for this sale: the money handed over,
         * less any change given back. For an ordinary sale that comes to the
         * grand total. For a credit sale it is just the deposit, because the rest
         * was never paid.
         *
         * Deliberately NOT `grand_total - balance_due`: once a refund cancels
         * part of a debt, balance_due falls without any money arriving, so that
         * form would quietly inflate the takings by the amount written off.
         * `paid_amount` only ever moves when cash does.
         */
        $takings = 'SUM(sales.paid_amount - COALESCE(sales.change_due, 0))';

        $query = DB::table('sales')
            ->where('sales.shift_id', $shift->id)
            ->where('sales.status', '!=', Sale::STATUS_VOIDED)
            ->groupBy('sales.payment_method')
            ->selectRaw('sales.payment_method as method, ' . $takings . ' as total');

        if ($tendered) {
            // Only the sales that have no tender rows of their own.
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('sale_payments')
                ->whereColumn('sale_payments.sale_id', 'sales.id'));
        }

        $this->addRows($totals, $query->get());

        return $totals;
    }

    /**
     * Refunds paid out of this drawer, per tender.
     *
     * A refund belongs to the shift that handed the money over, which is not
     * necessarily the shift that sold the goods - a manager can refund yesterday's
     * sale out of today's till.
     *
     * @return array<string, float>
     */
    private function refundsByMethod(Shift $shift): array
    {
        $totals = array_fill_keys(Shift::METHODS, 0.0);

        $rows = DB::table('refunds')
            ->join('sales', 'sales.id', '=', 'refunds.sale_id')
            ->where(function ($q) use ($shift) {
                $q->where('refunds.shift_id', $shift->id)
                    /*
                     * Bridge for refunds taken before the refund flow stamps
                     * shift_id: an unstamped refund of this shift's own sale, taken
                     * while the shift was open, plainly came out of this drawer.
                     * Narrow on purpose - it never claims another shift's refund.
                     */
                    ->orWhere(fn ($inner) => $inner
                        ->whereNull('refunds.shift_id')
                        ->where('sales.shift_id', $shift->id)
                        ->whereBetween('refunds.created_at', [$shift->opened_at, $shift->closed_at ?? now()]));
            })
            /*
             * A void of a sale this same shift rang up is the mirror image of that
             * sale: the money came in and went straight back out. The sale is
             * already excluded as voided, so subtracting the refund as well would
             * leave the drawer looking short by the whole amount. A void of an
             * *earlier* shift's sale is real money leaving this drawer, and counts.
             *
             * (One known simplification: a same-shift void refunded by a different
             * tender than it was taken on nets to zero overall but leaves the
             * per-method split out by that amount. A void is an immediate
             * correction, so in practice the money goes back the way it came.)
             */
            ->where(fn ($q) => $q
                ->where('refunds.is_void', false)
                ->orWhereNull('sales.shift_id')
                ->orWhere('sales.shift_id', '!=', $shift->id))
            ->groupBy('refunds.method')
            ->selectRaw('refunds.method as method, ' . $this->refundCashOut() . ' as total')
            ->get();

        $this->addRows($totals, $rows);

        return $totals;
    }

    /**
     * How much of a refund actually left the drawer.
     *
     * Refunding goods on a sale that was taken on credit cancels the debt before
     * it releases any cash, so the part recorded in `credit_applied` never
     * reached the customer's hand. Counting it as money paid out would leave
     * every such shift looking short.
     *
     * Guarded because that column arrives in a later migration than this one.
     */
    private function refundCashOut(): string
    {
        return Schema::hasColumn('refunds', 'credit_applied')
            ? 'SUM(refunds.total_amount - COALESCE(refunds.credit_applied, 0))'
            : 'SUM(refunds.total_amount)';
    }

    /**
     * Fold "method => total" rows into the running totals, ignoring any unknown tender.
     *
     * @param  array<string, float>  $totals
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     */
    private function addRows(array &$totals, $rows): void
    {
        foreach ($rows as $row) {
            if (array_key_exists($row->method, $totals)) {
                $totals[$row->method] = round($totals[$row->method] + (float) $row->total, 2);
            }
        }
    }

    /** Does the split-payment table exist yet, with the columns this service reads? */
    private function hasTenderRows(): bool
    {
        return Schema::hasTable('sale_payments')
            && Schema::hasColumn('sale_payments', 'method')
            && Schema::hasColumn('sale_payments', 'amount');
    }
}
