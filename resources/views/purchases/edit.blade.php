@extends('layouts.app')

@section('title', 'Edit ' . term('purchase', false, 'Purchase') . ' ' . $purchase->invoice_number)

@section('content')
    @php
        $label = term('purchase', false, 'Purchase');
        $productLabel = term('product', false, 'Product');
        $card = 'bg-white dark:bg-gray-800 rounded shadow p-4';
        $input = 'w-full rounded border border-gray-300 dark:border-gray-600 px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100';
        $labelClass = 'block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1';

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
    @endphp

    <div class="space-y-4"
         x-data="{
            products: {{ Js::from($catalogue) }},
            rows: {{ Js::from($rows) }},
            status: '{{ old('status', $purchase->status) }}',
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
                return '{{ currency() }}' + (Number(value) || 0).toLocaleString(undefined, {
                    minimumFractionDigits: {{ (int) setting('currency_decimals', config('sectors.currency.decimals')) }},
                    maximumFractionDigits: {{ (int) setting('currency_decimals', config('sectors.currency.decimals')) }},
                });
            },
         }">

        <div class="{{ $card }} flex flex-wrap justify-between items-center gap-3">
            <div>
                <h2 class="text-lg font-semibold">Edit {{ $label }} {{ $purchase->invoice_number }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @if ($purchase->hasMovedStock())
                        This {{ Str::lower($label) }} is received, so its quantities are already on the shelf.
                        Saving moves only the difference: dropping a line from 10 to 7 takes 3 back out.
                    @else
                        Nothing has been added to stock yet. Set the status to received when the goods arrive.
                    @endif
                </p>
            </div>
            <a href="{{ route('purchases.show', $purchase) }}"
               class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm">&larr; Back</a>
        </div>

        <form method="POST" action="{{ route('purchases.update', $purchase) }}" class="space-y-4">
            @csrf
            @method('PUT')

            <div class="{{ $card }} grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="{{ $labelClass }}">{{ term('supplier', false, 'Supplier') }}</label>
                    <select name="supplier_id" required class="{{ $input }}">
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected(old('supplier_id', $purchase->supplier_id) == $supplier->id)>
                                {{ $supplier->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="{{ $labelClass }}">Invoice number</label>
                    <input type="text" name="invoice_number" required maxlength="255"
                           value="{{ old('invoice_number', $purchase->invoice_number) }}" class="{{ $input }}">
                </div>

                <div>
                    <label class="{{ $labelClass }}">Status</label>
                    <select name="status" x-model="status" class="{{ $input }}">
                        <option value="{{ \App\Models\Purchase::STATUS_ORDERED }}">Ordered (nothing delivered)</option>
                        <option value="{{ \App\Models\Purchase::STATUS_RECEIVED }}">Received (goods are in)</option>
                        <option value="{{ \App\Models\Purchase::STATUS_CANCELLED }}">Cancelled</option>
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1"
                       x-show="status === '{{ \App\Models\Purchase::STATUS_RECEIVED }}' && {{ $purchase->hasMovedStock() ? 'false' : 'true' }}"
                       style="display: none">
                        Saving this will add the quantities below to stock.
                    </p>
                    <p class="text-xs text-red-600 dark:text-red-400 mt-1"
                       x-show="status !== '{{ \App\Models\Purchase::STATUS_RECEIVED }}' && {{ $purchase->hasMovedStock() ? 'true' : 'false' }}"
                       style="display: none">
                        Saving this will take the quantities back out of stock.
                    </p>
                </div>

                <div>
                    <label class="{{ $labelClass }}">{{ $label }} date</label>
                    <input type="date" name="purchase_date" required
                           value="{{ old('purchase_date', \Carbon\Carbon::parse($purchase->purchase_date)->toDateString()) }}"
                           class="{{ $input }}">
                </div>

                <div>
                    <label class="{{ $labelClass }}">Expected</label>
                    <input type="date" name="expected_at"
                           value="{{ old('expected_at', $purchase->expected_at?->toDateString()) }}" class="{{ $input }}">
                </div>

                <div>
                    <label class="{{ $labelClass }}">Supplier's reference</label>
                    <input type="text" name="reference" maxlength="255"
                           value="{{ old('reference', $purchase->reference) }}" class="{{ $input }}">
                </div>

                <div>
                    <label class="{{ $labelClass }}">Paid on delivery ({{ currency() }})</label>
                    <input type="number" step="0.01" min="0" name="amount_paid"
                           value="{{ old('amount_paid', round((float) $purchase->amount_paid, 2)) }}" class="{{ $input }}">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Anything paid later belongs on the supplier's account, not here.
                    </p>
                </div>

                <div class="md:col-span-2">
                    <label class="{{ $labelClass }}">Note</label>
                    <input type="text" name="note" value="{{ old('note', $purchase->note) }}" class="{{ $input }}">
                </div>
            </div>

            {{-- Lines --}}
            <div class="{{ $card }}">
                <h3 class="font-semibold mb-3">Items</h3>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-100 dark:bg-gray-700 text-left">
                            <tr>
                                <th class="p-2">{{ $productLabel }}</th>
                                <th class="p-2 w-28">Quantity</th>
                                <th class="p-2 w-36">Unit cost</th>
                                <th class="p-2 w-32 text-right">Total</th>
                                <th class="p-2 w-20"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, index) in rows" :key="index">
                                <tr class="border-b border-gray-200 dark:border-gray-700">
                                    <td class="p-2">
                                        {{-- Options are rendered server-side: an x-for inside a
                                             select has not produced them yet when x-model
                                             tries to select the row's current value. --}}
                                        <select name="product_ids[]" x-model="row.product_id"
                                                @change="onProductChange(row)" required class="{{ $input }}">
                                            <option value="">-- Select {{ Str::lower($productLabel) }} --</option>
                                            @foreach ($products as $option)
                                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="p-2">
                                        <input type="number" name="quantities[]" min="1" step="1" required
                                               x-model="row.quantity" class="{{ $input }}">
                                    </td>
                                    <td class="p-2">
                                        <input type="number" name="cost_prices[]" min="0" step="0.01" required
                                               x-model="row.cost_price" class="{{ $input }}">
                                    </td>
                                    <td class="p-2 text-right" x-text="money(lineTotal(row))"></td>
                                    <td class="p-2 text-right">
                                        <button type="button" @click="removeRow(index)"
                                                class="text-red-600 dark:text-red-400 hover:underline">Remove</button>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="rows.length === 0" style="display: none">
                                <td colspan="5" class="p-4 text-center text-gray-500 dark:text-gray-400">
                                    No items. Add at least one.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap justify-between items-center gap-3 mt-3">
                    <button type="button" @click="addRow()"
                            class="bg-blue-600 hover:bg-blue-700 text-white text-xs px-3 py-2 rounded">+ Add item</button>
                    <p class="font-semibold">Total: <span x-text="money(total)"></span></p>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('purchases.show', $purchase) }}"
                   class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600">Cancel</a>
                <button type="submit" class="px-4 py-2 rounded bg-green-600 hover:bg-green-700 text-white">Save changes</button>
            </div>
        </form>
    </div>
@endsection
