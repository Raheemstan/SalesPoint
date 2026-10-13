@extends('layouts.app')

@section('title', 'Reorder List')

@section('content')
    <div class="space-y-4">

        <div class="bg-white dark:bg-gray-800 rounded shadow p-4 flex flex-wrap gap-4 justify-between items-center">
            <div class="text-sm text-gray-600 dark:text-gray-300">
                Everything at or below its reorder level, grouped by who you buy it from. Items that do not track
                stock never appear here. Tick what you want and send a group straight to a purchase order.
            </div>
            <form method="GET" class="flex flex-wrap items-end gap-3">
                <div class="w-56">
                    <label for="supplier_id" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Supplier</label>
                    <select name="supplier_id" id="supplier_id"
                            class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                        <option value="">-- All --</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected((string) request('supplier_id') === (string) $supplier->id)>
                                {{ $supplier->name }}
                            </option>
                        @endforeach
                        <option value="0" @selected(request('supplier_id') === '0')>No supplier set</option>
                    </select>
                </div>
                <div class="w-48">
                    <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Search</label>
                    <input type="text" name="search" id="search" value="{{ request('search') }}"
                           class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                </div>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">Filter</button>
                <a href="{{ route('reorder.index') }}" class="text-sm text-gray-500 dark:text-gray-400 self-center">Reset</a>
            </form>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ term('product', true) }} to reorder</div>
                <div class="text-xl font-semibold">{{ number_format($summary['items']) }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Already out of stock</div>
                <div class="text-xl font-semibold text-red-600 dark:text-red-400">{{ number_format($summary['out_of_stock']) }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Suggested order cost</div>
                <div class="text-xl font-semibold">{{ money($summary['total']) }}</div>
            </div>
        </div>

        @forelse ($groups as $group)
            {{--
                One form per supplier group. The endpoint lives in PurchaseController
                (another part of the app), so this posts:

                  supplier_id                  the group's supplier (blank for "no supplier set")
                  product_ids[]                the ticked product ids
                  quantities[<product_id>]     how many to order
                  cost_prices[<product_id>]    unit cost to buy at

                The quantity and cost arrays are keyed by product id rather than by
                position: a buyer unticking a row must not silently shift every
                other row's quantity onto the wrong product.
            --}}
            <form method="POST" action="{{ route('reorder.purchase-order') }}"
                  class="bg-white dark:bg-gray-800 rounded shadow p-4">
                @csrf
                <input type="hidden" name="supplier_id" value="{{ $group['supplier_id'] ?: '' }}">

                <div class="flex flex-wrap justify-between items-center gap-3 mb-3">
                    <h3 class="font-semibold">
                        {{ $group['label'] }}
                        <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                            &middot; {{ $group['lines']->count() }} {{ Str::plural('item', $group['lines']->count()) }}
                            &middot; {{ money($group['total']) }}
                        </span>
                    </h3>

                    @if ($group['supplier_id'] === 0)
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Set a supplier on these {{ strtolower(term('product', true)) }} to order them together.
                        </span>
                    @else
                        <button type="submit"
                                class="px-4 py-2 rounded bg-green-600 hover:bg-green-700 text-white text-sm">
                            Create purchase order
                        </button>
                    @endif
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full table-auto text-sm">
                        <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                            <tr>
                                <th class="p-2 w-8"></th>
                                <th class="p-2 text-left">{{ term('product') }}</th>
                                <th class="p-2 text-left">SKU</th>
                                <th class="p-2 text-right">In stock</th>
                                <th class="p-2 text-right">Reorder at</th>
                                <th class="p-2 text-right">Order</th>
                                <th class="p-2 text-right">Unit cost</th>
                                <th class="p-2 text-right">Line cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group['lines'] as $line)
                                @php $product = $line['product']; @endphp
                                <tr class="border-t dark:border-gray-700">
                                    <td class="p-2">
                                        {{-- Unticked rows send nothing, so the buyer chooses what goes on the order. --}}
                                        <input type="checkbox" name="product_ids[]" value="{{ $product->id }}" checked
                                               class="rounded border-gray-300 dark:border-gray-600">
                                    </td>
                                    <td class="p-2">
                                        {{ $product->name }}
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $product->unit }}</span>
                                    </td>
                                    <td class="p-2 font-mono text-xs">{{ $product->sku }}</td>
                                    <td class="p-2 text-right {{ (int) $product->stock_quantity <= 0 ? 'text-red-600 dark:text-red-400 font-medium' : '' }}">
                                        {{ $product->stock_quantity }}
                                    </td>
                                    <td class="p-2 text-right">{{ $product->reorderLevel() }}</td>
                                    <td class="p-2 text-right">
                                        <input type="number" min="1" step="1"
                                               name="quantities[{{ $product->id }}]" value="{{ $line['suggested'] }}"
                                               class="w-20 px-2 py-1 text-right border rounded dark:bg-gray-900 dark:border-gray-600">
                                    </td>
                                    <td class="p-2 text-right">
                                        {{--
                                            A bare number, and without the thousands
                                            separator money() adds: a number input
                                            rejects "1,250.00" and silently blanks it.
                                        --}}
                                        <input type="number" min="0" step="0.01"
                                               name="cost_prices[{{ $product->id }}]"
                                               value="{{ str_replace(',', '', money($line['unit_cost'], false)) }}"
                                               class="w-24 px-2 py-1 text-right border rounded dark:bg-gray-900 dark:border-gray-600">
                                    </td>
                                    <td class="p-2 text-right">{{ money($line['line_cost']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 dark:border-gray-600 font-semibold">
                                <td class="p-2" colspan="7">Group total</td>
                                <td class="p-2 text-right">{{ money($group['total']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </form>
        @empty
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4 text-center text-gray-500 dark:text-gray-400 py-10">
                Nothing needs reordering: every {{ strtolower(term('product')) }} is above its reorder level.
            </div>
        @endforelse
    </div>
@endsection
