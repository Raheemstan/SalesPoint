<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The routes are behind `role:admin|manager`, and every action also asks
 * CategoryPolicy. The duplication is deliberate: the middleware guards the URL,
 * the policy guards the action, and a future route added outside that group
 * cannot quietly open the catalogue to cashiers.
 */
class CategoryController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Category::class);

        $categories = Category::orderBy('name')->paginate(10);
        return view('categories.index', compact('categories'));
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Category::class);

        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name',
        ]);

        Category::create([
            'name' => $request->name,
        ]);

        return back()->with('success', 'Category added successfully.');
    }

    public function update(Request $request, Category $category)
    {
        Gate::authorize('update', $category);

        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,' . $category->id,
        ]);

        $category->update([
            'name' => $request->name,
        ]);

        return back()->with('success', 'Category updated successfully.');
    }

    public function destroy(Category $category)
    {
        Gate::authorize('delete', $category);

        // products.category_id cascades on delete, so deleting a category that
        // still has products would wipe those products. Refuse instead.
        if ($category->products()->withTrashed()->exists()) {
            return back()->with('error', 'This category still has products. Move or delete them first.');
        }

        $category->delete();

        return back()->with('success', 'Category deleted.');
    }
}
