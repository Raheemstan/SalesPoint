@extends('layouts.app')

@section('title', 'Categories')

@section('content')

<div x-data="{ addModal: false, editModal: false, editCategory: {} }">

    {{-- Header --}}
    <div class="flex justify-between items-center mb-4">
        <button @click="addModal = true"
            class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
            ➕ Add Category
        </button>
    </div>

    {{-- Categories Table --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded">
        <table class="w-full table-auto">
            <thead class="bg-gray-200 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-2">#</th>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($categories as $category)
                    <tr class="border-b dark:border-gray-700">
                        <td class="px-4 py-2">{{ $loop->iteration }}</td>
                        <td class="px-4 py-2">{{ $category->name }}</td>
                        <td class="px-4 py-2 flex gap-2">
                            {{-- Edit --}}
                            <button @click="editModal = true; editCategory = {{ $category->toJson() }}"
                                class="bg-yellow-500 text-white px-3 py-1 rounded hover:bg-yellow-600">
                                ✏️ Edit
                            </button>

                            {{-- Delete --}}
                            <form action="{{ route('categories.destroy', $category->id) }}" method="POST"
                                  onsubmit="return confirm('Are you sure?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="bg-red-600 text-white px-3 py-1 rounded hover:bg-red-700">
                                    🗑️ Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-center px-4 py-4 text-gray-500">
                            No categories found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    <div class="mt-4">
        {{ $categories->links() }}
    </div>

    {{-- Add Category Modal --}}
    <div x-show="addModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white dark:bg-gray-800 p-6 rounded w-full max-w-md"
             @click.away="addModal = false">
            <h2 class="text-xl font-bold mb-4">Add Category</h2>
            <form method="POST" action="{{ route('categories.store') }}">
                @csrf
                <div class="mb-2">
                    <label>Name</label>
                    <input type="text" name="name"
                        required
                        class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600">
                </div>

                <div class="flex justify-end gap-2 mt-4">
                    <button type="button" @click="addModal = false"
                        class="px-4 py-2 border rounded">Cancel</button>
                    <button type="submit"
                        class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Save</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Edit Category Modal --}}
    <div x-show="editModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white dark:bg-gray-800 p-6 rounded w-full max-w-md"
             @click.away="editModal = false">
            <h2 class="text-xl font-bold mb-4">Edit Category</h2>
            <form method="POST" :action="'/categories/' + editCategory.id">
                @csrf
                @method('PUT')

                <div class="mb-2">
                    <label>Name</label>
                    <input type="text" name="name"
                        x-model="editCategory.name"
                        required
                        class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:border-gray-600">
                </div>

                <div class="flex justify-end gap-2 mt-4">
                    <button type="button" @click="editModal = false"
                        class="px-4 py-2 border rounded">Cancel</button>
                    <button type="submit"
                        class="px-4 py-2 bg-yellow-600 text-white rounded hover:bg-yellow-700">Update</button>
                </div>
            </form>
        </div>
    </div>

</div>

@endsection
