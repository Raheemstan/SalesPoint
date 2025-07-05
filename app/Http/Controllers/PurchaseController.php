<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\AuditLog as Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseController extends Controller
{
    // Show all purchases with optional filtering
    public function index(Request $request)
    {
        $query = Purchase::with('supplier')->latest();

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('purchase_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('purchase_date', '<=', $request->end_date);
        }

        $purchases = $query->paginate(10);
        $suppliers = Supplier::all();

        $products = cache()->remember('products.all', 60 * 60, function () {
            return Product::all();
        });
        $invoiceNumber = "IN-" . str_pad(Purchase::count() + 1, 6, '0', STR_PAD_LEFT);

        return view('purchases.index', compact('purchases', 'suppliers', 'products', 'invoiceNumber'));
    }

    // Show purchase creation form
    public function create()
    {
        $suppliers = Supplier::all();
        $products = Product::all();

        return view('purchases.create', compact('suppliers', 'products'));
    }

    // Store a new purchase

    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            // Step 1: Rebuild items array from parallel inputs
            $products = $request->input('product_ids', []);
            $quantities = $request->input('quantities', []);
            $cost_prices = $request->input('cost_prices', []);

            $items = [];
            for ($i = 0; $i < count($products); $i++) {
                $items[] = [
                    'product_id' => $products[$i],
                    'quantity' => $quantities[$i],
                    'cost_price' => $cost_prices[$i],
                ];
            }

            // Step 2: Validate the combined input
            $request->merge(['items' => $items]);

            $request->validate([
                'supplier_id'      => 'required|exists:suppliers,id',
                'purchase_date'    => 'required|date',
                'invoice_number'   => 'required|string|max:255|unique:purchases,invoice_number',
                'note'             => 'nullable|string',
                'items'            => 'required|array|min:1',
                'items.*.product_id'  => 'required|exists:products,id',
                'items.*.quantity'    => 'required|numeric|min:1',
                'items.*.cost_price'  => 'required|numeric|min:0',
            ]);

            // Step 3: Calculate total
            $total = collect($items)->sum(function ($item) {
                return $item['quantity'] * $item['cost_price'];
            });

            // Step 4: Save Purchase
            $purchase = Purchase::create([
                'supplier_id'    => $request->supplier_id,
                'user_id'        => Auth::id(),
                'invoice_number' => $request->invoice_number,
                'purchase_date'  => $request->purchase_date,
                'note'           => $request->note,
                'total_amount'   => $total,
            ]);

            // Step 5: Save PurchaseItems + update stock
            foreach ($items as $item) {
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'product_id'  => $item['product_id'],
                    'quantity'    => $item['quantity'],
                    'cost_price'  => $item['cost_price'],
                    'total'       => $item['quantity'] * $item['cost_price'],
                ]);

                Product::find($item['product_id'])->increment('stock_quantity', $item['quantity']);
            }

            Audit::create([
                'user_id' => Auth::id(),
                'action'  => 'Created purchase #' . $purchase->invoice_number,
                'table_name' => 'purchases',
                'record_id' => $purchase->id,
                'ip_address' => $request->ip(),
            ]);



            DB::commit();
            return redirect()->route('purchases.index')->with('success', 'Purchase recorded successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Purchase Error: ' . $e->getMessage());
            return redirect()->back()->withInput()->with('error', 'Failed to record purchase.');
        }
    }

    // Show a specific purchase
    public function show(Purchase $purchase)
    {
        $purchase->load(['supplier', 'purchaseItems.product']);
        return view('purchases.show', compact('purchase'));
    }

    // Delete a purchase
    public function destroy(Purchase $purchase)
    {
        DB::beginTransaction();
        try {
            foreach ($purchase->purchaseItems as $item) {
                // Optionally reduce stock
                $item->product->decrement('stock', $item->quantity);
                $item->delete();
            }

            Audit::create([
                'user_id' => Auth::id(),
                'action' => 'Deleted purchase #' . $purchase->invoice_number,
                'data' => json_encode($purchase->toArray()),
            ]);

            $purchase->delete();
            DB::commit();

            return redirect()->route('purchases.index')->with('success', 'Purchase deleted.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Delete Purchase Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to delete purchase.');
        }
    }
}
