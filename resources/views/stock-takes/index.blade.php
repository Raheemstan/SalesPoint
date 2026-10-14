@extends('layouts.app')

@section('title', 'Stock Takes')

@php
    /*
    |--------------------------------------------------------------------------
    | Stock-takes: the list of count sheets
    |--------------------------------------------------------------------------
    |
    | Presentation only. The figures, the paging and the status filter all come
    | from StockTakeController@index exactly as before; this file now says them
    | in the same shell, cards, table and badges as the rest of the app.
    */

    // money() puts the symbol first, so a bare negative would read "<symbol>-100".
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));

    $statuses = collect(\App\Models\StockTake::STATUSES)
        ->mapWithKeys(fn($status) => [$status => ucfirst($status)])
        ->all();
@endphp

@section('content')
    <x-page-header title="Stock takes"
                   subtitle="Count what is on the shelf, compare it with what the system believes, and correct the difference. Stock only moves when a count is approved.">
        <x-slot:actions>
            <a href="{{ route('reorder.index') }}"
               class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                <x-icon name="truck" size="4" />
                Reorder list
            </a>

            <a href="{{ route('adjustments.index') }}"
               class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                <x-icon name="sliders" size="4" />
                Adjustments
            </a>

            <a href="{{ route('stock-takes.create') }}"
               class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                <x-icon name="plus" size="4" />
                New count sheet
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- One filter row, above the list it scopes. --}}
    <x-card class="mb-4">
        <form method="GET" class="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-select name="status" label="Status" :options="$statuses" placeholder="All"
                      :selected="request('status')" class="min-h-touch" />

            <div class="mb-4 flex min-h-touch flex-wrap items-center gap-2 self-end">
                <button type="submit"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    <x-icon name="search" size="4" />
                    Filter
                </button>

                <a href="{{ route('stock-takes.index') }}"
                   class="inline-flex min-h-touch items-center rounded-md px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                    Reset
                </a>
            </div>
        </form>
    </x-card>

    <x-card :padding="false">
        <x-table>
            <x-slot:head>
                <tr>
                    <th>Reference</th>
                    <th>Started</th>
                    <th>Counted by</th>
                    <th class="text-right">Lines</th>
                    <th class="text-right">Counted</th>
                    <th class="text-right">Variance value</th>
                    <th>Status</th>
                    <th>Approved by</th>
                    <th><span class="sr-only">Open</span></th>
                </tr>
            </x-slot:head>

            @forelse ($stockTakes as $stockTake)
                @php $variance = (float) ($stockTake->variance_value ?? 0); @endphp
                <tr>
                    <td class="whitespace-nowrap font-mono font-medium">{{ $stockTake->reference }}</td>
                    <td class="whitespace-nowrap text-gray-500 dark:text-gray-400">
                        {{ $stockTake->created_at->format('d M Y H:i') }}
                    </td>
                    <td>{{ $stockTake->user->name ?? '-' }}</td>
                    <td class="text-right tabular-nums">{{ number_format($stockTake->items_count) }}</td>
                    <td class="text-right tabular-nums">{{ number_format($stockTake->counted_items_count) }}</td>
                    <td class="text-right tabular-nums {{ $variance < 0 ? 'text-red-700 dark:text-red-400' : ($variance > 0 ? 'text-green-700 dark:text-green-400' : '') }}">
                        {{ $amount($variance) }}
                    </td>
                    <td>@include('stock-takes._status', ['status' => $stockTake->status])</td>
                    <td class="text-gray-500 dark:text-gray-400">{{ $stockTake->approver->name ?? '-' }}</td>
                    <td class="text-right">
                        <a href="{{ route('stock-takes.show', $stockTake) }}"
                           class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                            {{ $stockTake->isEditable() ? 'Count' : 'View' }}
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        <x-empty icon="clipboard-check" title="No stock-takes yet"
                                 message="Start one when you are ready to count.">
                            <x-slot:action>
                                <a href="{{ route('stock-takes.create') }}"
                                   class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                                    <x-icon name="plus" size="4" />
                                    New count sheet
                                </a>
                            </x-slot:action>
                        </x-empty>
                    </td>
                </tr>
            @endforelse
        </x-table>

        @if ($stockTakes->hasPages())
            <x-slot:footer>{{ $stockTakes->links() }}</x-slot:footer>
        @endif
    </x-card>
@endsection
