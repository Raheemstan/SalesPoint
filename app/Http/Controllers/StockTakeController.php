<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\Supplier;
use App\Services\StockTakeService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Counted stock-takes, reconciled against what the system thinks.
 *
 * The sheet is created, counted, submitted and then approved by somebody else
 * (see StockTakePolicy). Only approval moves stock, and it moves it through
 * StockTakeService so that every line leaves an adjustment and an inventory log.
 */
class StockTakeController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', StockTake::class);

        $request->validate([
            'status' => 'nullable|in:' . implode(',', StockTake::STATUSES),
        ]);

        $stockTakes = StockTake::with(['user', 'approver'])
            ->withCount(['items', 'items as counted_items_count' => fn($q) => $q->whereNotNull('counted_quantity')])
            // The variance in money, as one subquery per sheet rather than loading
            // every line of every sheet just to total it.
            ->addSelect(['variance_value' => StockTakeItem::selectRaw('COALESCE(SUM(variance * cost_price), 0)')
                ->whereColumn('stock_take_id', 'stock_takes.id')])
            ->status($request->status)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('stock-takes.index', compact('stockTakes'));
    }

    public function create(Request $request)
    {
        Gate::authorize('create', StockTake::class);

        $categories = Category::orderBy('name')->get();
        $suppliers = Supplier::orderBy('name')->get();

        // How many lines the sheet would have, so nobody prints 2,000 rows by accident.
        $countable = Product::stocked()->active()
            ->when($request->filled('category_id'), fn($q) => $q->where('category_id', $request->category_id))
            ->when($request->filled('supplier_id'), fn($q) => $q->where('supplier_id', $request->supplier_id))
            ->count();

        return view('stock-takes.create', compact('categories', 'suppliers', 'countable'));
    }

    public function store(Request $request, StockTakeService $service)
    {
        Gate::authorize('create', StockTake::class);

        $data = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'note' => 'nullable|string|max:500',
        ]);

        try {
            $stockTake = $service->createSheet($request->user(), $data);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('stock-takes.show', $stockTake)
            ->with('success', "Count sheet {$stockTake->reference} created. Print it, count, then enter the figures.");
    }

    public function show(StockTake $stockTake, StockTakeService $service)
    {
        Gate::authorize('view', $stockTake);

        $stockTake->load(['items.product.category', 'user', 'approver']);

        $summary = $service->summary($stockTake);
        $canApprove = Gate::allows('approve', $stockTake);

        return view('stock-takes.show', compact('stockTake', 'summary', 'canApprove'));
    }

    /** Save the counts typed onto the sheet; "submit" also hands it over for approval. */
    public function update(Request $request, StockTake $stockTake, StockTakeService $service)
    {
        Gate::authorize('update', $stockTake);

        $data = $request->validate([
            'counts' => 'nullable|array',
            // A blank box means "not counted", which is not the same as counting zero.
            'counts.*' => 'nullable|integer|min:0|max:9999999',
            'notes' => 'nullable|array',
            'notes.*' => 'nullable|string|max:255',
            'action' => 'nullable|in:save,submit',
        ]);

        $submit = ($data['action'] ?? 'save') === 'submit';

        try {
            $service->saveCounts($stockTake, $data['counts'] ?? [], $data['notes'] ?? [], $submit);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('stock-takes.show', $stockTake)->with(
            'success',
            $submit ? 'Counts submitted for approval.' : 'Counts saved.'
        );
    }

    public function approve(Request $request, StockTake $stockTake, StockTakeService $service)
    {
        Gate::authorize('approve', $stockTake);

        try {
            $service->approve($stockTake, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $value = $service->summary($stockTake->refresh())['variance_value'];

        return redirect()->route('stock-takes.show', $stockTake)
            ->with('success', 'Stock-take approved and stock corrected. Variance: ' . money($value) . '.');
    }

    /**
     * A sheet nobody wrote on is junk, so it goes; anything with counts on it is
     * cancelled and kept, because "we counted and then abandoned it" is itself
     * worth knowing. An approved sheet is history and stays either way.
     */
    public function destroy(StockTake $stockTake, StockTakeService $service)
    {
        Gate::authorize('delete', $stockTake);

        if ($stockTake->countedItems()->doesntExist()) {
            $reference = $stockTake->reference;
            $stockTake->delete(); // items cascade

            return redirect()->route('stock-takes.index')->with('success', "Empty count sheet {$reference} discarded.");
        }

        try {
            $service->cancel($stockTake);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('stock-takes.index')->with('success', "Stock-take {$stockTake->reference} cancelled.");
    }
}
