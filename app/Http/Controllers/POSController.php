<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\InventoryLog;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;

class POSController extends Controller
{
    /**
     * Display the POS page
     */
    public function index()
    {
        $products = Product::orderBy('name')->get();
        $cart = session()->get('cart', []);

        return view('pos.index', compact('products', 'cart'));
    }

    /**
     * Search for a product (optional AJAX)
     */
    public function searchProduct(Request $request)
    {
        $query = $request->input('query');

        $products = Product::where('name', 'like', "%{$query}%")
            ->orWhere('sku', 'like', "%{$query}%")
            ->orWhere('barcode', 'like', "%{$query}%")
            ->get();

        return response()->json($products);
    }

    /**
     * Add product to cart
     */
    public function addToCart($id)
    {
        $product = Product::findOrFail($id);

        $cart = session()->get('cart', []);

        if (isset($cart[$id])) {
            $cart[$id]['quantity'] += 1;
        } else {
            $cart[$id] = [
                'product_id' => $id,
                'name' => $product->name,
                'price' => $product->sale_price,
                'quantity' => 1,
            ];
        }

        session()->put('cart', $cart);

        return back()->with('success', "{$product->name} added to cart.");
    }

    /**
     * Remove item from cart
     */
    public function removeFromCart($id)
    {
        $cart = session()->get('cart', []);

        if (isset($cart[$id])) {
            unset($cart[$id]);
            session()->put('cart', $cart);
        }

        return back()->with('success', 'Item removed from cart.');
    }

    /**
     * Update item quantity in cart
     */
    public function updateCartItem(Request $request, $id)
    {
        $cart = session()->get('cart', []);

        if (isset($cart[$id])) {
            $cart[$id]['quantity'] = max(1, (int) $request->quantity);
            session()->put('cart', $cart);
        }

        return back()->with('success', 'Cart updated.');
    }

    /**
     * Clear the entire cart
     */
    public function clearCart()
    {
        session()->forget('cart');
        return back()->with('success', 'Cart cleared.');
    }

    /**
     * Checkout and process sale
     */
    public function checkout(Request $request)
    {
        $cart = session()->get('cart', []);

        if (empty($cart)) {
            return back()->with('error', 'Cart is empty!');
        }

        $total = collect($cart)->sum(fn($item) => $item['price'] * $item['quantity']);
        $paymentMethod = $request->payment_method;
        $paid = $request->paid_amount;
        $tax = $request->tax ?? 0;
        $discount = $request->discount ?? 0;
        $grandTotal = ($total + $tax) - $discount;
        $change = $paid - $grandTotal;

        if ($paid < $grandTotal) {
            return back()->with('error', 'Insufficient payment.');
        }
        $saleId = null;

        DB::transaction(function () use ($cart, $total, $tax, $discount, $grandTotal, $paymentMethod, $paid, $change, &$saleId) {
            $sale = Sale::create([
                'invoice_number' => 'INV-' . time(),
                'user_id' => auth()->id(),
                'total_amount' => $total,
                'tax_amount' => $tax,
                'discount_amount' => $discount,
                'grand_total' => $grandTotal,
                'payment_method' => $paymentMethod,
                'paid_amount' => $paid,
                'change_due' => $change,
                'sale_date' => now(),
            ]);

            $saleId = $sale->id;

            foreach ($cart as $item) {
                SaleItem::create([
                    'sale_id' => $sale->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'total' => $item['price'] * $item['quantity'],
                ]);

                $product = Product::find($item['product_id']);
                if ($product) {
                    $product->decrement('stock_quantity', $item['quantity']);

                    InventoryLog::create([
                        'product_id' => $product->id,
                        'user_id' => auth()->id(),
                        'type' => 'OUT',
                        'quantity' => $item['quantity'],
                        'reason' => 'Sale',
                    ]);
                }
            }

            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'Created Sale',
                'table_name' => 'sales',
                'record_id' => $sale->id,
            ]);

            session()->forget('cart');
        });

        return redirect()->route('pos.print', $saleId);
    }

    public function searchProducts(Request $request)
    {
        $search = $request->query('q');

        $products = Product::where('name', 'like', "%$search%")
            ->orWhere('sku', 'like', "%$search%")
            ->orWhere('barcode', 'like', "%$search%")
            ->limit(30)
            ->get();

        return response()->json($products);
    }


    /**
     * Print Receipt
     */
    public function printReceipt($id)
    {
        $sale = Sale::with('items.product')->findOrFail($id);
        return view('pos.checkout', compact('sale'));
    }
}
