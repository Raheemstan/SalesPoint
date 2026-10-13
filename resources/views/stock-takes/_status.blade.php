{{-- One status pill, so the list and the sheet itself can never drift apart. --}}
@php
    $classes = match ($status) {
        \App\Models\StockTake::STATUS_APPROVED => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        \App\Models\StockTake::STATUS_SUBMITTED => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        \App\Models\StockTake::STATUS_CANCELLED => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
        default => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    };
@endphp

<span class="px-2 py-0.5 rounded text-xs font-medium {{ $classes }}">{{ ucfirst($status) }}</span>
