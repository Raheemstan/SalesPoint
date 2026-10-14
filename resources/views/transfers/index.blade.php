@extends('layouts.app')

@section('title', 'Stock Transfers')

@section('content')
    <div class="space-y-4">

        <div class="bg-white dark:bg-gray-800 rounded shadow p-4 flex flex-wrap gap-4 justify-between items-center">
            <div class="text-sm text-gray-600 dark:text-gray-300">
                Stock leaves the sending branch the moment it is dispatched and arrives at the
                destination only when somebody there confirms it &mdash; so goods on a van are never
                counted in two places at once.
                @if ($awaiting > 0)
                    <span class="block mt-1 font-medium text-amber-700 dark:text-amber-400">
                        {{ $awaiting }} {{ Str::plural('transfer', $awaiting) }} waiting to be received here.
                    </span>
                @endif
            </div>
            <div class="flex gap-2">
                <a href="{{ route('branches.index') }}"
                   class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                    Branches
                </a>
                @if ($canCreate)
                    <a href="{{ route('transfers.create') }}"
                       class="px-4 py-2 rounded bg-green-600 hover:bg-green-700 text-white text-sm">
                        + Send stock
                    </a>
                @endif
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
            <form method="GET" class="flex flex-wrap items-end gap-3 mb-4">
                <div class="w-48">
                    <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Status</label>
                    <select name="status" id="status"
                            class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                        <option value="">All</option>
                        @foreach (\App\Models\StockTransfer::STATUSES as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>
                                {{ ucfirst(str_replace('_', ' ', $status)) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                @role('admin')
                    <div class="w-48">
                        <label for="scope" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Show</label>
                        <select name="scope" id="scope"
                                class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                            <option value="branch" @selected(!$groupWide)>{{ $active?->name ?? 'This branch' }}</option>
                            <option value="all" @selected($groupWide)>Every branch</option>
                        </select>
                    </div>
                @endrole

                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">Filter</button>
                <a href="{{ route('transfers.index') }}" class="text-sm text-gray-500 dark:text-gray-400">Reset</a>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full table-auto text-sm">
                    <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                        <tr>
                            <th class="p-2 text-left">Reference</th>
                            <th class="p-2 text-left">Sent</th>
                            <th class="p-2 text-left">From</th>
                            <th class="p-2 text-left">To</th>
                            <th class="p-2 text-right">Lines</th>
                            <th class="p-2 text-right">Units</th>
                            <th class="p-2 text-left">Status</th>
                            <th class="p-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transfers as $transfer)
                            <tr class="border-t dark:border-gray-700">
                                <td class="p-2 font-mono">{{ $transfer->reference }}</td>
                                <td class="p-2">{{ $transfer->dispatched_at?->format('d M Y H:i') ?? '-' }}</td>
                                <td class="p-2">{{ $transfer->fromBranch->code ?? '-' }}</td>
                                <td class="p-2">{{ $transfer->toBranch->code ?? '-' }}</td>
                                <td class="p-2 text-right">{{ $transfer->items_count }}</td>
                                <td class="p-2 text-right">{{ number_format((int) $transfer->items_sum_quantity) }}</td>
                                <td class="p-2">@include('transfers._status', ['transfer' => $transfer])</td>
                                <td class="p-2 text-right">
                                    <a href="{{ route('transfers.show', $transfer) }}" class="text-blue-600 dark:text-blue-400 hover:underline">
                                        {{ $transfer->isInTransit() && $active?->id === (int) $transfer->to_branch_id ? 'Receive' : 'View' }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="p-4 text-center text-gray-500 dark:text-gray-400">
                                    No transfers yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">{{ $transfers->links() }}</div>
        </div>
    </div>
@endsection
