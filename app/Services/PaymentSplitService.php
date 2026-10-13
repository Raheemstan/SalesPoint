<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SalePayment;
use DomainException;

/**
 * One sale, several tenders: part cash, part card, part transfer, and sometimes
 * part left on the customer's account.
 *
 * Three rules do all the work here, and each exists because the obvious
 * alternative loses money:
 *
 *  - **Change comes out of the cash only.** You cannot hand back change from a
 *    card payment, so in a split the non-cash tenders may never come to more
 *    than the sale does. Without that rule a cashier can key "cash 0, transfer
 *    10,000" against a 2,000 sale and the till will cheerfully open the drawer
 *    for 8,000. The ceiling applies to a *split* only: with a single tender,
 *    `paid_amount` has always meant "the amount received", the till has always
 *    given change against it whatever the method, and that behaviour predates
 *    this service. Needing two rows to express it is what makes the rule
 *    enforceable where it matters without changing the old path.
 *
 *  - **A row records money kept, not money held out.** A 215 sale settled with a
 *    300 note writes a cash row of 215; the 85 lives in `sales.change_due`.
 *    The drawer holds what it kept, and ShiftService reconciles against these
 *    rows, so any other reading makes every till with change in it look over.
 *
 *  - **One row per method.** Two separate cash tenders on one sale are not a
 *    thing a counter does, and allowing them would make "which tender did the
 *    change come out of?" ambiguous. Unique methods keep the breakdown exactly
 *    the shape the shift report groups by.
 *
 * Money is rounded at each step and the rows are reconciled against the total
 * before they are written, so the split adds up to the penny or nothing is
 * written at all. The arithmetic is the same discipline as SaleCalculator, and
 * the amounts it works from are SaleCalculator's - nothing here recomputes a
 * discount or a tax.
 */
class PaymentSplitService
{
    /** The tenders a till may take. Same whitelist as Shift::METHODS, deliberately. */
    public const METHODS = SalePayment::METHODS;

    /** More than one tender per method is refused, so this is the real ceiling. */
    public const MAX_TENDERS = 3;

    /**
     * Turn what the cashier keyed into what will be written, or refuse it.
     *
     * Nothing is taken on trust: the grand total is the one SaleCalculator
     * produced server-side, and every amount below is derived from it.
     *
     * @param  array<int, array{method?: mixed, amount?: mixed, reference?: mixed}>  $tenders
     * @param  float  $grandTotal  what the sale comes to (from SaleCalculator)
     * @param  bool  $allowCredit  may the tenders fall short, with the rest going on account?
     * @return array{
     *     tenders: array<int, array{method: string, amount: float, applied: float, reference: ?string}>,
     *     tendered: float, applied: float, change: float, credit: float, dominant: string
     * }
     *
     * @throws DomainException when the tenders cannot settle this sale
     */
    public function plan(array $tenders, float $grandTotal, bool $allowCredit = false): array
    {
        $grandTotal = round($grandTotal, 2);
        $rows = $this->normalise($tenders);

        $tendered = round(array_sum(array_column($rows, 'amount')), 2);
        $nonCash = round(array_sum(array_map(
            fn (array $row) => $row['method'] === 'cash' ? 0.0 : $row['amount'],
            $rows
        )), 2);

        // Change can only come out of the drawer, so in a split the card and
        // transfer parts may settle the sale but never exceed it. See ceiling().
        if (count($rows) > 1 && $nonCash > $grandTotal + 0.004) {
            throw new DomainException(sprintf(
                'Change can only be given in cash, so the card and transfer payments cannot come to more than the %s total of %s.',
                strtolower(term('sale')),
                money($grandTotal)
            ));
        }

        /*
         * The same refusal, and the same words, as before split payments existed:
         * short of the total and nothing to put the rest on.
         */
        if ($tendered < $grandTotal - 0.004 && !$allowCredit) {
            throw new DomainException('Insufficient payment.');
        }

        $change = max(0.0, round($tendered - $grandTotal, 2));
        $applied = round($tendered - $change, 2);
        $credit = max(0.0, round($grandTotal - $tendered, 2));

        $rows = $this->applyChange($rows, $change, $applied);

        return [
            'tenders' => $rows,
            'tendered' => $tendered,
            'applied' => $applied,
            'change' => $change,
            'credit' => $credit,
            'dominant' => $this->dominant($rows),
        ];
    }

    /**
     * Write one `sale_payments` row per tender that brought money in.
     *
     * A tender whose whole amount went back out as change, and a sale taken
     * entirely on account, write nothing: no money arrived, and a zero row would
     * only invite someone to read it as a payment that did.
     *
     * The caller is inside the checkout transaction, so these roll back with the
     * sale if anything after them throws.
     *
     * @param  array<string, mixed>  $plan  as returned by plan()
     */
    public function record(Sale $sale, array $plan): void
    {
        foreach ($plan['tenders'] as $tender) {
            if ($tender['applied'] <= 0) {
                continue;
            }

            SalePayment::create([
                'sale_id' => $sale->id,
                'method' => $tender['method'],
                'amount' => $tender['applied'],
                'reference' => $tender['reference'],
            ]);
        }
    }

