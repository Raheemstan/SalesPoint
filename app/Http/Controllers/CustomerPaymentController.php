<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Services\CreditService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Payments a customer makes against what they owe.
 *
 * The money arithmetic - which invoices a payment settles, and in what order -
 * all lives in CreditService. Nothing here trusts a figure from the browser
 * beyond "how much arrived": the balances it is applied to come from the
 * database and are locked while they are written.
 */
class CustomerPaymentController extends Controller
{
    public function store(Request $request, Customer $customer, CreditService $credit)
    {
        Gate::authorize('create', CustomerPayment::class);

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:99999999999999',
            'method' => 'required|in:' . implode(',', CustomerPayment::METHODS),
            'reference' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:255',
            // Optional: settle one named invoice instead of the oldest ones first.
            'sale_id' => 'nullable|integer|exists:sales,id',
            'paid_at' => 'nullable|date',
        ]);

        // Resolved here so CreditService can check it belongs to this customer.
        $sale = isset($data['sale_id']) ? Sale::find($data['sale_id']) : null;

        try {
            $payment = $credit->applyPayment(
                $customer,
                (float) $data['amount'],
                $data['method'],
                $request->user(),
                $sale,
                $data['reference'] ?? null,
                $data['note'] ?? null,
                $data['paid_at'] ?? null,
            );
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $left = $credit->balanceFor($customer);

        return redirect()->route('customers.show', $customer)->with(
            'success',
            money($payment->amount) . ' received from ' . $customer->name . '. '
                . ($left > 0 ? money($left) . ' still owing.' : 'Account settled.')
        );
    }

    /** Reverse a payment: every invoice it settled goes back to what it owed before. */
    public function destroy($payment, CreditService $credit)
    {
        $payment = CustomerPayment::findOrFail($payment);

        Gate::authorize('delete', $payment);

        $customer = $payment->customer;

        try {
            $credit->reversePayment($payment);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('customers.show', $customer)
            ->with('success', 'Payment of ' . money($payment->amount) . ' reversed.');
    }
}
