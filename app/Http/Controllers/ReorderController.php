<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * What to reorder, grouped by supplier.
 *
 * The low-stock threshold has been a setting for a long time but nothing ever
 * read it, so "what do we need to buy?" was answered by walking the shelves.
 * This is that list: everything at or below its reorder level, grouped by who
 * we buy it from, with a suggested quantity and what the order would cost.
 *
 * Services never appear: Product::scopeLowStock() is built on scopeStocked().
 */
class ReorderController extends Controller
{
    public function index(Request $request)
    {
        // Stock control's own gate (see StockAdjustmentPolicy): managers and
        // admins only. ProductPolicy is still an unused stub, so it is not it.
        Gate::authorize('reorder', StockAdjustment::class);

        $request->validate([
            'supplier_id' => 'nullable|integer',
            'search' => 'nullable|string|max:100',
        ]);

        $products = Product::lowStock()
            ->active()
            ->with(['supplier', 'category'])
            // "0" means the unassigned group, which is not a supplier id.
            ->when($request->filled('supplier_id'), function ($query) use ($request) {
                return (int) $request->supplier_id === 0
                    ? $query->whereNull('supplier_id')
                    : $query->where('supplier_id', $request->supplier_id);
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where(function ($q) use ($request) {
                    $q->where('name', 'like', "%{$request->search}%")
                        ->orWhere('sku', 'like', "%{$request->search}%");
                });
            })
            ->orderBy('name')
            ->get();

        $groups = $products
            ->groupBy(fn(Product $product) => $product->supplier_id ?? 0)
            ->map(function ($items, $supplierId) {
                $lines = $items->map(fn(Product $product) => [
                    'product' => $product,
                    'suggested' => $this->suggestedQuantity($product),
                    'unit_cost' => round((float) $product->cost_price, 2),
                    'line_cost' => round($this->suggestedQuantity($product) * (float) $product->cost_price, 2),
                ]);

                return [
                    'supplier' => $items->first()->supplier,
                    'supplier_id' => (int) $supplierId,
                    // A supplier-less group still needs a heading, and the buyer
                    // still needs to know these items are running out.
                    'label' => $items->first()->supplier->name ?? 'No supplier set',
                    'lines' => $lines,
                    'total' => round((float) $lines->sum('line_cost'), 2),
                ];
            })
            // Named suppliers first, the unassigned group last.
            ->sortBy(fn($group) => ($group['supplier_id'] === 0 ? '1' : '0') . $group['label'])
            ->values();

        $suppliers = Supplier::orderBy('name')->get();

        $summary = [
            'items' => $products->count(),
            'out_of_stock' => $products->where('stock_quantity', '<=', 0)->count(),
            'total' => round((float) $groups->sum('total'), 2),
        ];

        return view('reorder.index', compact('groups', 'suppliers', 'summary'));
    }

    /**
     * How many to put on the order.
     *
     * The product's own reorder_quantity wins when it is set (a shop that buys
     * sugar by the 20kg sack knows better than we do). With nothing set we order
     * enough to reach twice the reorder level, so the item does not trip the
     * same warning again on the next sale - and never less than one unit.
     */
    private function suggestedQuantity(Product $product): int
    {
        if ((int) $product->reorder_quantity > 0) {
            return (int) $product->reorder_quantity;
        }

        return max(1, $product->reorderLevel() * 2 - (int) $product->stock_quantity);
    }
}
