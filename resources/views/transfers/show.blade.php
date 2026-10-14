@extends('layouts.app')

@section('title', 'Transfer ' . $transfer->reference)

@section('content')
    <div class="space-y-4">

        <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="text-sm text-gray-600 dark:text-gray-300 space-y-1">
                    <div>
                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $transfer->fromBranch->label() }}</span>
                        <span class="mx-2">&rarr;</span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $transfer->toBranch->label() }}</span>
                    </div>
                    <div>
                        Sent {{ $transfer->dispatched_at?->format('d M Y H:i') ?? '-' }}
                        by {{ $transfer->creator->name ?? 'a deleted user' }}
                    </div>
                    @if ($transfer->isReceived())
                        <div class="text-green-700 dark:text-green-400">
                            Received {{ $transfer->received_at?->format('d M Y H:i') }}
                            by {{ $transfer->receiver->name ?? 'a deleted user' }}
                        </div>
                    @elseif ($transfer->isCancelled())
                        <div>
                            Cancelled {{ $transfer->cancelled_at?->format('d M Y H:i') }}
                            by {{ $transfer->canceller->name ?? 'a deleted user' }}
                            &mdash; the stock went back to {{ $transfer->fromBranch->name }}.
                        </div>
                    @else
                        {{-- The honest statement of where the goods are. Worth saying
                             plainly: this is the window in which the group-wide stock
                             figure is deliberately lower than the sum of the shelves. --}}
                        <div class="text-amber-700 dark:text-amber-400">
                            On its way. These units are on no branch's shelves until
                            {{ $transfer->toBranch->name }} confirms they arrived.
                        </div>
                    @endif
                    @if ($transfer->note)
                        <div class="text-xs">{{ $transfer->note }}</div>
                    @endif
                </div>

                <div class="text-right space-y-2">
                    @include('transfers._status', ['transfer' => $transfer])

                    <div class="text-sm text-gray-600 dark:text-gray-300">
                        {{ number_format($transfer->totalQuantity()) }} units
                        &middot; {{ money($transfer->totalValue()) }} at cost
                    </div>
                </div>
            </div>

            @if ($canReceive || $canCancel)
                <div class="mt-4 pt-4 border-t dark:border-gray-700 flex flex-wrap gap-3">
                    @if ($canReceive)
                        <form method="POST" action="{{ route('transfers.receive', $transfer) }}"
                              onsubmit="return confirm('Confirm every line arrived in full? This puts the stock on {{ $transfer->toBranch->name }}\'s shelves.')">
                            @csrf
                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm">
                                Receive in full
                            </button>
                        </form>
                    @endif

                    @if ($canCancel)
                        <form method="POST" action="{{ route('transfers.cancel', $transfer) }}"
                              class="flex flex-wrap items-center gap-2"
                              onsubmit="return confirm('Recall this transfer? The stock goes back to {{ $transfer->fromBranch->name }}.')">
                            @csrf
                            <input type="text" name="reason" maxlength="255" placeholder="Why (optional)"
                                   class="px-3 py-2 border rounded-md text-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                            <button type="submit" class="px-4 py-2 rounded border border-red-300 text-red-600 text-sm hover:bg-red-50 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-900/30">
                                Recall
                            </button>
                        </form>
                    @endif
                </div>
            @elseif ($transfer->isInTransit())
                <div class="mt-4 pt-4 border-t dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400">
                    Receiving happens at {{ $transfer->toBranch->name }} and recalling at
                    {{ $transfer->fromBranch->name }}. Switch branch in the header to act on this transfer.
                </div>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
            <div class="overflow-x-auto">
                <table class="w-full table-auto text-sm">
                    <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                        <tr>
                            <th class="p-2 text-left">{{ term('product') }}</th>
                            <th class="p-2 text-left">SKU</th>
                            <th class="p-2 text-right">Units</th>
                            <th class="p-2 text-right">At cost</th>
                            <th class="p-2 text-right">At {{ $transfer->toBranch->code }} now</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transfer->items as $item)
                            <tr class="border-t dark:border-gray-700">
                                <td class="p-2">{{ $item->product?->displayName() ?? 'Deleted ' . strtolower(term('product')) }}</td>
                                <td class="p-2 font-mono text-xs">{{ $item->product->sku ?? '-' }}</td>
                                <td class="p-2 text-right font-semibold">{{ number_format($item->quantity) }}</td>
                                <td class="p-2 text-right">{{ money($item->value()) }}</td>
                                <td class="p-2 text-right text-gray-500 dark:text-gray-400">
                                    {{ number_format((int) ($destinationStock[$item->product_id] ?? 0)) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <a href="{{ route('transfers.index') }}" class="inline-block text-sm text-gray-500 dark:text-gray-400 hover:underline">
            &larr; All transfers
        </a>
    </div>
@endsection
