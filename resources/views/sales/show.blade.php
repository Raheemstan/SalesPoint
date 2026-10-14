@extends('layouts.app')
@section('title', 'Sale ' . $sale->invoice_number)

@php
    $canManage = auth()->user()->can('refund', $sale);

    $statusBadge = [
        \App\Models\Sale::STATUS_COMPLETED => ['green', 'Completed'],
        \App\Models\Sale::STATUS_PARTIAL => ['amber', 'Partially refunded'],
        \App\Models\Sale::STATUS_REFUNDED => ['orange', 'Refunded'],
        \App\Models\Sale::STATUS_VOIDED => ['red', 'Voided'],
    ];

    [$statusVariant, $statusLabel] = $statusBadge[$sale->status]
        ?? ['gray', ucfirst(str_replace('_', ' ', $sale->status))];

    $productTerm = term('product', false, 'Product');
    $refundable = $canManage && $sale->isRefundable();

    $fieldClass = 'min-h-touch rounded-md border-gray-300 bg-white text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white';
    $fieldLabel = 'mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400';
@endphp

@section('content')
    <x-page-header :title="$sale->invoice_number"
                   :subtitle="$sale->created_at->format('d M Y H:i') . ' · ' . ($sale->user->name ?? '-') . ' · ' . ucfirst($sale->payment_method)"
                   :back="route('sales.index')"
                   :back-label="term('sale', true, 'Sales')">
        <x-slot:actions>
            {{--
                Serial / IMEI capture (electronics). Route::has keeps this out
                of the way of an install where the screen is not registered.
            --}}
            @if (feature('serials') && $canManage && Route::has('serials.edit'))
                <a href="{{ route('serials.edit', $sale) }}"
                   class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                    <x-icon name="key" size="4" />
                    Serial numbers
                </a>
            @endif

            <a href="{{ route('pos.print', ['sale' => $sale->id, 'reprint' => 1]) }}"
               class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                <x-icon name="receipt" size="4" />
                Reprint receipt
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <x-badge :variant="$statusVariant" size="lg" dot>{{ $statusLabel }}</x-badge>

        @if ($sale->status === 'voided')
            <span class="text-sm text-red-700 dark:text-red-300">
                Voided {{ $sale->voided_at?->format('d M Y H:i') }} by {{ $sale->voider->name ?? '-' }}:
                {{ $sale->void_reason }}
            </span>
        @endif
    </div>

    {{-- Totals --}}
    <div class="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <x-stat label="Subtotal" :value="money($sale->total_amount)" />
        <x-stat label="Discount" :value="money($sale->discount_amount)" />
        <x-stat label="Tax" :value="money($sale->tax_amount)" />
        <x-stat label="Total" :value="money($sale->grand_total)" icon="coins" />
        <x-stat label="Refunded" :value="money($sale->refunded_amount)" icon="undo" invert
                :trend="$sale->refunded_amount > 0 ? 'up' : null" />
    </div>

    {{-- Refund form wraps the items table so quantities can be picked right on the lines --}}
    <form method="POST" action="{{ route('sales.refund', $sale) }}" id="refund-form">
        @csrf

        <x-card :padding="false" :title="term('sale', false, 'Sale') . ' lines'">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>{{ $productTerm }}</th>
                        <th class="text-right">Price</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Paid</th>
                        <th class="text-right">Refunded</th>
                        @if ($refundable)
                            <th class="text-right">Refund qty</th>
                            <th class="text-center">Back to stock</th>
                        @endif
                    </tr>
                </x-slot:head>

                @foreach ($sale->items as $item)
                    @php $itemName = $item->product->name ?? 'Deleted product'; @endphp
                    <tr>
                        <td class="font-medium">{{ $itemName }}</td>
                        <td class="text-right tabular-nums">{{ money($item->price) }}</td>
                        <td class="text-right tabular-nums">{{ $item->quantity }}</td>
                        <td class="text-right tabular-nums">{{ money($item->paid_total) }}</td>
                        <td class="text-right tabular-nums">
                            {{ $item->refunded_quantity }} ({{ money($item->refunded_amount) }})
                        </td>

                        @if ($refundable)
                            <td class="text-right">
                                <input type="number" name="items[{{ $item->id }}][quantity]" min="0"
                                       max="{{ $item->refundable_quantity }}" value="0"
                                       @disabled($item->refundable_quantity === 0)
                                       aria-label="Quantity of {{ $itemName }} to refund"
                                       class="{{ $fieldClass }} w-20 text-right tabular-nums disabled:opacity-50">
                            </td>
                            <td class="text-center">
                                <input type="checkbox" name="items[{{ $item->id }}][restock]" value="1" checked
                                       @disabled($item->refundable_quantity === 0)
                                       aria-label="Return {{ $itemName }} to stock"
                                       class="h-5 w-5 rounded border-gray-300 text-primary-600 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800">
                            </td>
                        @endif
                    </tr>
                @endforeach
            </x-table>
        </x-card>

        @if ($refundable)
            <x-card title="Refund selected items" class="mt-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-0 flex-1 basis-64">
                        <label for="refund-reason" class="{{ $fieldLabel }}">
                            Reason <span class="text-red-600" aria-hidden="true">*</span>
                        </label>
                        <input id="refund-reason" type="text" name="reason" placeholder="Reason (required)"
                               value="{{ old('reason') }}" required minlength="3"
                               class="{{ $fieldClass }} w-full">
                    </div>

                    <div>
                        <label for="refund-method" class="{{ $fieldLabel }}">Method</label>
                        <select id="refund-method" name="method" class="{{ $fieldClass }}">
                            <option value="cash">Refund via cash</option>
                            <option value="pos">Refund via POS</option>
                            <option value="transfer">Refund via transfer</option>
                        </select>
                    </div>

                    <button type="submit"
                            class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">
                        <x-icon name="undo" size="4" />
                        Refund selected items
                    </button>
                </div>

                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Untick "Back to stock" for damaged goods: the money is returned but the cost stays booked as a loss.
                </p>
            </x-card>
        @endif
    </form>

    {{-- Void --}}
    @if ($canManage && $sale->status === 'completed')
        <x-card title="Void entire sale"
                subtitle="Returns every line to stock and refunds the whole amount."
                class="mt-4">
            <form method="POST" action="{{ route('sales.void', $sale) }}"
                  class="flex flex-wrap items-end gap-3"
                  onsubmit="return confirm('Void this whole sale and return all items to stock?');">
                @csrf

                <div class="min-w-0 flex-1 basis-64">
                    <label for="void-reason" class="{{ $fieldLabel }}">
                        Reason for voiding <span class="text-red-600" aria-hidden="true">*</span>
                    </label>
                    <input id="void-reason" type="text" name="reason" placeholder="Reason for voiding (required)"
                           required minlength="3" class="{{ $fieldClass }} w-full">
                </div>

                <div>
                    <label for="void-method" class="{{ $fieldLabel }}">Method</label>
                    <select id="void-method" name="method" class="{{ $fieldClass }}">
                        <option value="cash">Refund via cash</option>
                        <option value="pos">Refund via POS</option>
                        <option value="transfer">Refund via transfer</option>
                    </select>
                </div>

                <button type="submit"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                    <x-icon name="x-circle" size="4" />
                    Void entire sale
                </button>
            </form>
        </x-card>
    @endif

    {{-- History --}}
    @if ($sale->refunds->isNotEmpty())
        <x-card title="Refund history" class="mt-4">
            <ul class="space-y-3">
                @foreach ($sale->refunds as $refund)
                    <li class="rounded-lg bg-gray-50 p-3 ring-1 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                {{ money($refund->total_amount) }}
                            </span>

                            @if ($refund->is_void)
                                <x-badge variant="red" size="sm">void</x-badge>
                            @endif

                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                via {{ $refund->method }} &middot;
                                {{ $refund->created_at->format('d M Y H:i') }} &middot;
                                {{ $refund->user->name ?? '-' }}
                            </span>
                        </div>

                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $refund->reason }}</p>

                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            @foreach ($refund->items as $ri)
                                {{ $ri->product->name ?? 'Deleted product' }} &times;{{ $ri->quantity }}{{ $ri->restocked ? '' : ' (not restocked)' }}@if (!$loop->last), @endif
                            @endforeach
                        </p>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif
@endsection
