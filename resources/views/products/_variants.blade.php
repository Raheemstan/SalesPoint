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
     *
     * No focus:outline-none anywhere here - the shell rings :focus-visible.
     */
    $vInput = 'min-h-touch w-full rounded-md border-gray-300 bg-white text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white';

    /*
     * The columns are named once in a header row from md up, so each row's own
     * labels are there for a screen reader and for the stacked phone layout but
     * do not repeat down the screen on a tablet.
     */
    $vLabel = 'mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400 md:sr-only';
    $vHead = 'text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $vHint = 'text-xs text-gray-500 dark:text-gray-400';

    $styleLabel = Str::lower(term('product', false, 'Product'));
    $variants = $product->variants;
    $stockOn = feature('stock');

    // Label / SKU / Barcode / Cost / Price (+ Stock / Low at) / Save / Remove
    $cols = $stockOn ? 'md:grid-cols-10' : 'md:grid-cols-8';
@endphp

<div class="space-y-5 py-4">

    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h4 class="font-semibold text-gray-900 dark:text-gray-100">
            Variants of {{ $product->name }}
        </h4>
        <p class="{{ $vHint }} max-w-xl">
            Each variant is counted, priced and sold in its own right. The {{ $styleLabel }} itself is
            only a heading: its stock is the total below and it is not offered at the till.
        </p>
    </div>

    {{-- What already exists. Each row is its own form; HTML forbids nesting them. --}}
    @if ($variants->isNotEmpty())
        <div class="space-y-2">
            {{-- Column names, once, from md up. --}}
            <div class="hidden gap-2 px-2 {{ $cols }} md:grid" aria-hidden="true">
                <div class="{{ $vHead }} md:col-span-2">Label</div>
                <div class="{{ $vHead }}">SKU</div>
                <div class="{{ $vHead }}">Barcode</div>
                <div class="{{ $vHead }} text-right">Cost ({{ currency() }})</div>
                <div class="{{ $vHead }} text-right">Price ({{ currency() }})</div>
                @if ($stockOn)
                    <div class="{{ $vHead }} text-right">Stock</div>
                    <div class="{{ $vHead }} text-right">Low at</div>
                @endif
                <div class="{{ $vHead }} md:col-span-2"></div>
            </div>

            @foreach ($variants as $variant)
                @php $vid = 'variant-' . $variant->id; @endphp

                <div class="grid grid-cols-2 items-end gap-2 rounded-lg bg-white p-2 ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 {{ $cols }}">
                    <form method="POST" action="/products/{{ $variant->id }}" class="contents">
                        @csrf
                        @method('PUT')

                        {{-- Required by the product form these rows post to; not edited here. --}}
                        <input type="hidden" name="name" value="{{ $variant->name }}">
                        <input type="hidden" name="category_id" value="{{ $variant->category_id }}">

                        <div class="col-span-2">
                            <label for="{{ $vid }}-label" class="{{ $vLabel }}">Label</label>
                            <input id="{{ $vid }}-label" type="text" name="variant_label"
                                   value="{{ $variant->variant_label }}" maxlength="120"
                                   aria-label="Label for {{ $variant->name }}"
                                   class="{{ $vInput }}">
                        </div>

                        <div>
                            <label for="{{ $vid }}-sku" class="{{ $vLabel }}">SKU</label>
                            <input id="{{ $vid }}-sku" type="text" name="sku" value="{{ $variant->sku }}"
                                   maxlength="100" aria-label="SKU for {{ $variant->name }}"
                                   class="{{ $vInput }}">
                        </div>

                        <div>
                            <label for="{{ $vid }}-barcode" class="{{ $vLabel }}">Barcode</label>
                            <input id="{{ $vid }}-barcode" type="text" name="barcode" value="{{ $variant->barcode }}"
                                   maxlength="100" aria-label="Barcode for {{ $variant->name }}"
                                   class="{{ $vInput }}">
                        </div>

                        <div>
                            <label for="{{ $vid }}-cost" class="{{ $vLabel }}">Cost ({{ currency() }})</label>
                            <input id="{{ $vid }}-cost" type="number" step="0.01" min="0" name="cost_price"
                                   value="{{ (float) $variant->cost_price }}" required
                                   aria-label="Cost price for {{ $variant->name }}"
                                   class="{{ $vInput }} text-right tabular-nums">
                        </div>

                        <div>
                            <label for="{{ $vid }}-price" class="{{ $vLabel }}">Price ({{ currency() }})</label>
                            <input id="{{ $vid }}-price" type="number" step="0.01" min="0" name="sale_price"
                                   value="{{ (float) $variant->sale_price }}" required
                                   aria-label="Sale price for {{ $variant->name }}"
                                   class="{{ $vInput }} text-right tabular-nums">
                        </div>

                        @if ($stockOn)
                            <div>
                                <label for="{{ $vid }}-stock" class="{{ $vLabel }}">Stock</label>
                                <input id="{{ $vid }}-stock" type="number" name="stock_quantity"
                                       value="{{ (int) $variant->stock_quantity }}" required
                                       aria-label="Stock for {{ $variant->name }}"
                                       class="{{ $vInput }} text-right tabular-nums">
                            </div>

                            <div>
                                <label for="{{ $vid }}-low" class="{{ $vLabel }}">Low at</label>
                                <input id="{{ $vid }}-low" type="number" min="0" name="alert_quantity"
                                       value="{{ $variant->alert_quantity }}"
                                       aria-label="Low-stock level for {{ $variant->name }}"
                                       class="{{ $vInput }} text-right tabular-nums">
                            </div>
                        @endif

                        <div>
                            <button type="submit"
                                    class="inline-flex min-h-touch w-full items-center justify-center gap-1.5 rounded-md bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                                Save
                            </button>
                        </div>
                    </form>

                    <div>
                        <form method="POST" action="/products/{{ $variant->id }}"
                              onsubmit="return confirm('Delete the {{ $variant->variant_label }} variant?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="inline-flex min-h-touch w-full items-center justify-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium text-red-600 ring-1 ring-red-200 hover:bg-red-50 dark:text-red-400 dark:ring-red-900 dark:hover:bg-red-950"
                                    aria-label="Remove the {{ $variant->variant_label }} variant">
                                Remove
                            </button>
                        </form>
                    </div>

                    <p class="col-span-2 flex flex-wrap items-center gap-2 {{ $vHint }} {{ $stockOn ? 'md:col-span-10' : 'md:col-span-8' }}">
                        @if ($variant->isLowStock())
                            <x-badge variant="amber" size="sm" dot>Low stock</x-badge>
                        @endif
                        @unless ($variant->is_active)
                            <x-badge variant="gray" size="sm">inactive</x-badge>
                        @endunless
                        <span class="tabular-nums">Stock value {{ money($variant->stock_value) }}</span>
                    </p>
                </div>
            @endforeach
        </div>

        <p class="text-sm text-gray-700 dark:text-gray-300">
            Total stock across {{ $variants->count() }} variant(s):
            <strong class="tabular-nums">{{ $product->totalStock() }}</strong>
            &middot; valued at <span class="tabular-nums">{{ money($product->totalStockValue()) }}</span>
        </p>
    @else
        <p class="{{ $vHint }}">No variants yet. Add one below, or generate a whole grid of them.</p>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

        {{-- One at a time --}}
        <x-card title="Add one variant">
            <form method="POST" action="/products/{{ $product->id }}/variants" class="space-y-3">
                @csrf

                <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                    <div class="col-span-2">
                        <label for="new-variant-label-{{ $product->id }}" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">
                            Label <span class="text-red-600" aria-hidden="true">*</span>
                        </label>
                        <input id="new-variant-label-{{ $product->id }}" type="text" name="variant_label"
                               placeholder="Red / XL" required maxlength="120" class="{{ $vInput }}">
                    </div>

                    <div>
                        <label for="new-variant-sku-{{ $product->id }}" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">SKU</label>
                        <input id="new-variant-sku-{{ $product->id }}" type="text" name="sku"
                               placeholder="{{ $product->sku }}-RED-XL" maxlength="100" class="{{ $vInput }}">
                        <p class="{{ $vHint }} mt-1">Blank builds one from the {{ $styleLabel }}'s SKU.</p>
                    </div>

                    <div>
                        <label for="new-variant-barcode-{{ $product->id }}" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Barcode</label>
                        <input id="new-variant-barcode-{{ $product->id }}" type="text" name="barcode"
                               maxlength="100" class="{{ $vInput }}">
                    </div>

                    <div>
                        <label for="new-variant-cost-{{ $product->id }}" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Cost ({{ currency() }})</label>
                        <input id="new-variant-cost-{{ $product->id }}" type="number" step="0.01" min="0" name="cost_price"
                               placeholder="{{ money($product->cost_price, false) }}"
                               class="{{ $vInput }} text-right tabular-nums">
                    </div>

                    <div>
                        <label for="new-variant-price-{{ $product->id }}" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Price ({{ currency() }})</label>
                        <input id="new-variant-price-{{ $product->id }}" type="number" step="0.01" min="0" name="sale_price"
                               placeholder="{{ money($product->sale_price, false) }}"
                               class="{{ $vInput }} text-right tabular-nums">
                    </div>

                    @if ($stockOn)
                        <div>
                            <label for="new-variant-stock-{{ $product->id }}" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Opening stock</label>
                            <input id="new-variant-stock-{{ $product->id }}" type="number" min="0" name="stock_quantity"
                                   placeholder="0" class="{{ $vInput }} text-right tabular-nums">
                        </div>

                        <div>
                            <label for="new-variant-reorder-{{ $product->id }}" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Reorder quantity</label>
                            <input id="new-variant-reorder-{{ $product->id }}" type="number" min="0" name="reorder_quantity"
                                   placeholder="{{ (int) $product->reorder_quantity }}"
                                   class="{{ $vInput }} text-right tabular-nums">
                        </div>
                    @endif
                </div>

                <p class="{{ $vHint }}">
                    {{ term('category', false, 'Category') }}, {{ Str::lower(term('supplier', false, 'supplier')) }}
                    and unit are inherited from the {{ $styleLabel }} unless you change them on the variant itself.
                </p>

                <button type="submit"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    <x-icon name="plus" size="4" />
                    Add variant
                </button>
            </form>
        </x-card>

        {{-- The whole grid at once: the thing that makes this usable. --}}
        <x-card title="Generate variants"
                subtitle="Two lists, separated by commas.">
            <form method="POST" action="/products/{{ $product->id }}/variants/generate" class="space-y-3">
                @csrf

                <p class="{{ $vHint }}">
                    Sizes S, M, L and colours Red, Blue make six variants.
                    Combinations that already exist are skipped, so you can come back and add a size later.
                </p>

                <div class="grid grid-cols-3 gap-x-4 gap-y-3">
                    <div>
                        <label for="axis-one-name-{{ $product->id }}" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">First axis</label>
                        <input id="axis-one-name-{{ $product->id }}" type="text" name="axis_one_name"
                               placeholder="Size" maxlength="40" class="{{ $vInput }}">
                    </div>
                    <div class="col-span-2">
                        <label for="axis-one-{{ $product->id }}" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">
                            Values <span class="text-red-600" aria-hidden="true">*</span>
                        </label>
                        <input id="axis-one-{{ $product->id }}" type="text" name="axis_one"
                               placeholder="S, M, L" required maxlength="500" class="{{ $vInput }}">
                    </div>

                    <div>
                        <label for="axis-two-name-{{ $product->id }}" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Second axis</label>
                        <input id="axis-two-name-{{ $product->id }}" type="text" name="axis_two_name"
                               placeholder="Colour" maxlength="40" class="{{ $vInput }}">
                    </div>
                    <div class="col-span-2">
                        <label for="axis-two-{{ $product->id }}" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Values (optional)</label>
                        <input id="axis-two-{{ $product->id }}" type="text" name="axis_two"
                               placeholder="Red, Blue" maxlength="500" class="{{ $vInput }}">
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-x-4 gap-y-3">
                    <div>
                        <label for="gen-cost-{{ $product->id }}" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Cost each ({{ currency() }})</label>
                        <input id="gen-cost-{{ $product->id }}" type="number" step="0.01" min="0" name="cost_price"
                               placeholder="{{ money($product->cost_price, false) }}"
                               class="{{ $vInput }} text-right tabular-nums">
                    </div>
                    <div>
                        <label for="gen-price-{{ $product->id }}" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Price each ({{ currency() }})</label>
                        <input id="gen-price-{{ $product->id }}" type="number" step="0.01" min="0" name="sale_price"
                               placeholder="{{ money($product->sale_price, false) }}"
                               class="{{ $vInput }} text-right tabular-nums">
                    </div>
                    @if ($stockOn)
                        <div>
                            <label for="gen-stock-{{ $product->id }}" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">Stock each</label>
                            <input id="gen-stock-{{ $product->id }}" type="number" min="0" name="stock_quantity"
                                   placeholder="0" class="{{ $vInput }} text-right tabular-nums">
                        </div>
                    @endif
                </div>

                <button type="submit"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    Generate
                </button>
            </form>
        </x-card>
    </div>
</div>
