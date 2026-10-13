<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\Sale;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Selling on credit, and collecting the money afterwards.
 *
 * The one place that decides how much of a sale is still owed and how an
 * incoming payment is spread over the invoices it settles. Nothing else may
 * write `sales.balance_due` or `sales.payment_status`.
 *
 * Two rules worth knowing before reading on:
 *
 *  - A payment is applied to the *oldest* unpaid invoice first. That is what a
 *    customer expects ("I paid off last month"), and it is what makes the
 *    overdue list shrink from the top instead of the middle.
 *
 *  - An overpayment is refused, not held as a credit balance. There is nowhere
 *    in this app for money that is owed *to* a customer: `balance_due` is
 *    non-negative by construction, Sale::outstanding() floors at zero, and the
 *    receivables report has no column for a negative figure. Accepting it would
 *    mean the shop quietly holding money the customer cannot see or spend. The
 *    cashier is told what is owed and types that instead.
 *
 * `sales.paid_amount` is deliberately left alone by applyPayment(): it means
 * "tendered at the till when the sale was rung up", which is what the shift's
 * cash count is reconciled against. Money collected later lives in
 * customer_payments, so total collected = paid_amount + sum(creditAllocations).
 */
class CreditService
{
    /**
     * Record what was actually collected at the till for a sale, and leave the
     * rest on the customer's account.
     *
     * The till calls this after creating the sale, inside its own transaction:
     * anything this throws rolls the whole checkout back.
     *
     * @param float $tendered what the customer handed over now (0 = all on account)
     *
     * @throws DomainException when the sale cannot go on credit
     */
    public function recordCreditSale(Sale $sale, float $tendered): void
    {
        $grandTotal = round((float) $sale->grand_total, 2);
        $tendered = round($tendered, 2);

        if ($tendered < 0) {
            throw new DomainException('Amount paid cannot be negative.');
        }

        // Anything above the total is change, not credit, and the till works that out.
        $collected = min($tendered, $grandTotal);
        $balance = round($grandTotal - $collected, 2);

        if ($balance > 0) {
            if ($sale->customer_id === null) {
                // Debt nobody owes is money lost: there would be no account to collect it from.
                throw new DomainException('Choose a ' . strtolower(term('customer')) . ' before putting part of a sale on credit.');
            }

            $customer = Customer::lockForUpdate()->findOrFail($sale->customer_id);

            if (!$customer->is_active) {
                throw new DomainException($customer->name . ' is not active and cannot buy on credit.');
            }

            // Checked again here even though the till asks first: the limit may have
            // been reached by another cashier between the two moments.
            $this->assertCanTakeCredit($customer, $balance, $sale);
        }

        $sale->paid_amount = $collected;
        $sale->balance_due = $balance;
        $sale->payment_status = $this->statusFor($grandTotal, $balance);
        $sale->save();

        if ($balance > 0) {
            AuditLog::record('Sold on credit ' . $sale->invoice_number . ' (' . money($balance) . ' owed)', 'sales', $sale->id);
        }
    }

    /**
     * Take money off what a customer owes.
     *
     * Spreads the payment over their unpaid invoices oldest first, writing one
     * allocation row per invoice touched, or over one named invoice when the
     * cashier picked it. The invoices are locked for the duration, so two people
     * taking a payment at once cannot both settle the same one.
     *
     * @param ?Sale $sale allocate to this invoice only, instead of oldest-first
     *
     * @throws DomainException when the amount, method or invoice is not acceptable
     */
    public function applyPayment(
        Customer $customer,
        float $amount,
        string $method,
        User $user,
        ?Sale $sale = null,
        ?string $reference = null,
        ?string $note = null,
        $paidAt = null
    ): CustomerPayment {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new DomainException('A payment must be more than zero.');
        }

        if (!in_array($method, CustomerPayment::METHODS, true)) {
            throw new DomainException('Unknown payment method.');
        }

