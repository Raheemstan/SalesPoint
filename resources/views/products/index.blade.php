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

        `openVariants` is keyed by product id rather than held per row: x-table
        renders one <tbody> for the whole list, so a row and its variant panel
        can no longer share a <tbody> scope of their own.
    --}}
    <div x-data="{
        openVariants: {},
        addModal: false,
        editModal: false,
        editProduct: {},
        is_service: false,
        parent_id: null,
        has_variants: false,
    }">
        <x-page-header :title="$productsLabel"
                       :subtitle="number_format($products->total()) . ' ' . Str::lower(Str::plural($productLabel, $products->total()))
                            . (request('search') ? ' matching \'' . request('search') . '\'' : '')">
            <x-slot:actions>
                @if (Route::has('products.import.form'))
                    <a href="{{ route('products.import.form') }}"
                       class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                        <x-icon name="upload" size="4" />
                        Import
                    </a>
                @endif

                <button type="button" @click="addModal = true"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    <x-icon name="plus" size="4" />
                    Add {{ $productLabel }}
                </button>
            </x-slot:actions>
        </x-page-header>

        {{--
            The one upload failure a shop cannot diagnose: everything saves, the
            files are on the disk, and every picture is a 403 because nothing
            serves storage/app/public. The web server answers those requests
            without PHP ever seeing them, so there is nothing in the log either.
        --}}
        @if ($media->publicLinkMissing())
            <div class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-100 dark:ring-amber-800">
                <strong class="font-semibold">Pictures cannot be shown on this server.</strong>
                Uploads are being saved, but the link that serves them
                (<code class="font-mono text-xs">public/storage</code>) is missing, so every picture here is a
                placeholder. Run <code class="font-mono text-xs">php artisan storage:link</code> on the server.
            </div>
        @endif

        {{-- Search --}}
        <x-card class="mb-4">
            <form method="GET" action="{{ route('products.index') }}" class="flex flex-wrap items-center gap-2">
                <div class="relative min-w-0 flex-1">
                    <label for="product-search" class="sr-only">Search {{ Str::lower($productsLabel) }}</label>
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
                        <x-icon name="search" size="4" />
                    </span>
                    <input id="product-search" type="search" name="search" value="{{ request('search') }}"
                           placeholder="Name, SKU or barcode"
                           class="min-h-touch w-full rounded-md border-gray-300 pl-9 shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                </div>

                <button type="submit"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    Search
                </button>

                @if (request('search'))
                    <a href="{{ route('products.index') }}"
                       class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                        Clear
                    </a>
                @endif
            </form>
        </x-card>

        {{-- The list --}}
        <x-card :padding="false">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>{{ $productLabel }}</th>
                        <th>SKU</th>
                        <th>{{ term('category', false, 'Category') }}</th>
                        <th class="text-right">Stock</th>
                        <th class="text-right">Price</th>
                        <th><span class="sr-only">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($products as $product)
                    @php
                        // withCount('variants') is always loaded, so this is free.
                        // With the grouping off there is nothing to fold away.
                        $variantCount = $variantsOn ? (int) $product->variants_count : 0;

                        // Null when the row names a file that is not on the disk
                        // (a restored database, a half-copied deploy), so the
                        // placeholder below is used instead of a torn image.
                        $imageUrl = $media->url($product->image_path);

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
                            // The edit form needs both: the URL to show the
                            // picture, and the path to know there is one to
                            // remove even when the file itself has gone.
                            'image_url' => $imageUrl,
                        ];

                        $expiring = $product->expiry_date
                            && $product->tracksStock()
                            && $product->expiry_date->lte(now()->addDays($expiryWarningDays));
                    @endphp

                    <tr>
                        <td>
                            <div class="flex items-center gap-3">
                                {{--
                                    The placeholder is always rendered, with the
                                    picture laid over it. So a file that is on the
                                    disk but cannot be fetched - a missing
                                    public/storage link gives 403, a stale browser
                                    cache gives 404 - removes itself on error and
                                    uncovers the box icon, instead of leaving a
                                    broken-image icon in the middle of the table.
                                --}}
                                <span class="relative flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-gray-100 text-gray-400 ring-1 ring-gray-200 dark:bg-gray-700 dark:text-gray-500 dark:ring-gray-600"
                                      aria-hidden="true">
                                    <x-icon name="box" size="5" />

                                    @if ($imageUrl)
                                        <img src="{{ $imageUrl }}" alt="" loading="lazy"
                                             onerror="this.remove()"
                                             class="absolute inset-0 h-full w-full object-cover">
                                    @endif
                                </span>

                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $product->name }}</span>

                                        @unless ($product->is_active)
                                            <x-badge variant="gray" size="sm">inactive</x-badge>
                                        @endunless

                                        @if ($product->is_service)
                                            <x-badge variant="blue" size="sm">service</x-badge>
                                        @endif

                                        @if ($variantCount)
                                            <x-badge variant="purple" size="sm">{{ $variantCount }} variants</x-badge>
                                        @elseif ($product->isVariant())
                                            <x-badge variant="purple" size="sm">variant</x-badge>
                                        @endif
                                    </div>

                                    @if ($product->supplier)
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $product->supplier->name }}</div>
                                    @endif

                                    @if ($expiring)
                                        <div class="mt-0.5">
                                            <x-badge :variant="$product->expiry_date->isPast() ? 'red' : 'amber'" size="sm" dot>
                                                {{ $product->expiry_date->isPast() ? 'Expired' : 'Expires' }}
                                                {{ $product->expiry_date->format('d M Y') }}
                                                @if ($product->batch_no) &middot; batch {{ $product->batch_no }} @endif
                                            </x-badge>
                                        </div>
                                    @endif

                                    {{-- Variants live under their style, folded away until asked for. --}}
                                    @if ($variantsOn && !$product->isVariant())
                                        <button type="button"
                                                @click="openVariants[{{ $product->id }}] = ! openVariants[{{ $product->id }}]"
                                                :aria-expanded="openVariants[{{ $product->id }}] ? 'true' : 'false'"
                                                aria-controls="variants-{{ $product->id }}"
                                                class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                                            <x-icon name="chevron-down" size="3"
                                                    x-bind:class="openVariants[{{ $product->id }}] ? 'rotate-180 transition' : 'transition'" />
                                            {{ $variantCount ? 'Show variants' : 'Add variants' }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </td>

                        <td class="text-gray-500 dark:text-gray-400">{{ $product->sku }}</td>
                        <td class="text-gray-500 dark:text-gray-400">{{ $product->category->name ?? '-' }}</td>

                        <td class="text-right tabular-nums">
                            @if ($variantCount)
                                {{-- A style has no stock of its own: this is the sum of its variants'. --}}
                                {{ $product->totalStock() }}
                                <span class="block text-xs text-gray-500 dark:text-gray-400">across {{ $variantCount }} variants</span>
                            @elseif ($product->tracksStock())
                                @if ($product->isLowStock())
                                    <x-badge :variant="(int) $product->stock_quantity <= 0 ? 'red' : 'amber'" size="sm">
                                        {{ $product->stock_quantity }}{{ feature('units') && $product->unit ? ' ' . $product->unit : '' }}
                                        (Low)
                                    </x-badge>
                                @else
                                    {{ $product->stock_quantity }}{{ feature('units') && $product->unit ? ' ' . $product->unit : '' }}
                                @endif
                            @else
                                <span class="text-gray-400 dark:text-gray-500">n/a</span>
                            @endif
                        </td>

                        <td class="text-right tabular-nums">{{ money($product->sale_price) }}</td>

                        <td>
                            <div class="flex items-center justify-end gap-2">
                                <button type="button"
                                        @click="editModal = true; editProduct = {{ Js::from($row) }}"
                                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                                    <x-icon name="sliders" size="4" />
                                    Edit
                                </button>

                                <form action="{{ route('products.destroy', $product->id) }}" method="POST"
                                      onsubmit="return confirm('Are you sure?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="inline-flex min-h-touch items-center justify-center rounded-md px-3 py-1.5 text-sm font-medium text-red-600 ring-1 ring-red-200 hover:bg-red-50 dark:text-red-400 dark:ring-red-900 dark:hover:bg-red-950"
                                            aria-label="Delete {{ $product->name }}">
                                        <x-icon name="x" size="4" />
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>

                    @if ($variantsOn && !$product->isVariant())
                        <tr x-show="openVariants[{{ $product->id }}]" x-cloak id="variants-{{ $product->id }}">
                            <td colspan="6" class="bg-gray-50 dark:bg-gray-900/40">
                                @include('products._variants', ['product' => $product])
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="6">
                            <x-empty icon="box"
                                     :title="'No ' . Str::lower($productsLabel) . ' found'"
                                     :message="request('search')
                                        ? 'Nothing matches that name, SKU or barcode.'
                                        : 'Add your first ' . Str::lower($productLabel) . ' to start selling it at the till.'">
                                <x-slot:action>
                                    @if (request('search'))
                                        <a href="{{ route('products.index') }}"
                                           class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                                            Clear the search
                                        </a>
                                    @else
                                        <button type="button" @click="addModal = true"
                                                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                                            <x-icon name="plus" size="4" />
                                            Add {{ $productLabel }}
                                        </button>
                                    @endif
                                </x-slot:action>
                            </x-empty>
                        </td>
                    </tr>
                @endforelse
            </x-table>

            @if ($products->hasPages())
                <x-slot:footer>{{ $products->links() }}</x-slot:footer>
            @endif
        </x-card>

        {{--
            Add and Edit modals.

            These keep their own Alpine state (addModal / editModal) rather than
            becoming <x-modal>, which is driven by open-modal events: the Edit
            button has to set editProduct in the same click that opens the panel.
        --}}
        <div x-show="addModal" x-cloak
             class="fixed inset-0 z-modal flex items-start justify-center overflow-y-auto bg-gray-900/60 p-4"
             role="dialog" aria-modal="true" aria-labelledby="add-product-title"
             @keydown.escape.window="addModal = false">
            <div class="my-8 w-full max-w-3xl rounded-xl bg-white shadow-xl ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"
                 @click.outside="addModal = false">
                <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                    <h2 id="add-product-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">
                        Add {{ $productLabel }}
                    </h2>
                    <button type="button" @click="addModal = false" aria-label="Close"
                            class="-m-1.5 flex h-touch w-touch shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700">
                        <x-icon name="x" size="5" />
                    </button>
                </div>

                {{-- enctype: the form carries a picture. --}}
                <form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="max-h-[70vh] overflow-y-auto p-4 sm:p-5">
                        @include('products._form')
                    </div>

                    <div class="flex flex-wrap justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                        <button type="button" @click="addModal = false"
                                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit"
                                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div x-show="editModal" x-cloak
             class="fixed inset-0 z-modal flex items-start justify-center overflow-y-auto bg-gray-900/60 p-4"
             role="dialog" aria-modal="true" aria-labelledby="edit-product-title"
             @keydown.escape.window="editModal = false">
            <div class="my-8 w-full max-w-3xl rounded-xl bg-white shadow-xl ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"
                 @click.outside="editModal = false">
                <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                    <h2 id="edit-product-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">
                        Edit {{ $productLabel }}
                    </h2>
                    <button type="button" @click="editModal = false" aria-label="Close"
                            class="-m-1.5 flex h-touch w-touch shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700">
                        <x-icon name="x" size="5" />
                    </button>
                </div>

                <form method="POST" :action="'/products/' + editProduct.id" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    <div class="max-h-[70vh] overflow-y-auto p-4 sm:p-5">
                        @include('products._form', ['edit' => true])
                    </div>

                    <div class="flex flex-wrap justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                        <button type="button" @click="editModal = false"
                                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit"
                                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                            Update
                        </button>
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