    /**
     * Check the whitelist, the signs and the duplicates, and round every amount
     * before a single sum is taken.
     *
     * @param  array<int, array{method?: mixed, amount?: mixed, reference?: mixed}>  $tenders
     * @return array<int, array{method: string, amount: float, applied: float, reference: ?string}>
     */
    private function normalise(array $tenders): array
    {
        $tenders = array_values($tenders);

        if (empty($tenders)) {
            throw new DomainException('Enter how the ' . strtolower(term('sale')) . ' was paid.');
        }

        if (count($tenders) > self::MAX_TENDERS) {
            throw new DomainException('A ' . strtolower(term('sale')) . ' can be split over at most '
                . self::MAX_TENDERS . ' payment methods.');
        }

        $rows = [];
        $seen = [];

        foreach ($tenders as $tender) {
            $method = is_string($tender['method'] ?? null) ? $tender['method'] : '';

            if (!in_array($method, self::METHODS, true)) {
                throw new DomainException('Unknown payment method.');
            }

            if (isset($seen[$method])) {
                throw new DomainException(SalePayment::label($method)
                    . ' is entered twice. Put the whole ' . SalePayment::label($method) . ' amount on one line.');
            }

            $seen[$method] = true;

            $amount = $tender['amount'] ?? 0;

            if (!is_numeric($amount)) {
                throw new DomainException('A payment amount must be a number.');
            }

            $amount = round((float) $amount, 2);

            if ($amount < 0) {
                throw new DomainException('A payment cannot be negative.');
            }

            $reference = isset($tender['reference']) && is_string($tender['reference'])
                ? (trim($tender['reference']) ?: null)
                : null;

            $rows[] = [
                'method' => $method,
                'amount' => $amount,
                // Filled in by applyChange() once the change is known.
                'applied' => $amount,
                'reference' => $reference,
            ];
        }

        return $rows;
    }

    /**
     * Take the change off the cash, and prove the rows add up.
     *
     * The change comes off the cash row, because that is the only tender it can
     * physically come out of. When there is no cash row at all it comes off the
     * largest one instead: that is only reachable on the single-tender path,
     * where a lone card or transfer amount has always been allowed to exceed the
     * sale and have change given against it, and the row still has to end up
     * holding what the sale actually took.
     *
     * With one row per method there is at most one cash row, so this is exact
     * arithmetic rather than an allocation. The reconciliation afterwards is a
     * guard, not a fudge: if the rows ever failed to sum to what was applied,
     * the drawer figures would drift by that amount for the rest of the shift,
     * so it is checked here rather than discovered at closing time.
     *
     * @param  array<int, array{method: string, amount: float, applied: float, reference: ?string}>  $rows
     * @return array<int, array{method: string, amount: float, applied: float, reference: ?string}>
     */
    private function applyChange(array $rows, float $change, float $applied): array
    {
        if ($change > 0) {
            $target = $this->indexOfCash($rows) ?? $this->indexOfLargest($rows);

            $rows[$target]['applied'] = round($rows[$target]['amount'] - $change, 2);
        }

        $sum = round(array_sum(array_column($rows, 'applied')), 2);

        if ($sum !== $applied) {
            // Put the difference where it is proportionally smallest.
            $largest = $this->indexOfLargest($rows);
            $rows[$largest]['applied'] = round($rows[$largest]['applied'] + ($applied - $sum), 2);
        }

        foreach ($rows as $row) {
            if ($row['applied'] < 0) {
                /*
                 * Only reachable if the change exceeded the tender it came out of,
                 * which cannot happen: the change is the tendered total less the
                 * sale, and the split ceiling keeps the non-cash side below the
                 * sale. Refuse rather than write a negative into the drawer.
                 */
                throw new DomainException('Change cannot be given out of a card or transfer payment.');
            }
        }

        return $rows;
    }

    /** @param  array<int, array{method: string}>  $rows */
    private function indexOfCash(array $rows): ?int
    {
        foreach ($rows as $i => $row) {
            if ($row['method'] === 'cash') {
                return $i;
            }
        }

        return null;
    }

    /** @param  array<int, array{applied: float}>  $rows */
    private function indexOfLargest(array $rows): int
    {
        $largest = 0;

        foreach ($rows as $i => $row) {
            if ($row['applied'] > $rows[$largest]['applied']) {
                $largest = $i;
            }
        }

        return $largest;
    }

    /**
     * The tender that gets written to `sales.payment_method`.
     *
     * That column is read by the sales list filter, the cashier report, the
     * receipt and the old per-method drawer fallback, none of which know about
     * splits. Rather than leave it null or invent a "split" value that every one
     * of them would have to learn, it carries the **largest** tender: the answer
     * to "how was this mostly paid?", which is what each of those screens is
     * really asking. The full breakdown is in `sale_payments`.
     *
     * A tie goes to whichever the cashier keyed first. A sale taken wholly on
     * account has no money on any tender, so the first method keyed stands.
     *
     * @param  array<int, array{method: string, applied: float}>  $rows
     */
    private function dominant(array $rows): string
    {
        $best = null;

        foreach ($rows as $row) {
            // Strictly greater, so the first of equal tenders wins.
            if ($best === null || $row['applied'] > $best['applied']) {
                $best = $row;
            }
        }

        return $best['method'] ?? 'cash';
    }
}