        return DB::transaction(function () use ($customer, $amount, $method, $user, $sale, $reference, $note, $paidAt) {
            $query = Sale::query()
                ->where('customer_id', $customer->id)
                ->outstanding()
                ->orderBy('created_at')
                ->orderBy('id');

            if ($sale !== null) {
                if ((int) $sale->customer_id !== (int) $customer->id) {
                    throw new DomainException('That invoice belongs to someone else.');
                }

                $query->where('id', $sale->id);
            }

            $sales = $query->lockForUpdate()->get();

            if ($sales->isEmpty()) {
                throw new DomainException($sale !== null
                    ? 'That invoice has nothing left to pay.'
                    : $customer->name . ' has nothing outstanding.');
            }

            $owed = round((float) $sales->sum('balance_due'), 2);

            // Refused rather than held as a credit balance - see the class docblock.
            if ($amount > $owed + 0.004) {
                throw new DomainException('That is more than is owed. ' . ($sale !== null
                    ? 'This invoice has ' . money($owed) . ' left to pay.'
                    : money($owed) . ' is outstanding.'));
            }

            $payment = CustomerPayment::create([
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'note' => $note,
                'paid_at' => $paidAt ?: now(),
            ]);

            foreach ($this->allocate($amount, $sales) as [$target, $share]) {
                CustomerPaymentAllocation::create([
                    'customer_payment_id' => $payment->id,
                    'sale_id' => $target->id,
                    'amount' => $share,
                ]);

                $this->setBalance($target, round((float) $target->balance_due - $share, 2));
            }

            AuditLog::record('Received ' . money($amount) . ' from ' . $customer->name, 'customer_payments', $payment->id);

            return $payment;
        });
    }

    /**
     * Undo a payment: every invoice it settled goes back to what it owed before,
     * and the payment and its allocations disappear.
     *
     * @throws DomainException when an invoice it touched has since been voided
     */
    public function reversePayment(CustomerPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $payment = CustomerPayment::lockForUpdate()->findOrFail($payment->id);
            $customer = $payment->customer;

            foreach ($payment->allocations()->get() as $allocation) {
                $sale = Sale::lockForUpdate()->find($allocation->sale_id);

                if ($sale === null) {
                    continue;
                }

                $this->setBalance($sale, round((float) $sale->balance_due + (float) $allocation->amount, 2));
            }

            // Allocations cascade with the payment, so nothing is left pointing at it.
            $payment->delete();

            AuditLog::record(
                'Reversed ' . money($payment->amount) . ' payment from ' . ($customer->name ?? 'a customer'),
                'customer_payments',
                $payment->id
            );
        });
    }

    /** What one customer owes across every unpaid invoice. */
    public function balanceFor(Customer $customer): float
    {
        return round((float) $customer->sales()->outstanding()->sum('balance_due'), 2);
    }

    /**
     * The same figure for many customers in one query, for a list page.
     *
     * @param  iterable<int>  $customerIds
     * @return array<int, float> customer id => balance (ids with nothing owing are absent)
     */
    public function balancesFor(iterable $customerIds): array
    {
        $ids = array_values(array_unique(array_map('intval', is_array($customerIds) ? $customerIds : iterator_to_array($customerIds))));

        if (empty($ids)) {
            return [];
        }

        return Sale::query()
            ->outstanding()
            ->whereIn('customer_id', $ids)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(balance_due) as owed')
            ->pluck('owed', 'customer_id')
            ->map(fn($owed) => round((float) $owed, 2))
            ->all();
    }

    /**
     * How much more this customer may take on credit, or null when they have no
     * limit (credit_limit 0 means "no limit", so a shop need not set one).
     */
    public function remainingCredit(Customer $customer, ?Sale $excluding = null): ?float
    {
        $limit = round((float) $customer->credit_limit, 2);

        if ($limit <= 0) {
            return null;
        }

        $owed = $customer->sales()->outstanding()
            ->when($excluding !== null, fn($q) => $q->where('id', '!=', $excluding->id))
            ->sum('balance_due');

        return round($limit - (float) $owed, 2);
    }

    /**
     * Can the till put this much more on the customer's account? The question the
     * till asks before offering "pay later" at all.
     *
     * @param ?Sale $excluding the sale being rung up, so its own balance is not counted twice
     */
    public function canTakeCredit(Customer $customer, float $amount, ?Sale $excluding = null): bool
    {
        if (!$customer->is_active) {
            return false;
        }

        $remaining = $this->remainingCredit($customer, $excluding);

        return $remaining === null || $remaining + 0.004 >= round($amount, 2);
    }

    /**
     * The same check, as a refusal the user can read.
     *
     * @throws DomainException when the sale would push the customer past their limit
     */
    public function assertCanTakeCredit(Customer $customer, float $amount, ?Sale $excluding = null): void
    {
        if ($this->canTakeCredit($customer, $amount, $excluding)) {
            return;
        }

        $remaining = $this->remainingCredit($customer, $excluding);

        throw new DomainException(sprintf(
            '%s would go past their %s credit limit: only %s left.',
            $customer->name,
            money((float) $customer->credit_limit),
            money(max(0, (float) $remaining))
        ));
    }

    /**
     * Split a payment over invoices, oldest first: each takes what it still owes
     * until the money runs out, and the last invoice touched absorbs any rounding
     * remainder so the allocations add up to the payment exactly.
     *
     * @param  \Illuminate\Support\Collection<int, Sale>  $sales
     * @return array<int, array{0: Sale, 1: float}>
     */
    private function allocate(float $amount, $sales): array
    {
        $plan = [];
        $left = round($amount, 2);

        foreach ($sales as $sale) {
            if ($left <= 0) {
                break;
            }

            $share = round(min($left, round((float) $sale->balance_due, 2)), 2);

            if ($share <= 0) {
                continue;
            }

            $plan[] = [$sale, $share];
            $left = round($left - $share, 2);
        }

        if (empty($plan)) {
            throw new DomainException('There is nothing for this payment to settle.');
        }

        $allocated = round(array_sum(array_column($plan, 1)), 2);

        if ($allocated !== round($amount, 2)) {
            $last = count($plan) - 1;
            $plan[$last][1] = round($plan[$last][1] + (round($amount, 2) - $allocated), 2);
        }

        return $plan;
    }

    /**
     * Take a refund off what a customer still owes, and say how much of it that
     * swallowed.
     *
     * Returning goods on an unpaid invoice reduces the debt before it releases
     * any money. If 600 is still owed on a 1,000 invoice and 300 of goods come
     * back, the customer simply owes 300: nothing is paid out. Bring back 800 of
     * goods and the debt clears and 200 is genuinely handed over.
     *
     * Without this, refunding a credit sale left the whole original debt standing
     * even though the goods were back on the shelf.
     *
     * The caller must already hold a lock on the sale (RefundService does).
     *
     * @param  float  $amount  the value being refunded
     * @return float  how much of that cancelled debt rather than being paid out
     */
    public function applyRefund(Sale $sale, float $amount): float
    {
        $owed = max(0, round((float) $sale->balance_due, 2));

        if ($owed <= 0 || $amount <= 0) {
            return 0.0;
        }

        $cancelled = min(round($amount, 2), $owed);

        $this->setBalance($sale, $owed - $cancelled);

        return $cancelled;
    }

    /**
     * Write a new balance on a sale, with the payment status that goes with it.
     */
    private function setBalance(Sale $sale, float $balance): void
    {
        $balance = max(0, round($balance, 2));

        $sale->balance_due = $balance;
        $sale->payment_status = $this->statusFor(round((float) $sale->grand_total, 2), $balance);
        $sale->save();
    }

    /** paid / partial / unpaid, from what is left against what the sale came to. */
    private function statusFor(float $grandTotal, float $balance): string
    {
        if ($balance <= 0) {
            return Sale::PAYMENT_PAID;
        }

        return $balance >= $grandTotal ? Sale::PAYMENT_UNPAID : Sale::PAYMENT_PARTIAL;
    }
}
