@php
    /*
     * One form for both the add and edit modals. $p prefixes every Alpine
     * binding: the edit modal fills an `editProduct` object from the row, the add
     * modal binds straight onto the page's own scope.
     */
    $edit = isset($edit) && $edit;
    $p = $edit ? 'editProduct.' : '';

    $inputClass = "w-full rounded-lg border border-gray-300 dark:border-gray-600 px-4 py-2 bg-white dark:bg-gray-800 focus:ring-2 focus:ring-blue-500 focus:outline-none";
    $labelClass = "block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1";
    $hintClass = "text-xs text-gray-500 dark:text-gray-400 mt-1";

    $productLabel = term('product', false, 'Product');
@endphp

<div class="grid grid-cols-1 md:grid-cols-2 gap-6">

    <div>
        <label class="{{ $labelClass }}">Name</label>
        <input type="text" name="name"
               x-model="{{ $p }}name"
               value="{{ old('name') }}"
               required
               class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">SKU</label>
        <input type="text" name="sku"
               x-model="{{ $p }}sku"
               value="{{ old('sku') }}"
               class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Barcode</label>
        <input type="text" name="barcode"
               x-model="{{ $p }}barcode"
               value="{{ old('barcode') }}"
               class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">{{ term('category', false, 'Category') }}</label>
        <select name="category_id"
                x-model="{{ $p }}category_id"
                class="{{ $inputClass }}">
            <option value="">Select {{ Str::lower(term('category', false, 'Category')) }}</option>
            @foreach($categories as $category)
                <option value="{{ $category->id }}">
                    {{ $category->name }}
                </option>
            @endforeach
        </select>
    </div>

    {{-- Who this is normally bought from: it is what groups the reorder list. --}}
    <div>
        <label class="{{ $labelClass }}">{{ term('supplier', false, 'Supplier') }}</label>
        <select name="supplier_id"
                x-model="{{ $p }}supplier_id"
                class="{{ $inputClass }}">
            <option value="">No preferred {{ Str::lower(term('supplier', false, 'Supplier')) }}</option>
            @foreach($suppliers ?? [] as $supplier)
                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="flex items-end">
        {{-- An unticked box sends nothing, so a 0 goes ahead of it. --}}
        <input type="hidden" name="is_active" value="0">
        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 mb-2">
            <input type="checkbox" name="is_active" value="1" class="rounded"
                   x-model="{{ $p }}is_active"
                   @if (!$edit) checked @endif>
            Active
        </label>
    </div>

    <div>
        <label class="{{ $labelClass }}">Cost Price ({{ currency() }})</label>
        <input type="number" step="0.01" name="cost_price"
               x-model="{{ $p }}cost_price"
               value="{{ old('cost_price') }}"
               required
               class="{{ $inputClass }}">
    </div>

    <div>
        <label class="{{ $labelClass }}">Sale Price ({{ currency() }})</label>
        <input type="number" step="0.01" name="sale_price"
               x-model="{{ $p }}sale_price"
               value="{{ old('sale_price') }}"
               required
               class="{{ $inputClass }}">
    </div>

    @if (feature('service_items'))
        {{--
            A service - labour, a haircut, a delivery fee - never runs out, so the
            stock fields below are hidden rather than filled with a zero that would
            put it on the low-stock list and block a sale.
        --}}
        <div class="md:col-span-2 p-3 rounded bg-gray-50 dark:bg-gray-700">
            <input type="hidden" name="is_service" value="0">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" name="is_service" value="1" class="rounded"
                       x-model="{{ $p }}is_service">
                This is a service, not a stocked {{ Str::lower($productLabel) }}
            </label>
            <p class="{{ $hintClass }}">Nothing is counted in or out, and it can always be sold.</p>
        </div>
    @endif

    @if (feature('variants'))
        {{--
            Only a variant has a label, and only the edit form can be looking at
            one: new variants are created from the panel under their style, where
            the inherited fields and the generated SKU come from.
        --}}
        <template x-if="{{ $p }}parent_id">
            <div class="md:col-span-2">
                <label class="{{ $labelClass }}">Variant label</label>
                <input type="text" name="variant_label" maxlength="120" placeholder="Red / XL"
                       x-model="{{ $p }}variant_label"
                       class="{{ $inputClass }}">
                <p class="{{ $hintClass }}">What tells this one apart from the others in its style.</p>
            </div>
        </template>

        {{-- A style is a heading, not something you sell. --}}
        <template x-if="{{ $p }}has_variants">
            <div class="md:col-span-2 p-3 rounded bg-purple-50 dark:bg-purple-900/30 text-sm text-gray-700 dark:text-gray-200">
                This {{ Str::lower($productLabel) }} has variants, so its stock is the total of theirs and it is
                not offered at the till. Prices and stock are set on each variant.
            </div>
        </template>
    @endif

    @if (feature('serials'))
        {{-- Electronics: each unit is individually identifiable. --}}
        <div class="md:col-span-2 p-3 rounded bg-gray-50 dark:bg-gray-700">
            <input type="hidden" name="serial_required" value="0">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" name="serial_required" value="1" class="rounded"
                       x-model="{{ $p }}serial_required">
                Record a serial / IMEI for each unit sold
            </label>
            <p class="{{ $hintClass }}">Serials are entered against the {{ Str::lower(term('sale', false, 'Sale')) }} and can be looked up later.</p>
        </div>
    @endif

    @if (feature('units'))
        <div>
            <label class="{{ $labelClass }}">Unit sold</label>
            <input type="text" name="unit" maxlength="20" placeholder="piece"
                   x-model="{{ $p }}unit"
                   value="{{ old('unit') }}"
                   class="{{ $inputClass }}">
        </div>

        <div>
            <label class="{{ $labelClass }}">Units per pack bought</label>
            <input type="number" step="0.001" min="0.001" name="pack_size" placeholder="1"
                   x-model="{{ $p }}pack_size"
                   value="{{ old('pack_size') }}"
                   class="{{ $inputClass }}">
            <p class="{{ $hintClass }}">A carton of 12 sold singly: unit "piece", 12 per pack.</p>
        </div>
    @endif

    {{--
        Stock. Hidden for a service, for a trade that counts nothing at all, and
        for a style whose stock lives on its variants.
    --}}
    @if (feature('stock'))
        <template x-if="!{{ $p }}is_service && !{{ $p }}has_variants">
            <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <label class="{{ $labelClass }}">Stock Quantity</label>
                    <input type="number" name="stock_quantity"
                           x-model="{{ $p }}stock_quantity"
                           value="{{ old('stock_quantity') }}"
                           required
                           class="{{ $inputClass }}">
                </div>

                <div>
                    <label class="{{ $labelClass }}">Low-stock level</label>
                    <input type="number" min="0" name="alert_quantity"
                           x-model="{{ $p }}alert_quantity"
                           value="{{ old('alert_quantity') }}"
                           class="{{ $inputClass }}">
                    <p class="{{ $hintClass }}">Blank uses the shop-wide level ({{ (int) setting('low_stock_threshold', 5) }}).</p>
                </div>

                <div>
                    <label class="{{ $labelClass }}">Reorder quantity</label>
                    <input type="number" min="0" name="reorder_quantity"
                           x-model="{{ $p }}reorder_quantity"
                           value="{{ old('reorder_quantity') }}"
                           class="{{ $inputClass }}">
                    <p class="{{ $hintClass }}">How many to put on an order when it runs low.</p>
                </div>

                @if (feature('expiry'))
                    <div>
                        <label class="{{ $labelClass }}">Batch number</label>
                        <input type="text" name="batch_no" maxlength="100"
                               x-model="{{ $p }}batch_no"
                               value="{{ old('batch_no') }}"
                               class="{{ $inputClass }}">
                    </div>

                    <div>
                        <label class="{{ $labelClass }}">Expiry date</label>
                        <input type="date" name="expiry_date"
                               x-model="{{ $p }}expiry_date"
                               value="{{ old('expiry_date') }}"
                               class="{{ $inputClass }}">
                    </div>
                @endif
            </div>
        </template>
    @endif

    {{-- Picture. Its own Alpine scope so the two copies of this form do not share a preview. --}}
    <div class="md:col-span-2" x-data="{ preview: null }">
        <label class="{{ $labelClass }}">Picture</label>

        <div class="flex items-start gap-4">
            <div>
                <input type="file" name="image" accept="image/jpeg,image/png,image/webp"
                       @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null"
                       class="text-sm">
                <p class="{{ $hintClass }}">JPEG, PNG or WebP, up to 2 MB.</p>
            </div>

            {{-- The file just chosen, before it is uploaded. --}}
            <img x-show="preview" style="display: none" :src="preview" alt=""
                 class="h-20 w-20 object-cover rounded border border-gray-300 dark:border-gray-600">

            @if ($edit)
                {{-- What is already saved, until a new file replaces it on screen. --}}
                <template x-if="!preview && {{ $p }}image_path">
                    <div class="flex items-start gap-2">
                        <img :src="'{{ asset('storage') }}/' + {{ $p }}image_path" alt=""
                             class="h-20 w-20 object-cover rounded border border-gray-300 dark:border-gray-600">
                        {{--
                            Removing the picture is its own request, and HTML forbids
                            nesting forms, so this button drives the form that sits
                            outside this one (see products/index).
                        --}}
                        <button type="submit" form="remove-product-image"
                                onclick="return confirm('Remove this picture?');"
                                class="text-xs text-red-600 dark:text-red-400 hover:underline">Remove</button>
                    </div>
                </template>
            @endif
        </div>
    </div>

</div>
