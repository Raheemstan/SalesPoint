@extends('layouts.app')

@section('title', term('product', true, 'Products'))

@section('content')

    @php
        $productLabel = term('product', false, 'Product');
        $productsLabel = term('product', true, 'Products');
        $expiryWarningDays = 60;
        // Variants are a per-trade feature: a pharmacy never sees any of this.
        $variantsOn = feature('variants');
    @endphp

    {{--
        `is_service`, `parent_id` and `has_variants` sit in this scope so the add
        form's conditional fields have something declared to bind to; the edit
        form binds onto editProduct.
    --}}
    <div x-data="{ addModal: false, editModal: false, editProduct: {}, is_service: false, parent_id: null, has_variants: false }">
        @if ($errors->any())
            <div class="bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200 px-4 py-2 rounded mb-3">
                <ul class="list-disc ml-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Header --}}
        <div class="flex justify-between items-center mb-4">
            <button @click="addModal = true" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                ➕ Add {{ $productLabel }}
            </button>
        </div>

        {{-- Search --}}
        <form method="GET" action="{{ route('products.index') }}" class="mb-4">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search {{ Str::lower($productsLabel) }}..."
                class="w-full border rounded px-3 py-2 dark:bg-gray-800 dark:border-gray-600">
        </form>

        {{-- Products Table --}}
        <div class="bg-white dark:bg-gray-800 shadow rounded p-4">
            <table class="w-full table-auto">
                <thead class="bg-gray-200 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left">#</th>
                        <th class="px-4 py-2 text-left">{{ $productLabel }}</th>
                        <th class="px-4 py-2 text-left">SKU</th>
                        <th class="px-4 py-2 text-left">{{ term('category', false, 'Category') }}</th>
                        <th class="px-4 py-2 text-right">Stock</th>
                        <th class="px-4 py-2 text-right">Price</th>
                        <th class="px-4 py-2 text-left">Actions</th>
                    </tr>
                </thead>

                @forelse ($products as $product)
                    @php
                        // withCount('variants') is always loaded, so this is free.
                        // With the grouping off there is nothing to fold away.
                        $variantCount = $variantsOn ? (int) $product->variants_count : 0;

                        // Only the fields this form uses, with the date in the shape
                        // an <input type="date"> accepts rather than a full timestamp.
                        $row = [
                            'id' => $product->id,
                            'name' => $product->name,
                            'sku' => $product->sku,
                            'barcode' => $product->barcode,
                            'parent_id' => $product->parent_id,
                            'variant_label' => $product->variant_label,
                            'has_variants' => $variantCount > 0,
                            'category_id' => $product->category_id,
                            'supplier_id' => $product->supplier_id,
                            'cost_price' => (float) $product->cost_price,
                            'sale_price' => (float) $product->sale_price,
                            'unit' => $product->unit,
                            'pack_size' => (float) $product->pack_size,
                            'is_service' => (bool) $product->is_service,
                            'track_stock' => (bool) $product->track_stock,
                            'serial_required' => (bool) $product->serial_required,
                            'is_active' => (bool) $product->is_active,
                            'stock_quantity' => (int) $product->stock_quantity,
                            'alert_quantity' => $product->alert_quantity,
                            'batch_no' => $product->batch_no,
                            'expiry_date' => $product->expiry_date?->toDateString(),
                            'reorder_quantity' => (int) $product->reorder_quantity,
                            'image_path' => $product->image_path,
                        ];

                        $expiring = $product->expiry_date
                            && $product->tracksStock()
                            && $product->expiry_date->lte(now()->addDays($expiryWarningDays));
                    @endphp

                    {{--
                        One <tbody> per product so the row and its variant panel
                        share an Alpine scope: a <tr> cannot contain another, and
                        several tbody elements in one table are perfectly legal.
                    --}}
                    <tbody x-data="{ open: false }">
                        <tr class="border-b dark:border-gray-700">
                            <td class="px-4 py-2">{{ $loop->iteration }}</td>
                            <td class="px-4 py-2">
                                <div class="flex items-center gap-3">
                                    @if ($product->image_path)
                                        <img src="{{ asset('storage/' . $product->image_path) }}" alt=""
                                             class="h-10 w-10 object-cover rounded border border-gray-200 dark:border-gray-600">
                                    @else
                                        <span class="h-10 w-10 rounded bg-gray-100 dark:bg-gray-700 flex items-center justify-center text-xs text-gray-400">
                                            —
                                        </span>
                                    @endif
                                    <div>
                                        {{ $product->name }}
                                        @unless ($product->is_active)
                                            <span class="text-xs px-1 rounded bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">inactive</span>
                                        @endunless
                                        @if ($product->is_service)
                                            <span class="text-xs px-1 rounded bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">service</span>
                                        @endif
                                        @if ($variantCount)
                                            <span class="text-xs px-1 rounded bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">
                                                {{ $variantCount }} variants
                                            </span>
                                        @elseif ($product->isVariant())
                                            <span class="text-xs px-1 rounded bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">
                                                variant
                                            </span>
                                        @endif
                                        @if ($product->supplier)
                                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $product->supplier->name }}</div>
                                        @endif
                                        @if ($expiring)
                                            <div class="text-xs {{ $product->expiry_date->isPast() ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400' }}">
                                                {{ $product->expiry_date->isPast() ? 'Expired' : 'Expires' }}
                                                {{ $product->expiry_date->format('d M Y') }}
                                                @if ($product->batch_no) &middot; batch {{ $product->batch_no }} @endif
                                            </div>
                                        @endif

                                        {{-- Variants live under their style, folded away until asked for. --}}
                                        @if ($variantsOn && !$product->isVariant())
                                            <button type="button" @click="open = !open"
                                                    class="mt-1 text-xs text-blue-600 dark:text-blue-400 hover:underline">
                                                <span x-text="open ? '▾' : '▸'"></span>
                                                {{ $variantCount ? 'Show variants' : 'Add variants' }}
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-2">{{ $product->sku }}</td>
                            <td class="px-4 py-2">{{ $product->category->name ?? '-' }}</td>
                            <td class="px-4 py-2 text-right">
                                @if ($variantCount)
                                    {{-- A style has no stock of its own: this is the sum of its variants'. --}}
                                    {{ $product->totalStock() }}
                                    <div class="text-xs text-gray-500 dark:text-gray-400">across {{ $variantCount }} variants</div>
                                @elseif ($product->tracksStock())
                                    {{ $product->stock_quantity }}{{ feature('units') && $product->unit ? ' ' . $product->unit : '' }}
                                    @if ($product->isLowStock())
                                        <span class="text-red-500">(Low)</span>
                                    @endif
                                @else
                                    <span class="text-gray-400">n/a</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">{{ money($product->sale_price) }}</td>

                            <td class="px-4 py-2">
                                <div class="flex gap-2">
                                    {{-- Edit Button --}}
                                    <button @click="editModal = true; editProduct = {{ Js::from($row) }}"
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
                                </div>
                            </td>
                        </tr>

                        @if ($variantsOn && !$product->isVariant())
                            <tr x-show="open" style="display: none" class="border-b dark:border-gray-700">
                                <td colspan="7" class="px-4 pb-4">
                                    @include('products._variants', ['product' => $product])
                                </td>
                            </tr>
                        @endif
                    </tbody>
                @empty
                    <tbody>
                        <tr>
                            <td colspan="7" class="text-center px-4 py-4 text-gray-500">
                                No {{ Str::lower($productsLabel) }} found.
                            </td>
                        </tr>
                    </tbody>
                @endforelse
            </table>
        </div>

        {{-- Pagination --}}
        <div class="mt-4">
            {{ $products->links() }}
        </div>

        {{-- Add Product Modal --}}
        <div x-show="addModal" style="display: none"
            class="fixed inset-0 bg-black bg-opacity-50 flex items-start justify-center z-50 p-4 overflow-y-auto">
            <div class="bg-white dark:bg-gray-800 p-6 rounded shadow w-full max-w-3xl my-8" @click.away="addModal = false">
                <h2 class="text-xl font-bold mb-4">Add {{ $productLabel }}</h2>
                {{-- enctype: the form carries a picture. --}}
                <form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data">
                    @csrf
                    @include('products._form')
                    <div class="flex justify-end gap-2 mt-4">
                        <button type="button" @click="addModal = false" class="px-4 py-2 border rounded dark:border-gray-600">Cancel</button>
                        <button type="submit"
                            class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Save</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Edit Product Modal --}}
        <div x-show="editModal" style="display: none"
            class="fixed inset-0 bg-black bg-opacity-50 flex items-start justify-center z-50 p-4 overflow-y-auto">
            <div class="bg-white dark:bg-gray-800 p-6 rounded shadow w-full max-w-3xl my-8" @click.away="editModal = false">
                <h2 class="text-xl font-bold mb-4">Edit {{ $productLabel }}</h2>
                <form method="POST" :action="'/products/' + editProduct.id" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    @include('products._form', ['edit' => true])
                    <div class="flex justify-end gap-2 mt-4">
                        <button type="button" @click="editModal = false" class="px-4 py-2 border rounded dark:border-gray-600">Cancel</button>
                        <button type="submit"
                            class="px-4 py-2 bg-yellow-600 text-white rounded hover:bg-yellow-700">Update</button>
                    </div>
                </form>
            </div>
        </div>

        {{--
            Removing a picture is a request of its own. It lives out here rather
            than inside the edit form because HTML forbids nesting forms; the
            Remove button inside that form points at it by id.
        --}}
        <form id="remove-product-image" method="POST" :action="'/products/' + editProduct.id + '/image'" class="hidden">
            @csrf
            @method('DELETE')
        </form>

    </div>

@endsection
