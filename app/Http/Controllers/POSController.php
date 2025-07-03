<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\InventoryLog;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        if (request()->ajax()) {
            return view('pos.cart', compact('cart'))->render();
        }

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
    public function addToCart(Request $request, $id)
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

        return response()->json(['success' => true, 'cart' => $cart]);
    }

    /**
     * Remove item from cart
     */
    public function removeFromCart(Request $request, $id)
    {
        $cart = session()->get('cart', []);

        if (isset($cart[$id])) {
            unset($cart[$id]);
            session()->put('cart', $cart);
        }

        return response()->json(['success' => true, 'cart' => $cart]);
    }

    /**
     * Update item quantity in cart
     */
    public function updateCartItem(Request $request, $id)
    {
        $quantity = max(1, (int) $request->input('quantity'));
        $cart = session()->get('cart', []);

        if (isset($cart[$id])) {
            $cart[$id]['quantity'] = $quantity;
            session()->put('cart', $cart);
        }

        return response()->json(['success' => true, 'cart' => $cart]);
    }


    /**
     * Clear the entire cart
     */
    public function clearCart()
    {
        session()->forget('cart');
        return response()->json(['success' => true]);
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

        foreach ($cart as $item) {
            $product = Product::find($item['product_id']);

            if (!$product) {
                return back()->with('error', "Product not found.");
            }

            if ($product->stock_quantity < $item['quantity']) {
                return back()->with('error', "{$product->name} is out of stock or has insufficient quantity.");
            }
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

        DB::transaction(function () use ($product, $cart, $total, $tax, $discount, $grandTotal, $paymentMethod, $paid, $change, &$saleId) {
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

                $product->decrement('stock_quantity', $item['quantity']);

                InventoryLog::create([
                    'product_id' => $product->id,
                    'user_id' => auth()->id(),
                    'type' => 'OUT',
                    'quantity' => $item['quantity'],
                    'reason' => 'Sale',
                ]);
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
    use Mike42\Escpos\Printer;
    use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;

    public function printReceipt($id)
    {
        $sale = Sale::with('items.product')->findOrFail($id);

        // ESC/POS printing
        try {
            // Replace 'POS-58' with your shared printer name
            $connector = new WindowsPrintConnector("POS-58");
            $printer = new Printer($connector);

            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("SalesPoint POS\n");
            $printer->text("123 Business Street, Nigeria\n");
            $printer->text("Phone: +234 800 000 0000\n");
            $printer->feed();

            $printer->setJustification(Printer::JUSTIFY_LEFT);
            foreach ($sale->items as $item) {
                $line = $item->product->name . " x" . $item->quantity;
                $price = number_format($item->price * $item->quantity, 2);
                $printer->text(str_pad($line, 32));
                $printer->text("₦" . $price . "\n");
            }

            $printer->text("--------------------------------\n");
            $printer->text("Subtotal:  ₦" . number_format($sale->total_amount, 2) . "\n");
            $printer->text("Tax:       ₦" . number_format($sale->tax_amount, 2) . "\n");
            $printer->text("Discount:  ₦" . number_format($sale->discount_amount, 2) . "\n");
            $printer->text("Total:     ₦" . number_format($sale->grand_total, 2) . "\n");
            $printer->text("Paid:      ₦" . number_format($sale->paid_amount, 2) . "\n");
            $printer->text("Change:    ₦" . number_format($sale->change_due, 2) . "\n");

            $printer->feed();
            $printer->text("Payment: " . ucfirst($sale->payment_method) . "\n");
            $printer->text("Date: " . $sale->created_at->format('d M Y h:i A') . "\n");
            $printer->feed();

            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text("Thank you for your purchase!\n");
            $printer->feed(3);

            $printer->cut();
            $printer->close();
        } catch (\Exception $e) {
            // Optional: log error or show message
            Log::error("ESC/POS Print failed: " . $e->getMessage());
        }

        // Show DOM receipt view
        return view('pos.checkout', compact('sale'));
    }
}
