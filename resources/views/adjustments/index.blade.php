@extends('layouts.app')

@section('title', 'Stock Adjustments')

@php
    /*
    |--------------------------------------------------------------------------
    | Stock adjustments
    |--------------------------------------------------------------------------
    |
    | Presentation only: the same filters, the same posted field names and the
    | same hand-rolled modal (its Alpine state is `open` / `stock`, which page
    | scripts and the product select both read), restyled to the house shell.
    */

    // money() puts the symbol first, so a bare negative would read "<symbol>-100".
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));

    $reasons = collect(\App\Models\StockAdjustment::REASONS)
        ->mapWithKeys(fn($reason) => [$reason => ucfirst($reason)])
        ->all();

    // 'count' belongs to stock-take approval, so it is not offered by hand.
    $manualReasons = collect($reasons)->except('count')->all();

    $directions = [
        'decrease' => 'Decrease (stock lost)',
        'increase' => 'Increase (stock found)',
    ];
@endphp

@section('content')
    <div x-data="{ open: false, stock: null }">

        <x-page-header title="Stock adjustments"
                       subtitle="Stock that leaves without being sold - damaged, stolen, expired - is recorded here with a reason, so the difference is explained instead of just disappearing.">
            <x-slot:actions>
                <a href="{{ route('stock-takes.index') }}"
                   class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                    <x-icon name="clipboard-check" size="4" />
                    Stock takes
                </a>

                <button type="button" @click="open = true"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    <x-icon name="sliders" size="4" />
                    Adjust stock
                </button>
            </x-slot:actions>
        </x-page-header>

        {{-- Totals for the filtered range --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-stat label="Adjustments" :value="number_format($totals->entries ?? 0)" icon="sliders"
                    hint="in this range" />
            <x-stat label="Units added" :value="'+' . number_format($totals->units_in ?? 0)" icon="trend-up"
                    hint="stock found" />
            <x-stat label="Units removed" :value="'-' . number_format($totals->units_out ?? 0)" icon="trend-down"
                    hint="stock lost" />
            <x-stat label="Value written off" :value="$amount($totals->value_out ?? 0)" icon="coins"
                    hint="at cost" />
        </div>

        {{-- One filter row, above the history it scopes. --}}
        <x-card class="mt-4">
            <form method="GET" class="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-5">
                {{-- Searchable: a shop's catalogue is far too long to scroll. --}}
                <x-select name="product_id" label="{{ term('product') }}" :options="$products" option-label="name"
                          option-value="id" placeholder="-- All --" :selected="request('product_id')" searchable />

                <x-select name="reason" label="Reason" :options="$reasons" placeholder="-- All --"
                          :selected="request('reason')" class="min-h-touch" />

                <x-input type="date" name="from" label="From" :value="$from" class="min-h-touch" />
                <x-input type="date" name="to" label="To" :value="$to" class="min-h-touch" />

                <div class="mb-4 flex min-h-touch flex-wrap items-center gap-2 self-end">
                    <button type="submit"
                            class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                        <x-icon name="search" size="4" />
                        Filter
                    </button>

                    <a href="{{ route('adjustments.index') }}"
                       class="inline-flex min-h-touch items-center rounded-md px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        Reset
                    </a>
                </div>
            </form>
        </x-card>

        {{-- History --}}
        <x-card :padding="false" class="mt-4">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>When</th>
                        <th>{{ term('product') }}</th>
                        <th class="text-right">Change</th>
                        <th>Reason</th>
                        <th>Note</th>
                        <th class="text-right">Value</th>
                        <th>By</th>
                    </tr>
                </x-slot:head>

                @forelse ($adjustments as $adjustment)
                    <tr>
                        <td class="whitespace-nowrap text-gray-500 dark:text-gray-400">
                            {{ $adjustment->created_at->format('d M Y H:i') }}
                        </td>
                        <td class="font-medium">{{ $adjustment->product->name ?? 'Deleted item' }}</td>
                        <td class="text-right tabular-nums">
                            <x-badge :variant="$adjustment->signed_quantity < 0 ? 'red' : 'green'" size="sm">
                                {{ $adjustment->signed_quantity > 0 ? '+' : '' }}{{ $adjustment->signed_quantity }}
                            </x-badge>
                        </td>
                        <td class="whitespace-nowrap">
                            {{ ucfirst($adjustment->reason) }}
                            @if ($adjustment->stock_take_id)
                                <a href="{{ route('stock-takes.show', $adjustment->stock_take_id) }}"
                                   class="block font-mono text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                                    {{ $adjustment->stockTake->reference ?? 'sheet' }}
                                </a>
                            @endif
                        </td>
                        <td class="text-gray-500 dark:text-gray-400">{{ $adjustment->note }}</td>
                        <td class="text-right tabular-nums">{{ $amount($adjustment->value) }}</td>
                        <td class="text-gray-500 dark:text-gray-400">{{ $adjustment->user->name ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-empty icon="sliders" title="No adjustments in this range"
                                     message="Widen the dates, or record one when stock goes missing or turns up.">
                                <x-slot:action>
                                    <button type="button" @click="open = true"
                                            class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                                        <x-icon name="sliders" size="4" />
                                        Adjust stock
                                    </button>
                                </x-slot:action>
                            </x-empty>
                        </td>
                    </tr>
                @endforelse
            </x-table>

            @if ($adjustments->hasPages())
                <x-slot:footer>{{ $adjustments->links() }}</x-slot:footer>
            @endif
        </x-card>

        {{--
            Adjust modal.

            Deliberately still hand-rolled: x-modal is driven by
            $dispatch('open-modal', ...) events, and swapping to it would change
            how this page opens, not just how it looks. The Alpine state names
            (`open`, `stock`) are untouched.
        --}}
        <div x-show="open" x-cloak x-transition.opacity.duration.150ms
             class="fixed inset-0 z-modal flex items-start justify-center overflow-y-auto bg-gray-900/50 p-4 sm:items-center"
             @keydown.escape.window="open = false"
             role="dialog" aria-modal="true" aria-labelledby="adjust-stock-title">
            <div @click.away="open = false"
                 class="w-full max-w-lg rounded-xl bg-white shadow-xl ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">

                <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                    <div class="min-w-0">
                        <h2 id="adjust-stock-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            Adjust stock
                        </h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                            The stock figure is corrected from the database, not from this page, and the movement is
                            logged against your name.
                        </p>
                    </div>

                    <button type="button" @click="open = false" aria-label="Close"
                            class="-m-1.5 flex h-touch w-touch shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700">
                        <x-icon name="x" size="4" />
                    </button>
                </div>

                <form method="POST" action="{{ route('adjustments.store') }}">
                    @csrf

                    <div class="p-4 sm:p-5">
                        <x-select name="product_id" id="adjust-product" label="{{ term('product') }}" :options="$products"
                                  option-label="name" option-value="id" placeholder="-- Choose --"
                                  :option-data="['stock_quantity']" searchable
                                  x-on:change="stock = $event.target.selectedOptions[0].dataset.stockQuantity ?? null" />

                        <p class="-mt-2 mb-4 text-xs text-gray-500 dark:text-gray-400" x-show="stock !== null">
                            In stock now: <span class="font-medium" x-text="stock"></span>
                        </p>

                        <div class="grid grid-cols-2 gap-3">
                            <x-select name="type" id="type" label="Direction" :options="$directions"
                                      required class="min-h-touch" />

                            <x-input type="number" name="quantity" label="Quantity" min="1" step="1" required
                                     class="min-h-touch text-right tabular-nums" />
                        </div>

                        <x-select name="reason" id="adjust-reason" label="Reason" :options="$manualReasons"
                                  required class="min-h-touch" />

                        <x-input type="text" name="note" label="Note (optional)" maxlength="255"
                                 placeholder="e.g. dropped a crate in the back store" class="min-h-touch" />
                    </div>

                    <div class="flex flex-wrap justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                        <button type="button" @click="open = false"
                                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                            Cancel
                        </button>

                        <button type="submit"
                                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                            <x-icon name="check-circle" size="4" />
                            Record adjustment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
