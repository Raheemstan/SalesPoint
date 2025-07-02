<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with('category')
            ->when(
                $request->search,
                fn($q) =>
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('sku', 'like', "%{$request->search}%")
                    ->orWhere('barcode', 'like', "%{$request->search}%")
            )
            ->orderBy('name')
            ->paginate(10);

        $categories = Category::orderBy('name')->get();

        return view('products.index', compact('products', 'categories'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        Product::create($validated);

        return back()->with('success', 'Product added successfully.');
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate($this->rules($product->id));

        $product->update($validated);

        return back()->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return back()->with('success', 'Product deleted successfully.');
    }

    private function rules($id = null): array
    {
        return [
            'name'           => 'required|string|max:255',
            'sku'            => 'nullable|string|max:100|unique:products,sku,' . $id,
            'barcode'        => 'nullable|string|max:100|unique:products,barcode,' . $id,
            'category_id'    => 'nullable|exists:categories,id',
            'cost_price'     => 'required|numeric|min:0',
            'sale_price'     => 'required|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
        ];
    }
}
