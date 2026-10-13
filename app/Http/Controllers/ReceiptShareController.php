<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\ReceiptShareService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Sending a receipt to the customer instead of handing over paper.
 *
 * WhatsApp needs nothing from here - ReceiptShareService::whatsappUrl() returns
 * a plain https://wa.me/ link that a view renders as an anchor. Only email needs
 * a request, because it is a state-changing action and wants CSRF protection.
 */
class ReceiptShareController extends Controller
{
    public function email(Request $request, Sale $sale, ReceiptShareService $share)
    {
        // The same rule that guards viewing the receipt guards sending it: a
        // cashier may only reach their own sales from today (see SalePolicy).
        Gate::authorize('view', $sale);

        $data = $request->validate([
            // Optional: falls back to the customer's stored address when absent,
            // so a till can send to an address typed in on the spot.
            'email' => 'nullable|email|max:255',
        ]);

        try {
            $sentTo = $share->email($sale, $data['email'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Receipt sent to {$sentTo}.");
    }
}
