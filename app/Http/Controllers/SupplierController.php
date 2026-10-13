<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Supplier;
use App\Services\SupplierLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Suppliers used to be add-only: created inline from the purchase form and never
 * seen again. They now have a list with what is owed on each, a statement, and
 * somewhere to record the money sent to them.
 *
 * The inline-add path from the purchase form still works exactly as it did
 * (store() redirects back rather than to a list), because that is the quickest
 * way to enter a delivery from a supplier nobody has typed in yet.
 */
class SupplierController extends Controller
{
    public function index(Request $request, SupplierLedgerService $ledger)
    {
        Gate::authorize('viewAny', Supplier::class);

        $request->validate(['status' => 'nullable|in:active,inactive']);

        $suppliers = Supplier::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                // Grouped so the OR conditions can't leak out of the search.
                $query->where(function ($q) use ($request) {
                    $q->where('name', 'like', "%{$request->search}%")
                        ->orWhere('phone', 'like', "%{$request->search}%")
                        ->orWhere('email', 'like', "%{$request->search}%");
                });
            })
            ->when($request->status === 'active', fn($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn($q) => $q->where('is_active', false))
            ->withCount('purchases')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        // One set of sums for the whole page rather than one per row.
        $balances = $ledger->balances($suppliers->getCollection());

        return view('suppliers.index', compact('suppliers', 'balances'));
    }

    /** The supplier's statement: what they invoiced, what was paid, what went back. */
    public function show(Supplier $supplier, SupplierLedgerService $ledger)
    {
        Gate::authorize('view', $supplier);

        $summary = $ledger->summary($supplier);

        $purchases = $supplier->purchases()->latest('purchase_date')->limit(50)->get();
        $payments = $supplier->payments()->with('user')->limit(50)->get();
        $returns = $supplier->returns()->with(['user', 'purchase'])->limit(50)->get();

        return view('suppliers.show', compact('supplier', 'summary', 'purchases', 'payments', 'returns'));
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Supplier::class);

        $validated = $request->validate($this->rules());

        try {
            DB::transaction(function () use ($validated) {
                $supplier = Supplier::create($this->attributes($validated, true));

                AuditLog::record('Created supplier', 'suppliers', $supplier->id);
            });
        } catch (\Throwable $e) {
            Log::error('Supplier creation failed', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to add supplier.');
        }

        // back(), not a redirect to the list: the purchase form adds suppliers
        // inline and the user is in the middle of typing a delivery.
        return redirect()->back()->with('success', 'Supplier added successfully.');
    }

    public function update(Request $request, Supplier $supplier)
    {
        Gate::authorize('update', $supplier);

        $validated = $request->validate($this->rules());

        try {
            DB::transaction(function () use ($validated, $supplier) {
                $supplier->update($this->attributes($validated, false));

                AuditLog::record('Updated supplier', 'suppliers', $supplier->id);
            });
        } catch (\Throwable $e) {
            Log::error('Supplier update failed', ['error' => $e->getMessage()]);
            return redirect()->back()->withInput()->with('error', 'Failed to update supplier.');
        }

        return redirect()->back()->with('success', 'Supplier updated successfully.');
    }

    /**
     * Delete a supplier, but only one nobody has ever bought from. Removing a
     * supplier with purchases would take the purchase history with it, so they
     * are deactivated instead: the history stays and they stop appearing in the
     * pickers.
     */
    public function destroy(Supplier $supplier)
    {
        Gate::authorize('delete', $supplier);

        if (!$supplier->isDeletable()) {
            if ($supplier->is_active) {
                $supplier->update(['is_active' => false]);
                AuditLog::record('Deactivated supplier', 'suppliers', $supplier->id);

                return redirect()->back()->with(
                    'success',
                    $supplier->name . ' has purchases against them, so they were deactivated instead of deleted.'
                );
            }

            return redirect()->back()->with(
                'error',
                $supplier->name . ' has purchases against them and cannot be deleted. They are already deactivated.'
            );
        }

        try {
            DB::transaction(function () use ($supplier) {
                // Nothing references them, so nothing is lost.
                $supplier->payments()->delete();
                AuditLog::record('Deleted supplier', 'suppliers', $supplier->id);
                $supplier->delete();
            });
        } catch (\Throwable $e) {
            Log::error('Supplier delete failed', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to delete supplier.');
        }

        return redirect()->route('suppliers.index')->with('success', 'Supplier deleted.');
    }

    private function rules(): array
    {
        return [
            'name'            => 'required|string|max:255',
            'phone'           => 'required|string|max:20',
            'email'           => 'nullable|email|max:255',
            'address'         => 'nullable|string|max:255',
            'notes'           => 'nullable|string|max:2000',
            'opening_balance' => 'nullable|numeric|min:0|max:999999999999',
            'is_active'       => 'nullable|boolean',
        ];
    }

    /**
     * The form's fields as columns.
     *
     * Two shapes have to be handled: the inline-add form on the purchase screen
     * sends name/phone/email/address and nothing else, while the supplier screens
     * send the lot. Anything the request did not carry is left alone on an update
     * rather than blanked - otherwise adding a supplier inline, or editing one
     * from a cut-down form, would wipe their opening balance.
     */
    private function attributes(array $validated, bool $creating): array
    {
        $attributes = [
            'name'    => $validated['name'],
            'phone'   => $validated['phone'],
            'email'   => $validated['email'] ?? null,
            // suppliers.address is NOT NULL; an empty form field arrives as null.
            'address' => $validated['address'] ?? '',
        ];

        if (array_key_exists('notes', $validated)) {
            $attributes['notes'] = $validated['notes'];
        }

        if (array_key_exists('opening_balance', $validated)) {
            $attributes['opening_balance'] = round((float) $validated['opening_balance'], 2);
        }

        /*
         * An unticked checkbox is simply absent, so the supplier form posts a
         * hidden is_active=0 ahead of it. A form with no such field at all (the
         * inline add) leaves a new supplier active.
         */
        if (array_key_exists('is_active', $validated)) {
            $attributes['is_active'] = (bool) $validated['is_active'];
        } elseif ($creating) {
            $attributes['is_active'] = true;
        }

        return $attributes;
    }
}

