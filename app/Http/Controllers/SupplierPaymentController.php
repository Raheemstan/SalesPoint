<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Money paid to a supplier against what is owed them.
 *
 * A payment is not tied to one invoice: shops pay a round figure off a running
 * balance. The balance itself is derived from these rows, the purchases and the
 * returns (see App\Services\SupplierLedgerService), so recording a payment is
 * one insert and reversing one is one delete - there is no stored total to keep
 * in step and therefore none to get out of step.
 */
class SupplierPaymentController extends Controller
{
    public function store(Request $request, Supplier $supplier)
    {
        Gate::authorize('pay', $supplier);

        $validated = $request->validate([
            'amount'    => 'required|numeric|min:0.01|max:999999999999',
            'method'    => 'required|in:' . implode(',', SupplierPayment::METHODS),
            'reference' => 'nullable|string|max:255',
            'note'      => 'nullable|string|max:255',
            'paid_at'   => 'nullable|date',
        ]);

        try {
            DB::transaction(function () use ($validated, $supplier) {
                $payment = SupplierPayment::create([
                    'supplier_id' => $supplier->id,
                    'user_id'     => userId(),
                    'amount'      => round((float) $validated['amount'], 2),
                    'method'      => $validated['method'],
                    'reference'   => $validated['reference'] ?? null,
                    'note'        => $validated['note'] ?? null,
                    'paid_at'     => $validated['paid_at'] ?? now(),
                ]);

                AuditLog::record(
                    'Paid ' . money($payment->amount) . ' to ' . $supplier->name,
                    'supplier_payments',
                    $payment->id
                );
            });
        } catch (\Throwable $e) {
            Log::error('Supplier payment failed', ['error' => $e->getMessage()]);
            return redirect()->back()->withInput()->with('error', 'Failed to record the payment.');
        }

        return redirect()->route('suppliers.show', $supplier)->with('success', 'Payment recorded.');
    }

    /** Reverse a payment entered in error: the balance goes straight back up. */
    public function destroy(SupplierPayment $payment)
    {
        $supplier = $payment->supplier;

        Gate::authorize('pay', $supplier);

        try {
            DB::transaction(function () use ($payment, $supplier) {
                AuditLog::record(
                    'Reversed a ' . money($payment->amount) . ' payment to ' . $supplier->name,
                    'supplier_payments',
                    $payment->id
                );

                $payment->delete();
            });
        } catch (\Throwable $e) {
            Log::error('Supplier payment reversal failed', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to reverse the payment.');
        }

        return redirect()->route('suppliers.show', $supplier)->with('success', 'Payment reversed.');
    }
}
