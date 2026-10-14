{{--
    One status pill, so the list and the sheet itself can never drift apart.

    This is an x-badge now rather than its own hand-written span: every status in
    the app is the same shape, the same sizes and the same dark-mode colours.

      @include('stock-takes._status', ['status' => $stockTake->status])
      @include('stock-takes._status', ['status' => $stockTake->status, 'size' => 'md'])
--}}
@php
    $variant = match ($status) {
        \App\Models\StockTake::STATUS_APPROVED => 'green',
        \App\Models\StockTake::STATUS_SUBMITTED => 'amber',
        \App\Models\StockTake::STATUS_CANCELLED => 'gray',
        default => 'blue',
    };
@endphp

<x-badge :variant="$variant" :size="$size ?? 'sm'" dot>{{ ucfirst($status) }}</x-badge>
