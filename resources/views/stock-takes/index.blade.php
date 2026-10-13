@extends('layouts.app')

@section('title', 'Stock Takes')

@section('content')
    <div class="space-y-4">

        {{-- What this screen is for: staff rarely meet a stock-take before their first one. --}}
        <div class="bg-white dark:bg-gray-800 rounded shadow p-4 flex flex-wrap gap-4 justify-between items-center">
            <div class="text-sm text-gray-600 dark:text-gray-300">
                Count what is on the shelf, compare it with what the system believes, and correct the difference.
                Stock only moves when a count is approved.
            </div>
            <div class="flex gap-2">
                <a href="{{ route('reorder.index') }}"
                   class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                    Reorder list
                </a>
                <a href="{{ route('adjustments.index') }}"
                   class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                    Adjustments
                </a>
                <a href="{{ route('stock-takes.create') }}"
                   class="px-4 py-2 rounded bg-green-600 hover:bg-green-700 text-white text-sm">
                    + New count sheet
                </a>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
            <form method="GET" class="flex flex-wrap items-end gap-3 mb-4">
                <div class="w-48">
                    <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Status</label>
                    <select name="status" id="status"
                            class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                        <option value="">All</option>
                        @foreach (\App\Models\StockTake::STATUSES as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">Filter</button>
                <a href="{{ route('stock-takes.index') }}" class="text-sm text-gray-500 dark:text-gray-400">Reset</a>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full table-auto text-sm">
                    <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                        <tr>
                            <th class="p-2 text-left">Reference</th>
                            <th class="p-2 text-left">Started</th>
                            <th class="p-2 text-left">Counted by</th>
                            <th class="p-2 text-right">Lines</th>
                            <th class="p-2 text-right">Counted</th>
                            <th class="p-2 text-right">Variance value</th>
                            <th class="p-2 text-left">Status</th>
                            <th class="p-2 text-left">Approved by</th>
                            <th class="p-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($stockTakes as $stockTake)
                            @php $variance = (float) ($stockTake->variance_value ?? 0); @endphp
                            <tr class="border-t dark:border-gray-700">
                                <td class="p-2 font-mono">{{ $stockTake->reference }}</td>
                                <td class="p-2">{{ $stockTake->created_at->format('d M Y H:i') }}</td>
                                <td class="p-2">{{ $stockTake->user->name ?? '-' }}</td>
                                <td class="p-2 text-right">{{ $stockTake->items_count }}</td>
                                <td class="p-2 text-right">{{ $stockTake->counted_items_count }}</td>
                                <td class="p-2 text-right {{ $variance < 0 ? 'text-red-600 dark:text-red-400' : ($variance > 0 ? 'text-green-600 dark:text-green-400' : '') }}">
                                    {{ money($variance) }}
                                </td>
                                <td class="p-2">
                                    @include('stock-takes._status', ['status' => $stockTake->status])
                                </td>
                                <td class="p-2">{{ $stockTake->approver->name ?? '-' }}</td>
                                <td class="p-2 text-right">
                                    <a href="{{ route('stock-takes.show', $stockTake) }}" class="text-blue-600 dark:text-blue-400 hover:underline">
                                        {{ $stockTake->isEditable() ? 'Count' : 'View' }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center py-6 text-gray-500 dark:text-gray-400">
                                    No stock-takes yet. Start one when you are ready to count.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $stockTakes->links() }}</div>
        </div>
    </div>
@endsection
