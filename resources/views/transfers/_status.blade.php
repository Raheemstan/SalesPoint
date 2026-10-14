{{-- One transfer's state as a pill. Shared by the list and the detail page so
     the two can never describe the same transfer differently. --}}
@php
    [$classes, $label] = match ($transfer->status) {
        \App\Models\StockTransfer::STATUS_RECEIVED => [
            'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300', 'Received',
        ],
        \App\Models\StockTransfer::STATUS_CANCELLED => [
            'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300', 'Cancelled',
        ],
        default => [
            'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300', 'In transit',
        ],
    };
@endphp

<span class="px-2 py-0.5 rounded text-xs {{ $classes }}">{{ $label }}</span>
