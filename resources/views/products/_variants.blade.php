@php
    /*
     * The variant panel for one style, shown under its row in the product list.
     *
     * A variant is a product with a parent, so editing or deleting one is the
     * ordinary product update and delete - these rows post straight at
     * /products/{id}. Only creating has its own door (/products/{style}/variants),
     * because that is where the rules live: one level only, category, supplier
     * and unit inherited from the style, and a SKU built from the style's own.
     *
     * URLs are written out rather than built with route(), as the edit modal in
     * products/index already does: the panel renders whether or not the named
     * routes are registered yet.
     */
    $vInput = "w-full rounded border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm bg-white dark:bg-gray-800 focus:ring-2 focus:ring-blue-500 focus:outline-none";
    $vLabel = "block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1";
    $vHint = "text-xs text-gray-500 dark:text-gray-400";
    $styleLabel = Str::lower(term('product', false, 'Product'));
    $variants = $product->variants;
@endphp

<div class="p-4 rounded bg-gray-50 dark:bg-gray-900/40 space-y-5">

    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h4 class="font-semibold text-gray-800 dark:text-gray-100">
            Variants of {{ $product->name }}
        </h4>
        <p class="{{ $vHint }}">
            Each variant is counted, priced and sold in its own right. The {{ $styleLabel }} itself is
            only a heading: its stock is the total below and it is not offered at the till.
        </p>
    </div>

    {{-- What already exists. Each row is its own form; HTML forbids nesting them. --}}
    @if ($variants->isNotEmpty())
        <div class="space-y-2">
            @foreach ($variants as $variant)
                <div class="grid grid-cols-2 md:grid-cols-8 gap-2 items-end p-2 rounded bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                    <form method="POST" action="/products/{{ $variant->id }}" class="contents">
                        @csrf
                        @method('PUT')

                        {{-- Required by the product form these rows post to; not edited here. --}}
                        <input type="hidden" name="name" value="{{ $variant->name }}">
                        <input type="hidden" name="category_id" value="{{ $variant->category_id }}">

                        <div class="col-span-2">
                            <label class="{{ $vLabel }}">Label</label>
                            <input type="text" name="variant_label" value="{{ $variant->variant_label }}"
                                   maxlength="120" class="{{ $vInput }}">
                        </div>

                        <div>
                            <label class="{{ $vLabel }}">SKU</label>
                            <input type="text" name="sku" value="{{ $variant->sku }}" maxlength="100" class="{{ $vInput }}">
                        </div>

                        <div>
                            <label class="{{ $vLabel }}">Barcode</label>
                            <input type="text" name="barcode" value="{{ $variant->barcode }}" maxlength="100" class="{{ $vInput }}">
                        </div>

                        <div>
                            <label class="{{ $vLabel }}">Cost ({{ currency() }})</label>
                            <input type="number" step="0.01" min="0" name="cost_price" value="{{ (float) $variant->cost_price }}"
                                   required class="{{ $vInput }}">
                        </div>

                        <div>
                            <label class="{{ $vLabel }}">Price ({{ currency() }})</label>
                            <input type="number" step="0.01" min="0" name="sale_price" value="{{ (float) $variant->sale_price }}"
                                   required class="{{ $vInput }}">
                        </div>

                        @if (feature('stock'))
                            <div>
                                <label class="{{ $vLabel }}">Stock</label>
                                <input type="number" name="stock_quantity" value="{{ (int) $variant->stock_quantity }}"
                                       required class="{{ $vInput }}">
                            </div>

                            <div>
                                <label class="{{ $vLabel }}">Low at</label>
                                <input type="number" min="0" name="alert_quantity" value="{{ $variant->alert_quantity }}"
                                       class="{{ $vInput }}">
                            </div>
                        @endif

                        <div class="md:col-span-1">
                            <button type="submit"
                                    class="w-full bg-yellow-500 hover:bg-yellow-600 text-white text-sm px-3 py-1.5 rounded">
                                Save
                            </button>
                        </div>
                    </form>

                    <div class="md:col-span-1">
                        <form method="POST" action="/products/{{ $variant->id }}"
                              onsubmit="return confirm('Delete the {{ $variant->variant_label }} variant?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="w-full bg-red-600 hover:bg-red-700 text-white text-sm px-3 py-1.5 rounded">
                                Remove
                            </button>
                        </form>
                    </div>

                    <p class="col-span-2 md:col-span-8 {{ $vHint }}">
                        @if ($variant->isLowStock())
                            <span class="text-red-600 dark:text-red-400">Low stock</span> &middot;
                        @endif
                        Stock value {{ money($variant->stock_value) }}
                        @unless ($variant->is_active) &middot; inactive @endunless
                    </p>
                </div>
            @endforeach
        </div>

        <p class="text-sm text-gray-700 dark:text-gray-300">
            Total stock across {{ $variants->count() }} variant(s):
            <strong>{{ $product->totalStock() }}</strong>
            &middot; valued at {{ money($product->totalStockValue()) }}
        </p>
    @else
        <p class="{{ $vHint }}">No variants yet. Add one below, or generate a whole grid of them.</p>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

        {{-- One at a time --}}
        <form method="POST" action="/products/{{ $product->id }}/variants"
              class="p-3 rounded bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 space-y-3">
            @csrf
            <h5 class="font-medium text-gray-800 dark:text-gray-100">Add one variant</h5>

            <div class="grid grid-cols-2 gap-2">
                <div class="col-span-2">
                    <label class="{{ $vLabel }}">Label</label>
                    <input type="text" name="variant_label" placeholder="Red / XL" required maxlength="120" class="{{ $vInput }}">
                </div>

                <div>
                    <label class="{{ $vLabel }}">SKU</label>
                    <input type="text" name="sku" placeholder="{{ $product->sku }}-RED-XL" maxlength="100" class="{{ $vInput }}">
                    <p class="{{ $vHint }}">Blank builds one from the {{ $styleLabel }}'s SKU.</p>
                </div>

                <div>
                    <label class="{{ $vLabel }}">Barcode</label>
                    <input type="text" name="barcode" maxlength="100" class="{{ $vInput }}">
                </div>

                <div>
                    <label class="{{ $vLabel }}">Cost ({{ currency() }})</label>
                    <input type="number" step="0.01" min="0" name="cost_price"
                           placeholder="{{ money($product->cost_price, false) }}" class="{{ $vInput }}">
                </div>

                <div>
                    <label class="{{ $vLabel }}">Price ({{ currency() }})</label>
                    <input type="number" step="0.01" min="0" name="sale_price"
                           placeholder="{{ money($product->sale_price, false) }}" class="{{ $vInput }}">
                </div>

                @if (feature('stock'))
                    <div>
                        <label class="{{ $vLabel }}">Opening stock</label>
                        <input type="number" min="0" name="stock_quantity" placeholder="0" class="{{ $vInput }}">
                    </div>

                    <div>
                        <label class="{{ $vLabel }}">Reorder quantity</label>
                        <input type="number" min="0" name="reorder_quantity"
                               placeholder="{{ (int) $product->reorder_quantity }}" class="{{ $vInput }}">
                    </div>
                @endif
            </div>

            <p class="{{ $vHint }}">
                {{ term('category', false, 'Category') }}, {{ Str::lower(term('supplier', false, 'supplier')) }}
                and unit are inherited from the {{ $styleLabel }} unless you change them on the variant itself.
            </p>

            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">
                Add variant
            </button>
        </form>

        {{-- The whole grid at once: the thing that makes this usable. --}}
        <form method="POST" action="/products/{{ $product->id }}/variants/generate"
              class="p-3 rounded bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 space-y-3">
            @csrf
            <h5 class="font-medium text-gray-800 dark:text-gray-100">Generate variants</h5>
            <p class="{{ $vHint }}">
                Two lists, separated by commas. Sizes S, M, L and colours Red, Blue make six variants.
                Combinations that already exist are skipped, so you can come back and add a size later.
            </p>

            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label class="{{ $vLabel }}">First axis</label>
                    <input type="text" name="axis_one_name" placeholder="Size" maxlength="40" class="{{ $vInput }}">
                </div>
                <div class="col-span-2">
                    <label class="{{ $vLabel }}">Values</label>
                    <input type="text" name="axis_one" placeholder="S, M, L" required maxlength="500" class="{{ $vInput }}">
                </div>

                <div>
                    <label class="{{ $vLabel }}">Second axis</label>
                    <input type="text" name="axis_two_name" placeholder="Colour" maxlength="40" class="{{ $vInput }}">
                </div>
                <div class="col-span-2">
                    <label class="{{ $vLabel }}">Values (optional)</label>
                    <input type="text" name="axis_two" placeholder="Red, Blue" maxlength="500" class="{{ $vInput }}">
                </div>
            </div>

            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label class="{{ $vLabel }}">Cost each ({{ currency() }})</label>
                    <input type="number" step="0.01" min="0" name="cost_price"
                           placeholder="{{ money($product->cost_price, false) }}" class="{{ $vInput }}">
                </div>
                <div>
                    <label class="{{ $vLabel }}">Price each ({{ currency() }})</label>
                    <input type="number" step="0.01" min="0" name="sale_price"
                           placeholder="{{ money($product->sale_price, false) }}" class="{{ $vInput }}">
                </div>
                @if (feature('stock'))
                    <div>
                        <label class="{{ $vLabel }}">Stock each</label>
                        <input type="number" min="0" name="stock_quantity" placeholder="0" class="{{ $vInput }}">
                    </div>
                @endif
            </div>

            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm">
                Generate
            </button>
        </form>
    </div>
</div>
