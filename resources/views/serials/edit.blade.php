@extends('layouts.app')

@section('title', 'Serial numbers · ' . $sale->invoice_number)

@php
    /*
     * Enter or correct the serials for one sale.
     *
     * The till will do this at checkout once it is ready (it calls the same
     * SerialService); this screen is how the feature is used today, and how a
     * mistyped IMEI is put right afterwards.
     *
     * The form posts every box of every line - serials[sale_item_id][] - and the
     * service replaces that line's serials with what arrives. A line left
     * entirely blank is cleared, which is how a wrong number is removed.
     *
     * The action is written out rather than built with route(): this screen is
     * reachable as soon as the route is registered, and renders either way.
     */
    $input = 'w-full rounded-md border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm bg-white dark:bg-gray-700 focus:ring-2 focus:ring-blue-500 focus:outline-none';
    $saleLabel = term('sale', false, 'Sale');
@endphp

@section('content')
    <div class="bg-white dark:bg-gray-800 p-6 rounded shadow-sm text-sm space-y-6">

        <div class="flex flex-wrap justify-between items-start gap-4">
            <div>
                <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100 font-mono">
                    {{ $sale->invoice_number }}
                </h2>
                <p class="text-gray-500 dark:text-gray-400">
                    Serial / IMEI numbers &middot;
                    {{ $sale->created_at->format('d M Y H:i') }} &middot;
                    {{ $sale->user->name ?? '-' }}
                </p>
            </div>
            <a href="{{ route('sales.show', $sale) }}" class="px-4 py-2 rounded border dark:border-gray-600">
                ← Back to {{ Str::lower($saleLabel) }}
            </a>
        </div>

        @if ($sale->status === 'voided')
            <p class="p-3 rounded bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-200">
                This {{ Str::lower($saleLabel) }} was voided, so nothing went out on it and its serials cannot be changed.
            </p>
        @else
            @if ($missing)
                <p class="p-3 rounded bg-amber-50 dark:bg-amber-900/40 text-amber-800 dark:text-amber-100">
                    {{ count($missing) }} line(s) still need their serial numbers.
                </p>
            @endif

            <form method="POST" action="/sales/{{ $sale->id }}/serials" class="space-y-4">
                @csrf
                @method('PUT')

                @foreach ($sale->items as $item)
                    @php
                        $recorded = $item->serials->pluck('serial')->values();
                        $required = $item->requiresSerials();
                    @endphp

                    <div class="p-3 rounded border border-gray-200 dark:border-gray-700">
                        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-2">
                            <p class="font-medium text-gray-800 dark:text-gray-100">
                                {{ $item->product?->displayName() ?? 'Deleted product' }}
                                <span class="text-gray-500 dark:text-gray-400">
                                    &times;{{ $item->quantity }} &middot; {{ money($item->price) }} each
                                </span>
                                @if ($required)
                                    <span class="text-xs px-1 rounded bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                        serial required
                                    </span>
                                @endif
                            </p>
                            @if (isset($missing[$item->id]))
                                <span class="text-xs text-amber-700 dark:text-amber-300">
                                    {{ $missing[$item->id] }} missing
                                </span>
                            @endif
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2">
                            {{-- One box per unit sold: three phones, three IMEIs. --}}
                            @for ($unit = 0; $unit < (int) $item->quantity; $unit++)
                                <input type="text" name="serials[{{ $item->id }}][]" maxlength="120"
                                       value="{{ old('serials.' . $item->id . '.' . $unit, $recorded[$unit] ?? '') }}"
                                       placeholder="Unit {{ $unit + 1 }}"
                                       class="{{ $input }}">
                            @endfor
                        </div>
                    </div>
                @endforeach

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                        Save serial numbers
                    </button>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        A line needs one serial per unit, or none at all. Clearing every box on a line removes
                        its serials. The same serial cannot go out twice.
                    </p>
                </div>
            </form>
        @endif
    </div>
@endsection
