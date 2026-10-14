@extends('layouts.app')

@section('title', 'Send stock')

@section('content')
    <div class="space-y-4">

        <div class="bg-white dark:bg-gray-800 rounded shadow p-4 text-sm text-gray-600 dark:text-gray-300">
            Sending from <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $from->label() }}</span>.
            The stock leaves these shelves as soon as you send it, and lands on the other branch's
            shelves when somebody there confirms it arrived.
            <span class="block mt-1 text-xs">
                You can only send from the branch you are working at &mdash; switch branch in the header to send from another.
            </span>
        </div>

        @if ($destinations->isEmpty())
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4 text-sm text-gray-500 dark:text-gray-400">
                There is nowhere to send stock to: this is the only open branch.
            </div>
        @else
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <form method="GET" class="flex flex-wrap items-end gap-3">
                    <div class="w-72">
                        <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
                            Find a {{ strtolower(term('product')) }}
                        </label>
                        <input type="text" name="search" id="search" value="{{ $search }}" maxlength="120"
                               placeholder="Name, SKU or barcode"
                               class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                    </div>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">Search</button>
                    @if ($search)
                        <a href="{{ route('transfers.create') }}" class="text-sm text-gray-500 dark:text-gray-400">Clear</a>
                    @endif
                </form>
            </div>

            <form method="POST" action="{{ route('transfers.store') }}" class="space-y-4">
                @csrf

                <div class="bg-white dark:bg-gray-800 rounded shadow p-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="to_branch_id" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
                            Send to
                        </label>
                        <select name="to_branch_id" id="to_branch_id" required
                                class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                            <option value="">Choose a branch</option>
                            @foreach ($destinations as $destination)
                                <option value="{{ $destination->id }}" @selected(old('to_branch_id') == $destination->id)>
                                    {{ $destination->label() }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('to_branch_id')" class="mt-1" />
                    </div>

                    <div>
                        <label for="note" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">
                            Note <span class="text-xs text-gray-500 dark:text-gray-400">(optional)</span>
                        </label>
                        <input type="text" name="note" id="note" value="{{ old('note') }}" maxlength="255"
                               placeholder="Who is driving, what it is for"
                               class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                    <div class="overflow-x-auto">
                        <table class="w-full table-auto text-sm">
                            <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                <tr>
                                    <th class="p-2 text-left">{{ term('product') }}</th>
                                    <th class="p-2 text-left">SKU</th>
                                    <th class="p-2 text-right">Here now</th>
                                    <th class="p-2 text-right w-32">Send</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($products as $product)
                                    {{-- Loaded by StockTransferService::sendable() as one
                                         subquery, not a query per row. --}}
                                    @php $here = (int) $product->branch_stock_quantity; @endphp
                                    <tr class="border-t dark:border-gray-700">
                                        <td class="p-2">{{ $product->displayName() }}</td>
                                        <td class="p-2 font-mono text-xs">{{ $product->sku ?: '-' }}</td>
                                        <td class="p-2 text-right">{{ number_format($here) }}</td>
                                        <td class="p-2 text-right">
                                            {{-- max is a convenience, not the control: the service
                                                 re-checks the shelf under a lock, because the browser's
                                                 idea of it is already seconds old. --}}
                                            <input type="number" name="quantities[{{ $product->id }}]"
                                                   value="{{ old('quantities.' . $product->id) }}"
                                                   min="0" max="{{ $here }}" step="1"
                                                   class="w-24 px-2 py-1 text-right border rounded-md dark:bg-gray-800 dark:text-white dark:border-gray-600">
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="p-4 text-center text-gray-500 dark:text-gray-400">
                                            {{ $search ? 'Nothing here matches that.' : 'This branch has nothing on its shelves to send.' }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <x-input-error :messages="$errors->get('quantities')" class="mt-2" />

                    @if ($products->isNotEmpty())
                        <div class="mt-4 pt-4 border-t dark:border-gray-700">
                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm">
                                Dispatch transfer
                            </button>
                            <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">
                                The stock comes off this branch's shelves straight away.
                            </span>
                        </div>
                    @endif
                </div>
            </form>
        @endif
    </div>
@endsection
