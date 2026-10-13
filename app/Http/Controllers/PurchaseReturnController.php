<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Services\PurchaseReturnService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Stock sent back to a supplier (damaged, wrong item, over-delivery).
 *
 * The maths and the stock movement live in App\Services\PurchaseReturnService,
 * exactly as a customer refund lives in RefundService; this only turns a form
 * into the arguments it wants.
 */
class PurchaseReturnController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Purchase::class);

        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'supplier_id' => 'nullable|exists:suppliers,id',
        ]);

        $query = PurchaseReturn::with(['supplier', 'user', 'purchase', 'items.product'])
            ->when($request->filled('search'), function ($query) use ($request) {
                // Grouped so the OR conditions can't leak out of the search.
                $query->where(function ($q) use ($request) {
                    $q->where('reference', 'like', "%{$request->search}%")
                        ->orWhereHas('purchase', fn($p) => $p->where('invoice_number', 'like', "%{$request->search}%"));
                });
            })
            ->when($request->filled('supplier_id'), fn($q) => $q->where('supplier_id', $request->supplier_id))
            ->when($request->filled('from'), fn($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn($q) => $q->whereDate('created_at', '<=', $request->to));

        // The total of everything that matched, not just the page being shown.
        $total = round((float) (clone $query)->sum('total_amount'), 2);

        $returns = $query->latest()->paginate(20)->withQueryString();
        $suppliers = Supplier::orderBy('name')->get();

        return view('purchases.returns.index', compact('returns', 'suppliers', 'total'));
    }

    public function store(Request $request, Purchase $purchase, PurchaseReturnService $returns)
    {
        Gate::authorize('return', $purchase);

        $data = $request->validate([
            'items' => 'required|array',
            'items.*.quantity' => 'nullable|integer|min:0',
            'reason' => 'required|string|min:3|max:255',
            'reference' => 'nullable|string|max:255',
        ]);

        $quantities = [];
        foreach ($data['items'] as $itemId => $row) {
            $quantities[(int) $itemId] = (int) ($row['quantity'] ?? 0);
        }

        try {
            $return = $returns->returnToSupplier(
                $purchase,
                $quantities,
                $data['reason'],
                $request->user(),
                $data['reference'] ?? null
            );
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('purchases.show', $purchase)
            ->with('success', 'Returned ' . money($return->total_amount) . ' of goods to ' . $purchase->supplier->name . '.');
    }
}
