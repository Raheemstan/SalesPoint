@php
    /*
     * One form for both the add and edit modals. $p prefixes every Alpine
     * binding: the edit modal fills an `editProduct` object from the row, the add
     * modal binds straight onto the page's own scope.
     *
     * $idPrefix keeps the two copies of this form from sharing element ids: both
     * are rendered on products/index at once, so a bare id="name" would appear
     * twice and every <label for="name"> would point at whichever came first.
     */
    $edit = isset($edit) && $edit;
    $p = $edit ? 'editProduct.' : '';
    $idPrefix = $edit ? 'edit_' : 'add_';

    /*
     * No focus:outline-none here. The shell styles :focus-visible globally
     * (resources/css/app.css), which rings a keyboard user without ringing a
     * mouse click; the old classes removed the outline and replaced it with a
     * focus: ring that fired on every click.
     *
     * min-h-touch because this form is filled in on a tablet.
     */
    $inputClass = 'min-h-touch w-full rounded-md border-gray-300 bg-white shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white';
    $labelClass = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
    $hintClass = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $panelClass = 'rounded-lg bg-gray-50 p-3 ring-1 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700';
    $checkRowClass = 'inline-flex min-h-touch items-center gap-2 text-sm text-gray-700 dark:text-gray-200';

    $productLabel = term('product', false, 'Product');
    $categoryLabel = term('category', false, 'Category');
    $supplierLabel = term('supplier', false, 'Supplier');
@endphp

