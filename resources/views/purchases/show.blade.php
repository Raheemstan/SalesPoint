@extends('layouts.app')

@section('title', term('purchase', false, 'Purchase') . ' ' . $purchase->invoice_number)

@section('content')
    @php
        $label = term('purchase', false, 'Purchase');
        $card = 'bg-white dark:bg-gray-800 rounded shadow p-4';
        $input = 'rounded border border-gray-300 dark:border-gray-600 px-2 py-1 bg-white dark:bg-gray-700';

        $statusTone = [
            \App\Models\Purchase::STATUS_ORDERED => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
            \App\Models\Purchase::STATUS_RECEIVED => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
            \App\Models\Purchase::STATUS_CANCELLED => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
        ][$purchase->status] ?? 'bg-gray-200 text-gray-700';

        $returnable = $purchase->isReturnable()
            && $purchase->purchaseItems->sum(fn($item) => $item->returnable_quantity) > 0;
    @endphp

    <div x-data="{ returnModal: {{ $errors->any() && old('_form') === 'return' ? 'true' : 'false' }} }" class="space-y-4">

        {{-- Header --}}
        <div class="{{ $card }} flex flex-wrap justify-between items-start gap-4">
            <div>
                <h2 class="text-xl font-semibold font-mono">{{ $purchase->invoice_number }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    <span class="text-xs px-2 py-1 rounded {{ $statusTone }}">{{ ucfirst($purchase->status) }}</span>
                    &middot; {{ \Carbon\Carbon::parse($purchase->purchase_date)->format('d M Y') }}
                    &middot; {{ $purchase->user->name ?? '-' }}
                    @if ($purchase->received_at)
                        &middot; received {{ $purchase->received_at->format('d M Y') }}
                    @elseif ($purchase->expected_at)
                        &middot; expected {{ $purchase->expected_at->format('d M Y') }}
                    @endif
                </p>
                @unless ($purchase->hasMovedStock())
                    <p class="text-sm text-yellow-700 dark:text-yellow-300 mt-2">
                        Nothing on this {{ Str::lower($label) }} has been added to stock. Edit it and set the status to
                        received when the goods arrive.
                    </p>
                @endunless
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($returnable)
                    <button type="button" @click="returnModal = true"
                            class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded text-sm">Return to supplier</button>
                @endif
                <a href="{{ route('purchases.edit', $purchase) }}"
                   class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded text-sm">Edit</a>
                <form action="{{ route('purchases.destroy', $purchase) }}" method="POST" class="inline"
                      onsubmit="return confirm('Delete this {{ Str::lower($label) }}? Anything it added to stock is taken back out.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded text-sm">Delete</button>
                </form>
                <a href="{{ route('purchases.index') }}"
                   class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm">&larr; Back</a>
            </div>
        </div>

        {{-- Supplier and money --}}
        <div class="grid md:grid-cols-2 gap-4">
            <div class="{{ $card }}">
                <h4 class="font-medium mb-2">{{ term('supplier', false, 'Supplier') }}</h4>
                <div class="text-sm text-gray-600 dark:text-gray-300">
                    <a href="{{ route('suppliers.show', $purchase->supplier) }}"
                       class="font-semibold text-blue-600 dark:text-blue-400 hover:underline">{{ $purchase->supplier->name }}</a><br>
                    {{ $purchase->supplier->phone }}<br>
                    {{ $purchase->supplier->email }}<br>
                    {{ $purchase->supplier->address }}
                </div>
            </div>
            <div class="{{ $card }}">
                <h4 class="font-medium mb-2">{{ $label }}</h4>
                <div class="text-sm text-gray-600 dark:text-gray-300 space-y-1">
                    <div><strong>Total:</strong> {{ money($purchase->total_amount) }}</div>
                    <div><strong>Paid on delivery:</strong> {{ money($purchase->amount_paid) }}</div>
                    <div><strong>Outstanding:</strong> {{ money($purchase->balance_due) }}</div>
                    @if ($purchase->reference)
                        <div><strong>Their reference:</strong> {{ $purchase->reference }}</div>
                    @endif
                    <div><strong>Note:</strong> {{ $purchase->note ?: '-' }}</div>
                </div>
            </div>
        </div>

        {{-- Line items --}}
        <div class="{{ $card }} overflow-x-auto">
            <h4 class="font-medium mb-3">Items</h4>
            <table class="w-full text-sm">
                <thead class="bg-gray-100 dark:bg-gray-700 text-left">
                    <tr>
                        <th class="p-2">{{ term('product', false, 'Product') }}</th>
                        <th class="p-2 text-right">Quantity</th>
                        <th class="p-2 text-right">Unit cost</th>
                        <th class="p-2 text-right">Total</th>
                        <th class="p-2 text-right">Returned</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($purchase->purchaseItems as $item)
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <td class="p-2">{{ $item->product->name ?? 'Deleted product' }}</td>
                            <td class="p-2 text-right">{{ $item->quantity }}</td>
                            <td class="p-2 text-right">{{ money($item->cost_price) }}</td>
                            <td class="p-2 text-right">{{ money($item->total) }}</td>
                            <td class="p-2 text-right">{{ $item->returned_quantity ?: '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Return history --}}
        @if ($purchase->returns->isNotEmpty())
            <div class="{{ $card }}">
                <h4 class="font-medium mb-3">Returned to supplier</h4>
                @foreach ($purchase->returns as $return)
                    <div class="mb-3 p-3 rounded bg-gray-50 dark:bg-gray-700">
                        <p class="text-sm">
                            <strong>{{ money($return->total_amount) }}</strong>
                            <span class="font-mono text-xs">{{ $return->reference }}</span> &middot;
                            {{ $return->created_at->format('d M Y H:i') }} &middot; {{ $return->user->name ?? '-' }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $return->reason }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            @foreach ($return->items as $line)
                                {{ $line->product->name ?? 'Deleted product' }} &times;{{ $line->quantity }}
                                ({{ money($line->amount) }})@if (!$loop->last), @endif
                            @endforeach
                        </p>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Return modal --}}
        @if ($returnable)
            <div x-show="returnModal" style="display: none"
                 class="fixed inset-0 bg-black bg-opacity-50 flex items-start justify-center z-50 p-4 overflow-y-auto">
                <div class="bg-white dark:bg-gray-800 rounded shadow p-6 w-full max-w-2xl my-8" @click.away="returnModal = false">
                    <h3 class="text-lg font-semibold mb-1">Return goods to {{ $purchase->supplier->name }}</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        Stock comes off the shelf and the supplier is credited at the cost on this
                        {{ Str::lower($label) }} - not at what the item costs today.
                    </p>

                    <form method="POST" action="{{ route('purchases.returns.store', $purchase) }}">
                        @csrf
                        <input type="hidden" name="_form" value="return">

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-100 dark:bg-gray-700 text-left">
                                    <tr>
                                        <th class="p-2">{{ term('product', false, 'Product') }}</th>
                                        <th class="p-2 text-right">Unit cost</th>
                                        <th class="p-2 text-right">Left to return</th>
                                        <th class="p-2">Return</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($purchase->purchaseItems as $item)
                                        <tr class="border-b border-gray-200 dark:border-gray-700">
                                            <td class="p-2">{{ $item->product->name ?? 'Deleted product' }}</td>
                                            <td class="p-2 text-right">{{ money($item->cost_price) }}</td>
                                            <td class="p-2 text-right">{{ $item->returnable_quantity }}</td>
                                            <td class="p-2">
                                                <input type="number" name="items[{{ $item->id }}][quantity]"
                                                       min="0" max="{{ $item->returnable_quantity }}" value="0"
                                                       @disabled($item->returnable_quantity === 0)
                                                       class="w-20 {{ $input }}">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reason</label>
                                <input type="text" name="reason" required minlength="3" maxlength="255"
                                       value="{{ old('reason') }}" placeholder="Damaged, wrong item, over-delivery"
                                       class="w-full {{ $input }}">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Reference (optional)
                                </label>
                                <input type="text" name="reference" maxlength="255" value="{{ old('reference') }}"
                                       placeholder="Their credit note number" class="w-full {{ $input }}">
                            </div>
                        </div>

                        <div class="flex justify-end gap-2 mt-4">
                            <button type="button" @click="returnModal = false"
                                    class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600">Cancel</button>
                            <button type="submit" class="px-4 py-2 rounded bg-orange-600 hover:bg-orange-700 text-white">
                                Return selected
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
@endsection
