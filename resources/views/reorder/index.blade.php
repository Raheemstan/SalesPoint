@extends('layouts.app')

@section('title', 'Reorder List')

@php
    /*
    |--------------------------------------------------------------------------
    | The reorder list
    |--------------------------------------------------------------------------
    |
    | Presentation only. The grouping, the suggested quantities and the posted
    | field names (product_ids[], quantities[<id>], cost_prices[<id>]) are
    | exactly what PurchaseController@storeFromReorder already receives.
    */
    $productTerm = term('product');
    $productsTerm = term('product', true);

    // "0" is the unassigned group, which is not a supplier id.
    $supplierOptions = $suppliers->pluck('name', 'id')->all() + [0 => 'No supplier set'];
@endphp

@section('content')
    <x-page-header title="Reorder list"
                   subtitle="Everything at or below its reorder level, grouped by who you buy it from. Tick what you want and send a group straight to a purchase order.">
        <x-slot:actions>
            <a href="{{ route('stock-takes.index') }}"
               class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                <x-icon name="clipboard-check" size="4" />
                Stock takes
            </a>

            <a href="{{ route('adjustments.index') }}"
               class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                <x-icon name="sliders" size="4" />
                Adjustments
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
        <x-stat label="{{ $productsTerm }} to reorder" :value="number_format($summary['items'])" icon="box"
                hint="at or below their reorder level" />
        <x-stat label="Already out of stock" :value="number_format($summary['out_of_stock'])" icon="alert-triangle"
                :hint="$summary['out_of_stock'] > 0 ? 'nothing left to sell' : 'none yet'" />
        <x-stat label="Suggested order cost" :value="money($summary['total'])" icon="coins"
                hint="every line below, at cost" />
    </div>

    {{-- One filter row, above the groups it scopes. --}}
    <x-card class="mt-4">
        <form method="GET" class="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Searchable: a wholesaler's shop buys from more suppliers than fit in a glance. --}}
            <x-select name="supplier_id" label="{{ term('supplier') }}" :options="$supplierOptions"
                      placeholder="-- All --" :selected="request('supplier_id')" searchable />

            <x-input type="text" name="search" label="Search" :value="request('search')"
                     placeholder="name or SKU" class="min-h-touch" />

            <div class="mb-4 flex min-h-touch flex-wrap items-center gap-2 self-end">
                <button type="submit"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    <x-icon name="search" size="4" />
                    Filter
                </button>

                <a href="{{ route('reorder.index') }}"
                   class="inline-flex min-h-touch items-center rounded-md px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                    Reset
                </a>
            </div>
        </form>
    </x-card>

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
        <form method="POST" action="{{ route('reorder.purchase-order') }}">
            @csrf
            <input type="hidden" name="supplier_id" value="{{ $group['supplier_id'] ?: '' }}">

            <x-card :title="$group['label']"
                    :subtitle="$group['lines']->count() . ' ' . Str::plural('item', $group['lines']->count()) . ' · ' . money($group['total'])"
                    :padding="false" class="mt-4">
                <x-slot:actions>
                    @if ($group['supplier_id'] === 0)
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Set a {{ strtolower(term('supplier')) }} on these {{ strtolower($productsTerm) }} to order them together.
                        </span>
                    @else
                        <button type="submit"
                                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                            <x-icon name="truck" size="4" />
                            Create purchase order
                        </button>
                    @endif
                </x-slot:actions>

                <x-table>
                    <x-slot:head>
                        <tr>
                            <th class="w-8"><span class="sr-only">On the order</span></th>
                            <th>{{ $productTerm }}</th>
                            <th>SKU</th>
                            <th class="text-right">In stock</th>
                            <th class="text-right">Reorder at</th>
                            <th class="text-right">Order</th>
                            <th class="text-right">Unit cost</th>
                            <th class="text-right">Line cost</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($group['lines'] as $line)
                        @php $product = $line['product']; @endphp
                        <tr>
                            <td>
                                {{-- Unticked rows send nothing, so the buyer chooses what goes on the order. --}}
                                <input type="checkbox" name="product_ids[]" value="{{ $product->id }}" checked
                                       aria-label="Put {{ $product->name }} on this order"
                                       class="h-5 w-5 rounded border-gray-300 text-primary-600 dark:border-gray-600 dark:bg-gray-900">
                            </td>
                            <td>
                                <span class="font-medium">{{ $product->name }}</span>
                                @if ($product->unit)
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $product->unit }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap font-mono text-xs text-gray-500 dark:text-gray-400">
                                {{ $product->sku }}
                            </td>
                            <td class="text-right tabular-nums">
                                <x-badge :variant="(int) $product->stock_quantity <= 0 ? 'red' : 'amber'" size="sm">
                                    {{ $product->stock_quantity }}
                                </x-badge>
                            </td>
                            <td class="text-right tabular-nums text-gray-500 dark:text-gray-400">
                                {{ $product->reorderLevel() }}
                            </td>
                            <td class="text-right">
                                {{-- A long data-entry column on a tablet: the box has to be tappable. --}}
                                <input type="number" min="1" step="1"
                                       name="quantities[{{ $product->id }}]" value="{{ $line['suggested'] }}"
                                       aria-label="Order quantity for {{ $product->name }}"
                                       class="min-h-touch w-20 rounded-md border-gray-300 bg-white px-2 py-1.5 text-right text-sm tabular-nums shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            </td>
                            <td class="text-right">
                                {{--
                                    A bare number, and without the thousands
                                    separator money() adds: a number input
                                    rejects "1,250.00" and silently blanks it.
                                --}}
                                <input type="number" min="0" step="0.01"
                                       name="cost_prices[{{ $product->id }}]"
                                       value="{{ str_replace(',', '', money($line['unit_cost'], false)) }}"
                                       aria-label="Unit cost for {{ $product->name }}"
                                       class="min-h-touch w-24 rounded-md border-gray-300 bg-white px-2 py-1.5 text-right text-sm tabular-nums shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            </td>
                            <td class="text-right tabular-nums">{{ money($line['line_cost']) }}</td>
                        </tr>
                    @endforeach

                    <x-slot:foot>
                        <tr>
                            <td colspan="7">Group total</td>
                            <td class="text-right tabular-nums">{{ money($group['total']) }}</td>
                        </tr>
                    </x-slot:foot>
                </x-table>
            </x-card>
        </form>
    @empty
        <x-card class="mt-4">
            <x-empty icon="check-circle" title="Nothing needs reordering"
                     :message="'Every ' . strtolower($productTerm) . ' is above its reorder level. Items that do not track stock never appear here.'">
                <x-slot:action>
                    <a href="{{ route('stock-takes.index') }}"
                       class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                        <x-icon name="clipboard-check" size="4" />
                        Stock takes
                    </a>
                </x-slot:action>
            </x-empty>
        </x-card>
    @endforelse
@endsection
