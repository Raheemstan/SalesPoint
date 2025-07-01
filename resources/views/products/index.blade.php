@extends('layouts.app')

@section('title', 'Products')

@section('content')

    <div x-data="{ addModal: false, editModal: false, editProduct: {} }">

        {{-- Header --}}
        <div class="flex justify-between items-center mb-4">
            <button @click="addModal = true" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                ➕ Add Product
            </button>
        </div>

        {{-- Search --}}
        <form method="GET" action="{{ route('products.index') }}" class="mb-4">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search products..."
                class="w-full border rounded px-3 py-2 dark:bg-gray-800 dark:border-gray-600">
        </form>

        {{-- Products Table --}}
        <div class="bg-white dark:bg-gray-800 shadow rounded">
            <table class="w-full table-auto">
                <thead class="bg-gray-200 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2">#</th>
                        <th class="px-4 py-2">Name</th>
                        <th class="px-4 py-2">SKU</th>
                        <th>Category</th>
                        <th class="px-4 py-2">Stock</th>
                        <th class="px-4 py-2">Price</th>
                        <th class="px-4 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        <tr class="border-b dark:border-gray-700">
                            <td class="px-4 py-2">{{ $loop->iteration }}</td>
                            <td class="px-4 py-2">{{ $product->name }}</td>
                            <td class="px-4 py-2">{{ $product->sku }}</td>
                            <td>{{ $product->category->name ?? '-' }}</td>
                            <td class="px-4 py-2">
                                {{ $product->stock_quantity }}
                                {!! $product->stock_quantity <= 5 ? '<span class="text-red-500">(Low)</span>' : '' !!}
                            </td>
                            <td class="px-4 py-2">₦{{ number_format($product->sale_price, 2) }}</td>
                            
                            <td class="px-4 py-2 flex gap-2">
                                {{-- Edit Button --}}
                                <button @click="editModal = true; editProduct = {{ $product->toJson() }}"
                                    class="bg-yellow-500 text-white px-3 py-1 rounded hover:bg-yellow-600">
                                    ✏️ Edit
                                </button>

                                {{-- Delete --}}
                                <form action="{{ route('products.destroy', $product->id) }}" method="POST"
                                    onsubmit="return confirm('Are you sure?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="bg-red-600 text-white px-3 py-1 rounded hover:bg-red-700">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center px-4 py-4 text-gray-500">
                                No products found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="mt-4">
            {{ $products->links() }}
        </div>

        {{-- Add Product Modal --}}
        <div x-show="addModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white dark:bg-gray-800 p-6 rounded w-full max-w-md" @click.away="addModal = false">
                <h2 class="text-xl font-bold mb-4">Add Product</h2>
                <form method="POST" action="{{ route('products.store') }}">
                    @csrf
                    @include('products._form')
                    <div class="flex justify-end gap-2 mt-4">
                        <button type="button" @click="addModal = false" class="px-4 py-2 border rounded">Cancel</button>
                        <button type="submit"
                            class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Save</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Edit Product Modal --}}
        <div x-show="editModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white dark:bg-gray-800 p-6 rounded w-full max-w-md" @click.away="editModal = false">
                <h2 class="text-xl font-bold mb-4">Edit Product</h2>
                <form method="POST" :action="'/products/' + editProduct.id">
                    @csrf
                    @method('PUT')
                    @include('products._form', ['edit' => true])
                    <div class="flex justify-end gap-2 mt-4">
                        <button type="button" @click="editModal = false" class="px-4 py-2 border rounded">Cancel</button>
                        <button type="submit"
                            class="px-4 py-2 bg-yellow-600 text-white rounded hover:bg-yellow-700">Update</button>
                    </div>
                </form>
            </div>
        </div>

    </div>

@endsection