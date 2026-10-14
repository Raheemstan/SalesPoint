@extends('layouts.app')

@section('title', 'New ' . term('purchase', false, 'Purchase'))

@section('content')
    @php
        $label = term('purchase', false, 'Purchase');
        $productLabel = term('product', false, 'Product');
        $supplierLabel = term('supplier', false, 'Supplier');

        $input = 'min-h-touch w-full rounded-md border-gray-300 bg-white shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white';
        $labelClass = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
        $hintClass = 'mt-1 text-xs text-gray-500 dark:text-gray-400';

        /*
        | The catalogue, handed to the row builder in one go so choosing a
        | product can fill in its cost price without another request.
        |
        | This replaced a row builder that assembled its markup in a JS template
        | string - Blade components and a @foreach of <option>s inside a
        | backtick literal, which meant every added row carried a duplicate of
        | the same <label for="..."> and ids. The lines are now an Alpine
        | repeater over server-rendered <option>s, which is the same shape
        | purchases/edit.blade.php already uses.
        */
        $catalogue = $products->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'cost' => (float) $p->cost_price,
        ])->values();

        // Whatever was typed if validation bounced, else one empty line to start on.
        $rows = old('product_ids')
            ? collect(old('product_ids'))->map(fn($id, $i) => [
                'product_id' => (int) $id,
                'quantity' => (int) (old('quantities')[$i] ?? 1),
                'cost_price' => (float) (old('cost_prices')[$i] ?? 0),
            ])->values()
            : collect([['product_id' => '', 'quantity' => 1, 'cost_price' => 0]]);

        $decimals = (int) setting('currency_decimals', config('sectors.currency.decimals'));
    @endphp

    <div x-data="{
            supplierModal: false,
            products: {{ Js::from($catalogue) }},
            rows: {{ Js::from($rows) }},
            addRow() {
                this.rows.push({ product_id: '', quantity: 1, cost_price: 0 });
            },
            removeRow(index) {
                this.rows.splice(index, 1);
            },
            onProductChange(row) {
                const product = this.products.find(p => p.id == row.product_id);
                if (product && !Number(row.cost_price)) {
                    row.cost_price = product.cost;
                }
            },
            lineTotal(row) {
                return (Number(row.quantity) || 0) * (Number(row.cost_price) || 0);
            },
            get total() {
                return this.rows.reduce((sum, row) => sum + this.lineTotal(row), 0);
            },
            money(value) {
                return @js(currency()) + (Number(value) || 0).toLocaleString(undefined, {
                    minimumFractionDigits: {{ $decimals }},
                    maximumFractionDigits: {{ $decimals }},
                });
            },
         }">

        <x-page-header :title="'New ' . $label"
                       subtitle="Type in a delivery, or raise an order for goods still to come"
                       :back="route('purchases.index')"
                       :back-label="term('purchase', true, 'Purchases')">
            <x-slot:actions>
                <button type="button" @click="supplierModal = true"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                    <x-icon name="plus" size="4" />
                    Add {{ $supplierLabel }}
                </button>
            </x-slot:actions>
        </x-page-header>

        <form method="POST" action="{{ route('purchases.store') }}" class="space-y-4">
            @csrf

            <x-card :title="$label . ' details'">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-3">
                    {{--
                        searchable: a wholesaler's list runs to hundreds, and the
                        flag is all it takes - x-select hands over to
                        x-searchable-select, which keeps the real <select>, its
                        name and its required attribute.
                    --}}
                    <div class="md:col-span-3 [&>div]:mb-0">
                        <x-select name="supplier_id" :label="'Select ' . $supplierLabel" required searchable
                                  :options="$suppliers" option-label="name" option-value="id"
                                  :placeholder="'-- Select ' . $supplierLabel . ' --'" />
                    </div>

                    <div>
                        <label for="invoice_number" class="{{ $labelClass }}">
                            Invoice Number <span class="text-red-600" aria-hidden="true">*</span>
                        </label>
                        <input id="invoice_number" type="text" name="invoice_number" required
                               value="{{ old('invoice_number', 'INV-' . mt_rand(100000, 999999)) }}"
                               class="{{ $input }}">
                    </div>

                    <div>
                        <label for="purchase_date" class="{{ $labelClass }}">
                            {{ $label }} Date <span class="text-red-600" aria-hidden="true">*</span>
                        </label>
                        <input id="purchase_date" type="date" name="purchase_date" required
                               value="{{ old('purchase_date', today()->toDateString()) }}"
                               class="{{ $input }}">
                    </div>

                    <div>
                        <label for="note" class="{{ $labelClass }}">Note (optional)</label>
                        <input id="note" type="text" name="note" value="{{ old('note') }}" class="{{ $input }}">
                    </div>

                    {{--
                        Status decides whether stock moves. "Received" is the normal case:
                        someone is typing in a delivery that is sitting on the counter.
                        "Ordered" raises the paperwork and leaves stock alone until the
                        goods turn up.
                    --}}
                    <div>
                        <label for="status" class="{{ $labelClass }}">Status</label>
                        <select id="status" name="status" class="{{ $input }}">
                            @foreach (['received' => 'Received (goods are in)', 'ordered' => 'Ordered (not delivered yet)'] as $value => $text)
                                <option value="{{ $value }}" @selected(old('status', 'received') === $value)>{{ $text }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="expected_at" class="{{ $labelClass }}">Expected (optional)</label>
                        <input id="expected_at" type="date" name="expected_at" value="{{ old('expected_at') }}"
                               class="{{ $input }}">
                    </div>

                    <div>
                        <label for="reference" class="{{ $labelClass }}">{{ $supplierLabel }}'s reference (optional)</label>
                        <input id="reference" type="text" name="reference" value="{{ old('reference') }}"
                               class="{{ $input }}">
                    </div>

                    <div>
                        <label for="amount_paid" class="{{ $labelClass }}">Paid now ({{ currency() }})</label>
                        <input id="amount_paid" type="number" step="0.01" min="0" name="amount_paid"
                               value="{{ old('amount_paid', 0) }}"
                               class="{{ $input }} text-right tabular-nums">
                        <p class="{{ $hintClass }}">Anything paid later belongs on the {{ Str::lower($supplierLabel) }}'s account.</p>
                    </div>
                </div>
            </x-card>

            {{-- Lines --}}
            <x-card title="Items" :padding="false">
                <x-slot:actions>
                    <button type="button" @click="addRow()"
                            class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                        <x-icon name="plus" size="4" />
                        Add Item
                    </button>
                </x-slot:actions>

                <x-table>
                    <x-slot:head>
                        <tr>
                            <th>{{ $productLabel }}</th>
                            <th class="w-28 text-right">Quantity</th>
                            <th class="w-36 text-right">Cost Price</th>
                            <th class="w-32 text-right">Total</th>
                            <th class="w-20"><span class="sr-only">Remove</span></th>
                        </tr>
                    </x-slot:head>

                    <template x-for="(row, index) in rows" :key="index">
                        <tr>
                            <td>
                                {{-- Options are rendered server-side: an x-for inside a
                                     select has not produced them yet when x-model
                                     tries to select the row's current value. --}}
                                <select name="product_ids[]" x-model="row.product_id"
                                        @change="onProductChange(row)" required
                                        :aria-label="'Item ' + (index + 1) + ' ' + @js(Str::lower($productLabel))"
                                        class="{{ $input }}">
                                    <option value="">-- Select {{ Str::lower($productLabel) }} --</option>
                                    @foreach ($products as $option)
                                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <input type="number" name="quantities[]" min="1" step="1" required
                                       x-model="row.quantity"
                                       :aria-label="'Item ' + (index + 1) + ' quantity'"
                                       class="{{ $input }} text-right tabular-nums">
                            </td>
                            <td>
                                <input type="number" name="cost_prices[]" min="0" step="0.01" required
                                       x-model="row.cost_price"
                                       :aria-label="'Item ' + (index + 1) + ' cost price'"
                                       class="{{ $input }} text-right tabular-nums">
                            </td>
                            <td class="text-right tabular-nums" x-text="money(lineTotal(row))"></td>
                            <td class="text-right">
                                <button type="button" @click="removeRow(index)"
                                        :aria-label="'Remove item ' + (index + 1)"
                                        class="inline-flex min-h-touch items-center justify-center rounded-md px-3 py-1.5 text-sm font-medium text-red-600 ring-1 ring-red-200 hover:bg-red-50 dark:text-red-400 dark:ring-red-900 dark:hover:bg-red-950">
                                    Remove
                                </button>
                            </td>
                        </tr>
                    </template>

                    <tr x-show="rows.length === 0" x-cloak>
                        <td colspan="5">
                            <x-empty icon="box" title="No items yet"
                                     :message="'Add at least one ' . Str::lower($productLabel) . ' to this ' . Str::lower($label) . '.'" />
                        </td>
                    </tr>

                    <x-slot:foot>
                        <tr>
                            <td colspan="3" class="text-right">Total</td>
                            <td class="text-right tabular-nums" x-text="money(total)"></td>
                            <td></td>
                        </tr>
                    </x-slot:foot>
                </x-table>
            </x-card>

            <div class="flex flex-wrap justify-end gap-2">
                <a href="{{ route('purchases.index') }}"
                   class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                    Cancel
                </a>
                <button type="submit"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    Save {{ $label }}
                </button>
            </div>
        </form>

        {{--
            Add Supplier.

            Alpine state rather than the old getElementById wiring, and a real
            dialog role. It still posts at suppliers.store on its own.
        --}}
        <div x-show="supplierModal" x-cloak
             class="fixed inset-0 z-modal flex items-start justify-center overflow-y-auto bg-gray-900/60 p-4"
             role="dialog" aria-modal="true" aria-labelledby="add-supplier-title"
             @keydown.escape.window="supplierModal = false">
            <div class="my-8 w-full max-w-md rounded-xl bg-white shadow-xl ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"
                 @click.outside="supplierModal = false">
                <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                    <h3 id="add-supplier-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">
                        Add New {{ $supplierLabel }}
                    </h3>
                    <button type="button" @click="supplierModal = false" aria-label="Close"
                            class="-m-1.5 flex h-touch w-touch shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700">
                        <x-icon name="x" size="5" />
                    </button>
                </div>

                <form method="POST" action="{{ route('suppliers.store') }}">
                    @csrf
                    <div class="max-h-[70vh] overflow-y-auto p-4 sm:p-5">
                        <div class="space-y-4">
                            <div>
                                <label for="new-supplier-name" class="{{ $labelClass }}">
                                    Name <span class="text-red-600" aria-hidden="true">*</span>
                                </label>
                                <input id="new-supplier-name" type="text" name="name" required class="{{ $input }}">
                            </div>
                            <div>
                                <label for="new-supplier-phone" class="{{ $labelClass }}">
                                    Phone <span class="text-red-600" aria-hidden="true">*</span>
                                </label>
                                <input id="new-supplier-phone" type="text" name="phone" required class="{{ $input }}">
                            </div>
                            <div>
                                <label for="new-supplier-email" class="{{ $labelClass }}">Email</label>
                                <input id="new-supplier-email" type="email" name="email" class="{{ $input }}">
                            </div>
                            <div>
                                <label for="new-supplier-address" class="{{ $labelClass }}">Address</label>
                                <input id="new-supplier-address" type="text" name="address" class="{{ $input }}">
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                        <button type="button" @click="supplierModal = false"
                                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit"
                                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                            Save {{ $supplierLabel }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
