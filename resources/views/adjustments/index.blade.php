@extends('layouts.app')

@section('title', 'Stock Adjustments')

@section('content')
    <div class="space-y-4" x-data="{ open: false, stock: null }">

        <div class="bg-white dark:bg-gray-800 rounded shadow p-4 flex flex-wrap gap-4 justify-between items-center">
            <div class="text-sm text-gray-600 dark:text-gray-300">
                Stock that leaves without being sold - damaged, stolen, expired - is recorded here with a reason,
                so the difference is explained instead of just disappearing.
            </div>
            <div class="flex gap-2">
                <a href="{{ route('stock-takes.index') }}"
                   class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                    Stock takes
                </a>
                <button type="button" @click="open = true"
                        class="px-4 py-2 rounded bg-green-600 hover:bg-green-700 text-white text-sm">
                    + Adjust stock
                </button>
            </div>
        </div>

        {{-- Totals for the filtered range --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Adjustments</div>
                <div class="text-xl font-semibold">{{ number_format($totals->entries ?? 0) }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Units added</div>
                <div class="text-xl font-semibold text-green-600 dark:text-green-400">+{{ number_format($totals->units_in ?? 0) }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Units removed</div>
                <div class="text-xl font-semibold text-red-600 dark:text-red-400">-{{ number_format($totals->units_out ?? 0) }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Value written off</div>
                <div class="text-xl font-semibold text-red-600 dark:text-red-400">{{ money($totals->value_out ?? 0) }}</div>
            </div>
        </div>

        {{-- History --}}
        <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-3 mb-4">
                <x-select name="product_id" label="{{ term('product') }}" :options="$products" option-label="name"
                          option-value="id" placeholder="-- All --" :selected="request('product_id')" />

                <div class="mb-4">
                    <label for="reason" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Reason</label>
                    <select name="reason" id="reason"
                            class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                        <option value="">-- All --</option>
                        @foreach (\App\Models\StockAdjustment::REASONS as $reason)
                            <option value="{{ $reason }}" @selected(request('reason') === $reason)>{{ ucfirst($reason) }}</option>
                        @endforeach
                    </select>
                </div>

                <x-input type="date" name="from" label="From" :value="$from" />
                <x-input type="date" name="to" label="To" :value="$to" />

                <div class="flex items-start gap-2 pt-7">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">Filter</button>
                    <a href="{{ route('adjustments.index') }}" class="text-sm text-gray-500 dark:text-gray-400 self-center">Reset</a>
                </div>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full table-auto text-sm">
                    <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                        <tr>
                            <th class="p-2 text-left">When</th>
                            <th class="p-2 text-left">{{ term('product') }}</th>
                            <th class="p-2 text-right">Change</th>
                            <th class="p-2 text-left">Reason</th>
                            <th class="p-2 text-left">Note</th>
                            <th class="p-2 text-right">Value</th>
                            <th class="p-2 text-left">By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($adjustments as $adjustment)
                            <tr class="border-t dark:border-gray-700">
                                <td class="p-2 whitespace-nowrap">{{ $adjustment->created_at->format('d M Y H:i') }}</td>
                                <td class="p-2">{{ $adjustment->product->name ?? 'Deleted item' }}</td>
                                <td class="p-2 text-right font-medium {{ $adjustment->signed_quantity < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                                    {{ $adjustment->signed_quantity > 0 ? '+' : '' }}{{ $adjustment->signed_quantity }}
                                </td>
                                <td class="p-2">
                                    {{ ucfirst($adjustment->reason) }}
                                    @if ($adjustment->stock_take_id)
                                        <a href="{{ route('stock-takes.show', $adjustment->stock_take_id) }}"
                                           class="text-xs text-blue-600 dark:text-blue-400 hover:underline">
                                            {{ $adjustment->stockTake->reference ?? 'sheet' }}
                                        </a>
                                    @endif
                                </td>
                                <td class="p-2 text-gray-600 dark:text-gray-300">{{ $adjustment->note }}</td>
                                <td class="p-2 text-right">{{ money($adjustment->value) }}</td>
                                <td class="p-2">{{ $adjustment->user->name ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-6 text-gray-500 dark:text-gray-400">
                                    No adjustments in this range.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $adjustments->links() }}</div>
        </div>

        {{-- Adjust modal --}}
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
             @keydown.escape.window="open = false">
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4 w-full max-w-lg" @click.away="open = false">
                <h3 class="text-lg font-semibold mb-1">Adjust stock</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                    The stock figure is corrected from the database, not from this page, and the movement is
                    logged against your name.
                </p>

                <form method="POST" action="{{ route('adjustments.store') }}">
                    @csrf

                    <x-select name="product_id" id="adjust-product" label="{{ term('product') }}" :options="$products"
                              option-label="name" option-value="id" placeholder="-- Choose --"
                              :option-data="['stock_quantity']"
                              x-on:change="stock = $event.target.selectedOptions[0].dataset.stockQuantity ?? null" />

                    <p class="-mt-2 mb-4 text-xs text-gray-500 dark:text-gray-400" x-show="stock !== null">
                        In stock now: <span class="font-medium" x-text="stock"></span>
                    </p>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="mb-4">
                            <label for="type" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Direction</label>
                            <select name="type" id="type" required
                                    class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                                <option value="decrease">Decrease (stock lost)</option>
                                <option value="increase">Increase (stock found)</option>
                            </select>
                        </div>

                        <x-input type="number" name="quantity" label="Quantity" min="1" step="1" required />
                    </div>

                    <div class="mb-4">
                        <label for="adjust-reason" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Reason</label>
                        <select name="reason" id="adjust-reason" required
                                class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                            @foreach (\App\Models\StockAdjustment::REASONS as $reason)
                                {{-- 'count' belongs to stock-take approval, so it is not offered by hand. --}}
                                @continue($reason === 'count')
                                <option value="{{ $reason }}">{{ ucfirst($reason) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <x-input type="text" name="note" label="Note (optional)" maxlength="255"
                             placeholder="e.g. dropped a crate in the back store" />

                    <div class="flex justify-end gap-2">
                        <button type="button" @click="open = false"
                                class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm">Cancel</button>
                        <button type="submit" class="px-4 py-2 rounded bg-green-600 hover:bg-green-700 text-white text-sm">
                            Record adjustment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <style>[x-cloak] { display: none !important; }</style>
@endsection
