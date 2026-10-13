<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierPayment;

/**
 * What the shop owes each supplier.
 *
 * The balance is derived, never stored, so it cannot drift away from the rows it
 * is made of:
 *
 *     opening_balance
 *   + received purchases        (what they invoiced)
 *   - paid on those purchases   (cash on delivery, a deposit)
 *   - supplier payments         (money sent later, against the account)
 *   - purchase returns          (goods they credited back)
 *
 * Only *received* purchases count. An order that has not arrived is not a debt
 * yet, and nothing paid against it counts either; cancelling one leaves no trace.
 *
 * A positive balance means the shop owes the supplier. A negative one means the
 * shop is in credit with them - paid up front, or returned more than it owed.
 */
class SupplierLedgerService
{
    /** What is owed one supplier right now. */
    public function balance(Supplier $supplier): float
    {
        return $this->balances([$supplier->id])[$supplier->id] ?? 0.0;
    }

    /**
     * Balances for many suppliers in four queries rather than four per row: the
     * supplier list would otherwise fire one set per row it paginates.
     *
     * @param  iterable<int|Supplier>  $suppliers  ids, or models
     * @return array<int, float>  supplier id => balance
     */
    public function balances(iterable $suppliers): array
    {
        $ids = collect($suppliers)
            ->map(fn($s) => $s instanceof Supplier ? (int) $s->id : (int) $s)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $opening = Supplier::whereIn('id', $ids)->pluck('opening_balance', 'id');

        // One row per supplier: invoiced and already-settled, side by side.
        $purchases = Purchase::received()
            ->whereIn('supplier_id', $ids)
            ->selectRaw('supplier_id, COALESCE(SUM(total_amount), 0) AS invoiced, COALESCE(SUM(amount_paid), 0) AS settled')
            ->groupBy('supplier_id')
            ->get()
            ->keyBy('supplier_id');

        $payments = SupplierPayment::whereIn('supplier_id', $ids)
            ->selectRaw('supplier_id, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('supplier_id')
            ->pluck('total', 'supplier_id');

        $returns = PurchaseReturn::whereIn('supplier_id', $ids)
            ->selectRaw('supplier_id, COALESCE(SUM(total_amount), 0) AS total')
            ->groupBy('supplier_id')
            ->pluck('total', 'supplier_id');

        $balances = [];

        foreach ($ids as $id) {
            $purchase = $purchases->get($id);

            $balances[$id] = round(
                (float) $opening->get($id, 0)
                + (float) ($purchase->invoiced ?? 0)
                - (float) ($purchase->settled ?? 0)
                - (float) $payments->get($id, 0)
                - (float) $returns->get($id, 0),
                2
            );
        }

        return $balances;
    }

    /**
     * The numbers behind one supplier's statement, each on its own line, so the
     * screen can show how the balance was arrived at rather than one figure the
     * shopkeeper has to take on trust.
     *
     * @return array{opening: float, invoiced: float, settled: float, payments: float, returns: float, ordered: float, balance: float}
     */
    public function summary(Supplier $supplier): array
    {
        $received = Purchase::received()->where('supplier_id', $supplier->id)
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS invoiced, COALESCE(SUM(amount_paid), 0) AS settled')
            ->first();

        $opening = round((float) $supplier->opening_balance, 2);
        $invoiced = round((float) $received->invoiced, 2);
        $settled = round((float) $received->settled, 2);
        $payments = round((float) SupplierPayment::where('supplier_id', $supplier->id)->sum('amount'), 2);
        $returns = round((float) PurchaseReturn::where('supplier_id', $supplier->id)->sum('total_amount'), 2);

        // Orders still to arrive: not owed yet, but worth knowing what is coming.
        $ordered = round((float) Purchase::where('supplier_id', $supplier->id)
            ->where('status', Purchase::STATUS_ORDERED)->sum('total_amount'), 2);

        return [
            'opening' => $opening,
            'invoiced' => $invoiced,
            'settled' => $settled,
            'payments' => $payments,
            'returns' => $returns,
            'ordered' => $ordered,
            'balance' => round($opening + $invoiced - $settled - $payments - $returns, 2),
        ];
    }
}
