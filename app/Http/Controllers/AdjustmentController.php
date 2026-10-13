<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Services\StockAdjustmentService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * One-off stock adjustments with a reason (damage, theft, expiry).
 *
 * This is the replacement for correcting stock on the product form: the figure
 * cannot change without somebody saying why, and the history below says who
 * changed what, when and by how much.
 */
class AdjustmentController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', StockAdjustment::class);

        $request->validate([
            'product_id' => 'nullable|integer|exists:products,id',
            'reason' => 'nullable|in:' . implode(',', StockAdjustment::REASONS),
            'type' => 'nullable|in:' . implode(',', StockAdjustment::TYPES),
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        // Default to the last 30 days: the whole history is rarely the question.
        $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->subDays(29)->startOfDay();
        $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

        $query = StockAdjustment::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($request->filled('product_id'), fn($q) => $q->where('product_id', $request->product_id))
            ->when($request->filled('type'), fn($q) => $q->where('type', $request->type))
            ->reason($request->reason);

        // Totals over the whole filtered set, not just the page on screen. Taken
        // before the relations are attached: an aggregate row has no keys to
        // eager-load against.
        $totals = (clone $query)->selectRaw("
            COUNT(*) as entries,
            COALESCE(SUM(CASE WHEN type = 'increase' THEN quantity ELSE 0 END), 0) as units_in,
            COALESCE(SUM(CASE WHEN type = 'decrease' THEN quantity ELSE 0 END), 0) as units_out,
            COALESCE(SUM(CASE WHEN type = 'decrease' THEN quantity * cost_price ELSE 0 END), 0) as value_out
        ")->first();

        $adjustments = $query->with(['product', 'user', 'stockTake'])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        // Only items that can actually be adjusted may be offered in the form.
        $products = Product::stocked()->active()->orderBy('name')->get(['id', 'name', 'sku', 'unit', 'stock_quantity']);

        return view('adjustments.index', compact('adjustments', 'products', 'totals') + [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    public function store(Request $request, StockAdjustmentService $service)
    {
        Gate::authorize('create', StockAdjustment::class);

        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'type' => 'required|in:' . implode(',', StockAdjustment::TYPES),
            'quantity' => 'required|integer|min:1|max:9999999',
            'reason' => 'required|in:' . implode(',', StockAdjustment::REASONS),
            'note' => 'nullable|string|max:255',
        ]);

        // The product (and its stock) comes from the database; the form only says which one.
        $product = Product::findOrFail($data['product_id']);

        try {
            $adjustment = $service->adjust(
                $product,
                $data['type'],
                (int) $data['quantity'],
                $data['reason'],
                $data['note'] ?? null,
                $request->user()
            );
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $direction = $adjustment->type === StockAdjustment::TYPE_INCREASE ? 'added to' : 'removed from';

        return redirect()->route('adjustments.index')->with(
            'success',
            "{$adjustment->quantity} {$direction} {$product->name}. Stock is now {$product->fresh()->stock_quantity}."
        );
    }
}