<div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">

    <div>
        <label for="{{ $idPrefix }}name" class="{{ $labelClass }}">
            Name <span class="text-red-600" aria-hidden="true">*</span>
        </label>
        <input id="{{ $idPrefix }}name" type="text" name="name"
               x-model="{{ $p }}name"
               value="{{ old('name') }}"
               required
               class="{{ $inputClass }}">
    </div>

    <div>
        <label for="{{ $idPrefix }}sku" class="{{ $labelClass }}">SKU</label>
        <input id="{{ $idPrefix }}sku" type="text" name="sku"
               x-model="{{ $p }}sku"
               value="{{ old('sku') }}"
               class="{{ $inputClass }}">
    </div>

    <div>
        <label for="{{ $idPrefix }}barcode" class="{{ $labelClass }}">Barcode</label>
        <input id="{{ $idPrefix }}barcode" type="text" name="barcode"
               x-model="{{ $p }}barcode"
               value="{{ old('barcode') }}"
               class="{{ $inputClass }}">
    </div>

    {{--
        Category and supplier are type-to-filter lists: a supermarket has
        hundreds of each, and a native select is unusable at that length on a
        touchscreen. x-searchable-select keeps the real <select> - and so the
        field name, the change event and old() repopulation - and only puts a
        filterable listbox in front of it.

        The edit copy is populated from the row that was clicked, so the wrapper
        carries an x-effect that writes the chosen id onto the real select and
        fires a change, which is what the component listens for to refresh its
        visible label. Setting the value silently would leave the button reading
        the wrong name.

        The x-effect sits on this plain <div> and not on the component tag: a
        Blade @if inside a component tag's attribute list stops the tag being
        recognised as a component at all, and it renders as inert literal
        markup. [&>div]:mb-0 cancels the component's own mb-4, since the grid's
        gap-y already spaces these.
    --}}
    <div class="[&>div]:mb-0"
         @if ($edit)
             x-effect="
                const select = $el.querySelector('select');
                if (select) {
                    select.value = editProduct.category_id ?? '';
                    select.dispatchEvent(new Event('change'));
                }
             "
         @endif>
        <x-searchable-select
            name="category_id"
            :id="$idPrefix . 'category_id'"
            :label="$categoryLabel"
            :options="$categories"
            option-value="id"
            option-label="name"
            :placeholder="'Select ' . Str::lower($categoryLabel)"
            :search-placeholder="'Type to filter ' . Str::lower(term('category', true))"
        />
    </div>

    {{-- Who this is normally bought from: it is what groups the reorder list. --}}
    <div class="[&>div]:mb-0"
         @if ($edit)
             x-effect="
                const select = $el.querySelector('select');
                if (select) {
                    select.value = editProduct.supplier_id ?? '';
                    select.dispatchEvent(new Event('change'));
                }
             "
         @endif>
        <x-searchable-select
            name="supplier_id"
            :id="$idPrefix . 'supplier_id'"
            :label="$supplierLabel"
            :options="$suppliers ?? []"
            option-value="id"
            option-label="name"
            :placeholder="'No preferred ' . Str::lower($supplierLabel)"
            :search-placeholder="'Type to filter ' . Str::lower(term('supplier', true))"
        />
    </div>

    <div class="flex items-end">
        {{-- An unticked box sends nothing, so a 0 goes ahead of it. --}}
        <input type="hidden" name="is_active" value="0">
        <label for="{{ $idPrefix }}is_active" class="{{ $checkRowClass }}">
            <input id="{{ $idPrefix }}is_active" type="checkbox" name="is_active" value="1"
                   class="rounded border-gray-300 text-primary-600 dark:border-gray-600 dark:bg-gray-800"
                   x-model="{{ $p }}is_active"
                   @if (!$edit) checked @endif>
            Active
        </label>
    </div>

    <div>
        <label for="{{ $idPrefix }}cost_price" class="{{ $labelClass }}">
            Cost Price ({{ currency() }}) <span class="text-red-600" aria-hidden="true">*</span>
        </label>
        <input id="{{ $idPrefix }}cost_price" type="number" step="0.01" name="cost_price"
               x-model="{{ $p }}cost_price"
               value="{{ old('cost_price') }}"
               required
               class="{{ $inputClass }} text-right tabular-nums">
    </div>

    <div>
        <label for="{{ $idPrefix }}sale_price" class="{{ $labelClass }}">
            Sale Price ({{ currency() }}) <span class="text-red-600" aria-hidden="true">*</span>
        </label>
        <input id="{{ $idPrefix }}sale_price" type="number" step="0.01" name="sale_price"
               x-model="{{ $p }}sale_price"
               value="{{ old('sale_price') }}"
               required
               class="{{ $inputClass }} text-right tabular-nums">
    </div>

    @if (feature('service_items'))
        {{--
            A service - labour, a haircut, a delivery fee - never runs out, so the
            stock fields below are hidden rather than filled with a zero that would
            put it on the low-stock list and block a sale.
        --}}
        <div class="md:col-span-2 {{ $panelClass }}">
            <input type="hidden" name="is_service" value="0">
            <label for="{{ $idPrefix }}is_service" class="{{ $checkRowClass }}">
                <input id="{{ $idPrefix }}is_service" type="checkbox" name="is_service" value="1"
                       class="rounded border-gray-300 text-primary-600 dark:border-gray-600 dark:bg-gray-800"
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
                <label for="{{ $idPrefix }}variant_label" class="{{ $labelClass }}">Variant label</label>
                <input id="{{ $idPrefix }}variant_label" type="text" name="variant_label" maxlength="120"
                       placeholder="Red / XL"
                       x-model="{{ $p }}variant_label"
                       class="{{ $inputClass }}">
                <p class="{{ $hintClass }}">What tells this one apart from the others in its style.</p>
            </div>
        </template>

        {{-- A style is a heading, not something you sell. --}}
        <template x-if="{{ $p }}has_variants">
            <div class="md:col-span-2 rounded-lg bg-purple-50 p-3 text-sm text-purple-900 ring-1 ring-purple-200 dark:bg-purple-900/30 dark:text-purple-100 dark:ring-purple-800">
                This {{ Str::lower($productLabel) }} has variants, so its stock is the total of theirs and it is
                not offered at the till. Prices and stock are set on each variant.
            </div>
        </template>
    @endif

    @if (feature('serials'))
        {{-- Electronics: each unit is individually identifiable. --}}
        <div class="md:col-span-2 {{ $panelClass }}">
            <input type="hidden" name="serial_required" value="0">
            <label for="{{ $idPrefix }}serial_required" class="{{ $checkRowClass }}">
                <input id="{{ $idPrefix }}serial_required" type="checkbox" name="serial_required" value="1"
                       class="rounded border-gray-300 text-primary-600 dark:border-gray-600 dark:bg-gray-800"
                       x-model="{{ $p }}serial_required">
                Record a serial / IMEI for each unit sold
            </label>
            <p class="{{ $hintClass }}">Serials are entered against the {{ Str::lower(term('sale', false, 'Sale')) }} and can be looked up later.</p>
        </div>
    @endif

    @if (feature('units'))
        <div>
            <label for="{{ $idPrefix }}unit" class="{{ $labelClass }}">Unit sold</label>
            <input id="{{ $idPrefix }}unit" type="text" name="unit" maxlength="20" placeholder="piece"
                   x-model="{{ $p }}unit"
                   value="{{ old('unit') }}"
                   class="{{ $inputClass }}">
        </div>

        <div>
            <label for="{{ $idPrefix }}pack_size" class="{{ $labelClass }}">Units per pack bought</label>
            <input id="{{ $idPrefix }}pack_size" type="number" step="0.001" min="0.001" name="pack_size"
                   placeholder="1"
                   x-model="{{ $p }}pack_size"
                   value="{{ old('pack_size') }}"
                   class="{{ $inputClass }} text-right tabular-nums">
            <p class="{{ $hintClass }}">A carton of 12 sold singly: unit "piece", 12 per pack.</p>
        </div>
    @endif

    {{--
        Stock. Hidden for a service, for a trade that counts nothing at all, and
        for a style whose stock lives on its variants.
    --}}
    @if (feature('stock'))
        <template x-if="!{{ $p }}is_service && !{{ $p }}has_variants">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:col-span-2 md:grid-cols-3">
                <div>
                    <label for="{{ $idPrefix }}stock_quantity" class="{{ $labelClass }}">
                        Stock Quantity <span class="text-red-600" aria-hidden="true">*</span>
                    </label>
                    <input id="{{ $idPrefix }}stock_quantity" type="number" name="stock_quantity"
                           x-model="{{ $p }}stock_quantity"
                           value="{{ old('stock_quantity') }}"
                           required
                           class="{{ $inputClass }} text-right tabular-nums">
                </div>

                <div>
                    <label for="{{ $idPrefix }}alert_quantity" class="{{ $labelClass }}">Low-stock level</label>
                    <input id="{{ $idPrefix }}alert_quantity" type="number" min="0" name="alert_quantity"
                           x-model="{{ $p }}alert_quantity"
                           value="{{ old('alert_quantity') }}"
                           class="{{ $inputClass }} text-right tabular-nums">
                    <p class="{{ $hintClass }}">Blank uses the shop-wide level ({{ (int) setting('low_stock_threshold', 5) }}).</p>
                </div>

                <div>
                    <label for="{{ $idPrefix }}reorder_quantity" class="{{ $labelClass }}">Reorder quantity</label>
                    <input id="{{ $idPrefix }}reorder_quantity" type="number" min="0" name="reorder_quantity"
                           x-model="{{ $p }}reorder_quantity"
                           value="{{ old('reorder_quantity') }}"
                           class="{{ $inputClass }} text-right tabular-nums">
                    <p class="{{ $hintClass }}">How many to put on an order when it runs low.</p>
                </div>

                @if (feature('expiry'))
                    <div>
                        <label for="{{ $idPrefix }}batch_no" class="{{ $labelClass }}">Batch number</label>
                        <input id="{{ $idPrefix }}batch_no" type="text" name="batch_no" maxlength="100"
                               x-model="{{ $p }}batch_no"
                               value="{{ old('batch_no') }}"
                               class="{{ $inputClass }}">
                    </div>

                    <div>
                        <label for="{{ $idPrefix }}expiry_date" class="{{ $labelClass }}">Expiry date</label>
                        <input id="{{ $idPrefix }}expiry_date" type="date" name="expiry_date"
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
        <label for="{{ $idPrefix }}image" class="{{ $labelClass }}">Picture</label>

        <div class="flex flex-wrap items-start gap-4">
            <div class="min-w-0">
                <input id="{{ $idPrefix }}image" type="file" name="image" accept="image/jpeg,image/png,image/webp"
                       @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null"
                       class="block w-full text-sm text-gray-700 file:mr-3 file:min-h-touch file:rounded-md file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-primary-700 hover:file:bg-primary-100 dark:text-gray-200 dark:file:bg-primary-900/40 dark:file:text-primary-200">
                <p class="{{ $hintClass }}">JPEG, PNG or WebP, up to 2 MB.</p>
            </div>

            {{-- The file just chosen, before it is uploaded. --}}
            <img x-show="preview" x-cloak :src="preview" alt=""
                 class="h-20 w-20 rounded-lg object-cover ring-1 ring-gray-300 dark:ring-gray-600">

            @if ($edit)
                {{-- What is already saved, until a new file replaces it on screen. --}}
                <template x-if="!preview && {{ $p }}image_path">
                    <div class="flex items-start gap-2">
                        {{--
                            image_url is null when the row names a file that is
                            not on the disk. Saying so is more use than a broken
                            image, and the condition stays on image_path so the
                            Remove button is still reachable - clearing the
                            dangling name is exactly what you want to do next.
                        --}}
                        <template x-if="{{ $p }}image_url">
                            <img :src="{{ $p }}image_url" alt="" onerror="this.remove()"
                                 class="h-20 w-20 rounded-lg object-cover ring-1 ring-gray-300 dark:ring-gray-600">
                        </template>

                        <template x-if="!{{ $p }}image_url">
                            <p class="max-w-xs text-xs text-amber-700 dark:text-amber-300">
                                The saved picture is no longer on the server. Choose a new file, or remove it.
                            </p>
                        </template>

                        {{--
                            Removing the picture is its own request, and HTML forbids
                            nesting forms, so this button drives the form that sits
                            outside this one (see products/index).
                        --}}
                        <button type="submit" form="remove-product-image"
                                onclick="return confirm('Remove this picture?');"
                                class="inline-flex min-h-touch items-center text-xs font-medium text-red-600 hover:underline dark:text-red-400">
                            Remove
                        </button>
                    </div>
                </template>
            @endif
        </div>
    </div>

</div>
