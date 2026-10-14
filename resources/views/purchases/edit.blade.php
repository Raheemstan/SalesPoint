@extends('layouts.app')

@section('title', 'Edit ' . term('purchase', false, 'Purchase') . ' ' . $purchase->invoice_number)

@section('content')
    @php
        $label = term('purchase', false, 'Purchase');
        $productLabel = term('product', false, 'Product');
        $supplierLabel = term('supplier', false, 'Supplier');

        $input = 'min-h-touch w-full rounded-md border-gray-300 bg-white shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white';
        $labelClass = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
        $hintClass = 'mt-1 text-xs text-gray-500 dark:text-gray-400';

        // The catalogue, small enough to hand to the picker in one go.
        $catalogue = $products->map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'cost' => (float) $p->cost_price])->values();

        // The lines to start from: whatever was typed if validation bounced, else what is saved.
        $rows = old('product_ids')
            ? collect(old('product_ids'))->map(fn($id, $i) => [
                'product_id' => (int) $id,
                'quantity' => (int) (old('quantities')[$i] ?? 1),
                'cost_price' => (float) (old('cost_prices')[$i] ?? 0),
            ])->values()
            : $purchase->purchaseItems->map(fn($item) => [
                'product_id' => $item->product_id,
                'quantity' => (int) $item->quantity,
                'cost_price' => (float) $item->cost_price,
            ])->values();

        $decimals = (int) setting('currency_decimals', config('sectors.currency.decimals'));
        $received = \App\Models\Purchase::STATUS_RECEIVED;
    @endphp

    <div x-data="{
            products: {{ Js::from($catalogue) }},
            rows: {{ Js::from($rows) }},
            status: @js(old('status', $purchase->status)),
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

        <x-page-header :title="'Edit ' . $label . ' ' . $purchase->invoice_number"
                       :subtitle="$purchase->hasMovedStock()
                            ? 'This ' . Str::lower($label) . ' is received, so its quantities are already on the shelf. Saving moves only the difference: dropping a line from 10 to 7 takes 3 back out.'
                            : 'Nothing has been added to stock yet. Set the status to received when the goods arrive.'"
                       :back="route('purchases.show', $purchase)"
                       :back-label="$purchase->invoice_number" />

        <form method="POST" action="{{ route('purchases.update', $purchase) }}" class="space-y-4">
            @csrf
            @method('PUT')

            <x-card :title="$label . ' details'">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-3">
                    {{--
                        Every supplier is offered here, not just the active ones:
                        a purchase that already uses a deactivated supplier has to
                        stay editable.
                    --}}
                    <div class="[&>div]:mb-0">
                        <x-select name="supplier_id" :label="$supplierLabel" required searchable
                                  :options="$suppliers" option-label="name" option-value="id"
                                  :selected="old('supplier_id', $purchase->supplier_id)" />
                    </div>

                    <div>
                        <label for="invoice_number" class="{{ $labelClass }}">
                            Invoice number <span class="text-red-600" aria-hidden="true">*</span>
                        </label>
                        <input id="invoice_number" type="text" name="invoice_number" required maxlength="255"
                               value="{{ old('invoice_number', $purchase->invoice_number) }}" class="{{ $input }}">
                    </div>

                    <div>
                        <label for="status" class="{{ $labelClass }}">Status</label>
                        <select id="status" name="status" x-model="status" class="{{ $input }}">
                            <option value="{{ \App\Models\Purchase::STATUS_ORDERED }}">Ordered (nothing delivered)</option>
                            <option value="{{ $received }}">Received (goods are in)</option>
                            <option value="{{ \App\Models\Purchase::STATUS_CANCELLED }}">Cancelled</option>
                        </select>

                        <p class="{{ $hintClass }}" x-cloak
                           x-show="status === @js($received) && {{ $purchase->hasMovedStock() ? 'false' : 'true' }}">
                            Saving this will add the quantities below to stock.
                        </p>
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400" x-cloak
                           x-show="status !== @js($received) && {{ $purchase->hasMovedStock() ? 'true' : 'false' }}">
                            Saving this will take the quantities back out of stock.
                        </p>
                    </div>

                    <div>
                        <label for="purchase_date" class="{{ $labelClass }}">
                            {{ $label }} date <span class="text-red-600" aria-hidden="true">*</span>
                        </label>
                        <input id="purchase_date" type="date" name="purchase_date" required
                               value="{{ old('purchase_date', \Carbon\Carbon::parse($purchase->purchase_date)->toDateString()) }}"
                               class="{{ $input }}">
                    </div>

                    <div>
                        <label for="expected_at" class="{{ $labelClass }}">Expected</label>
                        <input id="expected_at" type="date" name="expected_at"
                               value="{{ old('expected_at', $purchase->expected_at?->toDateString()) }}" class="{{ $input }}">
                    </div>

                    <div>
                        <label for="reference" class="{{ $labelClass }}">{{ $supplierLabel }}'s reference</label>
                        <input id="reference" type="text" name="reference" maxlength="255"
                               value="{{ old('reference', $purchase->reference) }}" class="{{ $input }}">
                    </div>

                    <div>
                        <label for="amount_paid" class="{{ $labelClass }}">Paid on delivery ({{ currency() }})</label>
                        <input id="amount_paid" type="number" step="0.01" min="0" name="amount_paid"
                               value="{{ old('amount_paid', round((float) $purchase->amount_paid, 2)) }}"
                               class="{{ $input }} text-right tabular-nums">
                        <p class="{{ $hintClass }}">
                            Anything paid later belongs on the {{ Str::lower($supplierLabel) }}'s account, not here.
                        </p>
                    </div>

                    <div class="md:col-span-2">
                        <label for="note" class="{{ $labelClass }}">Note</label>
                        <input id="note" type="text" name="note" value="{{ old('note', $purchase->note) }}"
                               class="{{ $input }}">
                    </div>
                </div>
            </x-card>

            {{-- Lines --}}
            <x-card title="Items" :padding="false">
                <x-slot:actions>
                    <button type="button" @click="addRow()"
                            class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                        <x-icon name="plus" size="4" />
                        Add item
                    </button>
                </x-slot:actions>

                <x-table>
                    <x-slot:head>
                        <tr>
                            <th>{{ $productLabel }}</th>
                            <th class="w-28 text-right">Quantity</th>
                            <th class="w-36 text-right">Unit cost</th>
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
                                       :aria-label="'Item ' + (index + 1) + ' unit cost'"
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
                            <x-empty icon="box" title="No items" message="Add at least one." />
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
                <a href="{{ route('purchases.show', $purchase) }}"
                   class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                    Cancel
                </a>
                <button type="submit"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    Save changes
                </button>
            </div>
        </form>
    </div>
@endsection
