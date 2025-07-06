@extends('layouts.app')

@section('title', 'New Purchase Order')

@section('content')
    <div class="bg-white dark:bg-gray-800 shadow rounded p-6 space-y-6 text-sm text-gray-800 dark:text-gray-100">

        {{-- Add Supplier Button --}}
        <div class="flex justify-between items-center">
            <button id="add-supplier-btn" class="btn btn-sm bg-primary text-white rounded px-4 py-1">
                + Add Supplier
            </button>
        </div>


        {{-- Purchase Form --}}
        <form method="POST" action="{{ route('purchases.store') }}">
            @csrf

            {{-- Supplier Selection --}}
            <div class="mb-4">
                <x-select name="supplier_id" label="Select Supplier" required :options="$suppliers" option-label="name"
                    option-value="id" placeholder="-- Select Supplier --" />
            </div>

            {{-- Purchase Info --}}
            <div class="grid md:grid-cols-3 gap-4">
                <x-input name="invoice_number" label="Invoice Number" required :value="'INV-' . mt_rand(100000, 999999)" />
                <x-input name="purchase_date" label="Purchase Date" type="date" required />
                <x-input name="note" label="Note (optional)" />
            </div>

            {{-- Purchase Items --}}
            <div class="mt-6">
                <h3 class="font-medium mb-2">Items</h3>

                <table class="w-full table-auto border">
                    <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                        <tr>
                            <th class="p-2">Product</th>
                            <th class="p-2">Quantity</th>
                            <th class="p-2">Cost Price</th>
                            <th class="p-2">Total</th>
                            <th class="p-2">Action</th>
                        </tr>
                    </thead>
                    <tbody id="items-body">
                        {{-- Rows will be added via JS --}}
                    </tbody>
                </table>

                <button type="button" id="add-item-btn"
                    class="mt-3 bg-blue-600 hover:bg-blue-700 text-white text-xs px-3 py-1 rounded">
                    + Add Item
                </button>
            </div>

            {{-- Total --}}
            <div class="mt-4 text-right font-semibold">
                <span>Total: ₦<span id="grand-total">0.00</span></span>
            </div>

            {{-- Submit --}}
            <div class="mt-6 text-right">
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded">
                    Save Purchase
                </button>
            </div>
        </form>
    </div>

    {{-- Supplier Modal --}}
    <div id="supplier-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex justify-center items-center">
        <div class="bg-white dark:bg-gray-900 p-6 rounded shadow-lg w-full max-w-md">
            <h3 class="text-lg font-bold mb-4">Add New Supplier</h3>
            <form id="supplier-form" method="POST" action="{{ route('suppliers.store') }}">
                @csrf
                <x-input name="name" label="Name" required />
                <x-input name="phone" label="Phone" required />
                <x-input name="email" label="Email" type="email" />
                <x-input name="address" label="Address" />

                <div class="mt-4 flex justify-end space-x-2">
                    <button type="button" id="cancel-supplier-btn"
                        class="px-4 py-2 bg-gray-300 dark:bg-gray-700 text-gray-900 dark:text-white rounded">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded">
                        Save Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        const modal = document.getElementById('supplier-modal');
        const addSupplierBtn = document.getElementById('add-supplier-btn');
        const cancelSupplierBtn = document.getElementById('cancel-supplier-btn');
        const itemsBody = document.getElementById('items-body');
        const addItemBtn = document.getElementById('add-item-btn');
        const totalSpan = document.getElementById('grand-total');

        // Modal show/hide
        addSupplierBtn.addEventListener('click', () => modal.classList.remove('hidden'));
        cancelSupplierBtn.addEventListener('click', () => modal.classList.add('hidden'));

        // Add item row
        addItemBtn.addEventListener('click', () => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td class="">
                    <select name="product_ids[]" class="'w-full px-3 py-2 border rounded-md shadow-sm focus:ring focus:ring-blue-200 dark:bg-gray-800 dark:text-white dark:border-gray-600 product-select" required>
                        <option value="">-- Select Product --</option>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}" data-cost="{{ $product->cost_price }}">
                                {{ $product->name }}
                            </option>
                        @endforeach
                    </select>
                </td>
                <td class="p-2">
                    <x-input type="number" name="quantities[]" class="w-full border rounded px-2 py-1 quantity" value="1" min="1" required label="Quantity" />
                </td>
                <td class="p-2">
                    <x-input type="number" name="cost_prices[]" class="w-full border rounded px-2 py-1 cost-price" value="0" min="0" step="0.01" required label="Cost Price" />
                </td>
                <td class="p-2 total">0.00</td>
                <td class="p-2 text-center">
                    <button type="button" class="text-red-600 remove-row">Remove</button>
                </td>
            `;
            itemsBody.appendChild(row);
            updateTotals();
        });

        // Remove item row
        itemsBody.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-row')) {
                e.target.closest('tr').remove();
                updateTotals();
            }
        });

        // Auto fill cost price when product selected
        itemsBody.addEventListener('change', (e) => {
            if (e.target.matches('.product-select')) {
                const selected = e.target.options[e.target.selectedIndex];
                const cost = selected.getAttribute('data-cost') || 0;
                const row = e.target.closest('tr');
                row.querySelector('.cost-price').value = cost;
                updateTotals();
            }
        });

        // Recalculate totals
        itemsBody.addEventListener('input', updateTotals);

        function updateTotals() {
            let total = 0;
            itemsBody.querySelectorAll('tr').forEach(row => {
                const qty = parseFloat(row.querySelector('.quantity')?.value || 0);
                const price = parseFloat(row.querySelector('.cost-price')?.value || 0);
                const rowTotal = qty * price;
                total += rowTotal;
                row.querySelector('.total').textContent = rowTotal.toFixed(2);
            });
            totalSpan.textContent = total.toFixed(2);
        }
    </script>
@endsection