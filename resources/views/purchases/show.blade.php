@extends('layouts.app')

@section('title', term('purchase', false, 'Purchase') . ' ' . $purchase->invoice_number)

@section('content')
    @php
        $label = term('purchase', false, 'Purchase');
        $supplierLabel = term('supplier', false, 'Supplier');
        $productLabel = term('product', false, 'Product');

        $statusVariant = [
            \App\Models\Purchase::STATUS_ORDERED => 'amber',
            \App\Models\Purchase::STATUS_RECEIVED => 'green',
            \App\Models\Purchase::STATUS_CANCELLED => 'gray',
        ][$purchase->status] ?? 'gray';

        $returnable = $purchase->isReturnable()
            && $purchase->purchaseItems->sum(fn($item) => $item->returnable_quantity) > 0;

        $input = 'min-h-touch w-full rounded-md border-gray-300 bg-white text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white';
        $labelClass = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
    @endphp

    <div x-data="{ returnModal: {{ $errors->any() && old('_form') === 'return' ? 'true' : 'false' }} }">

        <x-page-header :title="$purchase->invoice_number"
                       :back="route('purchases.index')"
                       :back-label="term('purchase', true, 'Purchases')">
            <x-slot:actions>
                @if ($returnable)
                    <button type="button" @click="returnModal = true"
                            class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">
                        <x-icon name="undo" size="4" />
                        Return to supplier
                    </button>
                @endif

                <a href="{{ route('purchases.edit', $purchase) }}"
                   class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    <x-icon name="sliders" size="4" />
                    Edit
                </a>

                <form action="{{ route('purchases.destroy', $purchase) }}" method="POST"
                      onsubmit="return confirm('Delete this {{ Str::lower($label) }}? Anything it added to stock is taken back out.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="inline-flex min-h-touch items-center gap-1.5 rounded-md px-4 py-2 text-sm font-medium text-red-600 ring-1 ring-red-200 hover:bg-red-50 dark:text-red-400 dark:ring-red-900 dark:hover:bg-red-950">
                        Delete
                    </button>
                </form>
            </x-slot:actions>
        </x-page-header>

        {{-- Status line --}}
        <div class="mb-4 flex flex-wrap items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
            <x-badge :variant="$statusVariant" size="lg" dot>{{ ucfirst($purchase->status) }}</x-badge>
            <span>
                {{ \Carbon\Carbon::parse($purchase->purchase_date)->format('d M Y') }}
                &middot; {{ $purchase->user->name ?? '-' }}
                @if ($purchase->received_at)
                    &middot; received {{ $purchase->received_at->format('d M Y') }}
                @elseif ($purchase->expected_at)
                    &middot; expected {{ $purchase->expected_at->format('d M Y') }}
                @endif
            </span>
        </div>

        @unless ($purchase->hasMovedStock())
            <div class="mb-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-950 dark:text-amber-100 dark:ring-amber-800">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 text-amber-600 dark:text-amber-400">
                        <x-icon name="alert-triangle" size="5" />
                    </span>
                    <p>
                        Nothing on this {{ Str::lower($label) }} has been added to stock. Edit it and set the status to
                        received when the goods arrive.
                    </p>
                </div>
            </div>
        @endunless

        {{-- Money --}}
        <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-stat label="Total" :value="money($purchase->total_amount)" icon="coins" />
            <x-stat label="Paid on delivery" :value="money($purchase->amount_paid)" />
            <x-stat label="Outstanding" :value="money($purchase->balance_due)" invert
                    :trend="$purchase->balance_due > 0 ? 'up' : null"
                    :hint="$purchase->balance_due > 0 ? 'owed to ' . $purchase->supplier->name : 'settled'" />
        </div>

        {{-- Supplier and paperwork --}}
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-card :title="$supplierLabel">
                <div class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
                    <a href="{{ route('suppliers.show', $purchase->supplier) }}"
                       class="block font-semibold text-primary-600 hover:underline dark:text-primary-400">
                        {{ $purchase->supplier->name }}
                    </a>
                    @if ($purchase->supplier->phone)
                        <div>{{ $purchase->supplier->phone }}</div>
                    @endif
                    @if ($purchase->supplier->email)
                        <div class="break-words">{{ $purchase->supplier->email }}</div>
                    @endif
                    @if ($purchase->supplier->address)
                        <div>{{ $purchase->supplier->address }}</div>
                    @endif
                </div>
            </x-card>

            <x-card :title="$label">
                <dl class="space-y-1.5 text-sm">
                    @if ($purchase->reference)
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500 dark:text-gray-400">Their reference</dt>
                            <dd class="font-mono">{{ $purchase->reference }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">Note</dt>
                        <dd class="text-right">{{ $purchase->note ?: '-' }}</dd>
                    </div>
                </dl>
            </x-card>
        </div>

        {{-- Line items --}}
        <x-card title="Items" :padding="false" class="mt-4">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>{{ $productLabel }}</th>
                        <th class="text-right">Quantity</th>
                        <th class="text-right">Unit cost</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Returned</th>
                    </tr>
                </x-slot:head>

                @foreach ($purchase->purchaseItems as $item)
                    <tr>
                        <td class="font-medium">{{ $item->product->name ?? 'Deleted product' }}</td>
                        <td class="text-right tabular-nums">{{ $item->quantity }}</td>
                        <td class="text-right tabular-nums">{{ money($item->cost_price) }}</td>
                        <td class="text-right tabular-nums">{{ money($item->total) }}</td>
                        <td class="text-right tabular-nums">{{ $item->returned_quantity ?: '-' }}</td>
                    </tr>
                @endforeach

                <x-slot:foot>
                    <tr>
                        <td colspan="3" class="text-right">Total</td>
                        <td class="text-right tabular-nums">{{ money($purchase->total_amount) }}</td>
                        <td></td>
                    </tr>
                </x-slot:foot>
            </x-table>
        </x-card>

        {{-- Return history --}}
        @if ($purchase->returns->isNotEmpty())
            <x-card title="Returned to supplier" class="mt-4">
                <ul class="space-y-3">
                    @foreach ($purchase->returns as $return)
                        <li class="rounded-lg bg-gray-50 p-3 ring-1 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700">
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                    {{ money($return->total_amount) }}
                                </span>
                                <span class="font-mono text-xs text-gray-500 dark:text-gray-400">{{ $return->reference }}</span>
                                <span class="text-gray-500 dark:text-gray-400">
                                    {{ $return->created_at->format('d M Y H:i') }} &middot; {{ $return->user->name ?? '-' }}
                                </span>
                            </div>

                            <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $return->reason }}</p>

                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                @foreach ($return->items as $line)
                                    {{ $line->product->name ?? 'Deleted product' }} &times;{{ $line->quantity }}
                                    ({{ money($line->amount) }})@if (!$loop->last), @endif
                                @endforeach
                            </p>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        {{-- Return modal --}}
        @if ($returnable)
            <div x-show="returnModal" x-cloak
                 class="fixed inset-0 z-modal flex items-start justify-center overflow-y-auto bg-gray-900/60 p-4"
                 role="dialog" aria-modal="true" aria-labelledby="return-title"
                 @keydown.escape.window="returnModal = false">
                <div class="my-8 w-full max-w-2xl rounded-xl bg-white shadow-xl ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"
                     @click.outside="returnModal = false">
                    <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                        <div class="min-w-0">
                            <h3 id="return-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">
                                Return goods to {{ $purchase->supplier->name }}
                            </h3>
                            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                Stock comes off the shelf and the supplier is credited at the cost on this
                                {{ Str::lower($label) }} - not at what the item costs today.
                            </p>
                        </div>
                        <button type="button" @click="returnModal = false" aria-label="Close"
                                class="-m-1.5 flex h-touch w-touch shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700">
                            <x-icon name="x" size="5" />
                        </button>
                    </div>

                    <form method="POST" action="{{ route('purchases.returns.store', $purchase) }}">
                        @csrf
                        <input type="hidden" name="_form" value="return">

                        <div class="max-h-[60vh] overflow-y-auto">
                            <x-table>
                                <x-slot:head>
                                    <tr>
                                        <th>{{ $productLabel }}</th>
                                        <th class="text-right">Unit cost</th>
                                        <th class="text-right">Left to return</th>
                                        <th class="text-right">Return</th>
                                    </tr>
                                </x-slot:head>

                                @foreach ($purchase->purchaseItems as $item)
                                    @php $itemName = $item->product->name ?? 'Deleted product'; @endphp
                                    <tr>
                                        <td class="font-medium">{{ $itemName }}</td>
                                        <td class="text-right tabular-nums">{{ money($item->cost_price) }}</td>
                                        <td class="text-right tabular-nums">{{ $item->returnable_quantity }}</td>
                                        <td class="text-right">
                                            <input type="number" name="items[{{ $item->id }}][quantity]"
                                                   min="0" max="{{ $item->returnable_quantity }}" value="0"
                                                   @disabled($item->returnable_quantity === 0)
                                                   aria-label="Quantity of {{ $itemName }} to return"
                                                   class="{{ $input }} w-20 text-right tabular-nums disabled:opacity-50">
                                        </td>
                                    </tr>
                                @endforeach
                            </x-table>
                        </div>

                        <div class="space-y-4 border-t border-gray-200 p-4 dark:border-gray-700 sm:px-5">
                            <div>
                                <label for="return-reason" class="{{ $labelClass }}">
                                    Reason <span class="text-red-600" aria-hidden="true">*</span>
                                </label>
                                <input id="return-reason" type="text" name="reason" required minlength="3" maxlength="255"
                                       value="{{ old('reason') }}" placeholder="Damaged, wrong item, over-delivery"
                                       class="{{ $input }}">
                            </div>
                            <div>
                                <label for="return-reference" class="{{ $labelClass }}">Reference (optional)</label>
                                <input id="return-reference" type="text" name="reference" maxlength="255"
                                       value="{{ old('reference') }}" placeholder="Their credit note number"
                                       class="{{ $input }}">
                            </div>
                        </div>

                        <div class="flex flex-wrap justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                            <button type="button" @click="returnModal = false"
                                    class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">
                                Return selected
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection
