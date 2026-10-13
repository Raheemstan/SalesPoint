<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleItemSerial;
use App\Services\SerialService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Serial / IMEI numbers after the fact.
 *
 * The till will capture serials as part of checkout (it calls
 * SerialService::recordForSale()). This controller is what makes the feature
 * usable without waiting for that, and what is still needed afterwards: a
 * manager entering the numbers for a sale that was rung up before the shop
 * started recording them, correcting a mistyped IMEI, and - the reason any of it
 * is recorded - looking a serial up to find the sale it went out on.
 *
 * Everything is written through SerialService so the two rules (one serial per
 * unit sold, and no unit sold twice) hold wherever serials come from.
 */
class SerialController extends Controller
{
    public function __construct(private SerialService $serials) {}

    /**
     * The warranty question: "when did this go out, and on whose sale?"
     *
     * An exact match is what is wanted; a partial one is offered as a fallback
     * because serials are read off a worn label and the last few characters are
     * what usually survive.
     */
    public function index(Request $request)
    {
        $this->assertEnabled();
        Gate::authorize('viewAny', SaleItemSerial::class);

        $request->validate(['serial' => 'nullable|string|max:120']);

        $search = trim((string) $request->input('serial', ''));

        $matches = $this->serials->lookup($search);

        $partial = ($search !== '' && $matches->isEmpty())
            ? SaleItemSerial::with(['product', 'saleItem.sale.user', 'saleItem.sale.customer'])
                ->like($search)->latest()->limit(20)->get()
            : collect();

        $recent = $search === ''
            ? SaleItemSerial::with(['product', 'saleItem.sale.user', 'saleItem.sale.customer'])
                ->latest()->limit(20)->get()
            : collect();

        return view('serials.index', compact('search', 'matches', 'partial', 'recent'));
    }

    /** Enter or correct the serials for every line of one sale. */
    public function edit(Sale $sale)
    {
        $this->assertEnabled();
        Gate::authorize('viewAny', SaleItemSerial::class);

        $sale->load(['items.product', 'items.serials', 'user']);

        $missing = $this->serials->missingFor($sale);

        return view('serials.edit', compact('sale', 'missing'));
    }

    public function update(Request $request, Sale $sale)
    {
        $this->assertEnabled();
        Gate::authorize('create', SaleItemSerial::class);

        if ($sale->status === Sale::STATUS_VOIDED) {
            // A voided sale did not happen, so no unit went out on it.
            return back()->with('error', 'This sale was voided; its serial numbers cannot be changed.');
        }

        $data = $request->validate([
            'serials' => 'nullable|array',
            // sale_item_id => [one box per unit]
            'serials.*' => 'nullable|array',
            'serials.*.*' => 'nullable|string|max:120',
        ]);

        try {
            $recorded = $this->serials->recordForSale($sale, $data['serials'] ?? []);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Saved: {$recorded} serial number(s) recorded against {$sale->invoice_number}.");
    }

    /**
     * A trade that does not identify individual units has no serial screens,
     * exactly as the table-service controllers 404 when there are no tables.
     */
    private function assertEnabled(): void
    {
        abort_unless(feature('serials'), 404);
    }
}
