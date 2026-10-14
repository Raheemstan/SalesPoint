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
    $saleLabel = term('sale', false, 'Sale');

    // Comfortably tappable: this is tablet data entry, one box per physical unit.
    $control = 'w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 font-mono text-sm shadow-sm
                focus:border-primary-500 focus:ring-primary-500
                dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

    $btnPrimary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700';
@endphp

@section('content')
    <x-page-header :title="$sale->invoice_number"
                   :subtitle="'Serial / IMEI numbers · ' . $sale->created_at->format('d M Y H:i') . ' · ' . ($sale->user->name ?? '-')"
                   :back="route('sales.show', $sale)"
                   :back-label="'Back to ' . Str::lower($saleLabel)" />

    @if ($sale->status === \App\Models\Sale::STATUS_VOIDED)
        <x-card>
            <x-empty icon="x-circle" :title="Str::ucfirst(Str::lower($saleLabel)) . ' voided'"
                     :message="'This ' . Str::lower($saleLabel) . ' was voided, so nothing went out on it and its serials cannot be changed.'" />
        </x-card>
    @else
        @if ($missing)
            <div class="mb-4 flex items-start gap-3 rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200 dark:bg-amber-950 dark:ring-amber-800">
                <span class="mt-0.5 shrink-0 text-amber-600 dark:text-amber-400">
                    <x-icon name="alert-triangle" size="5" />
                </span>
                <p class="text-sm text-amber-900 dark:text-amber-100">
                    {{ count($missing) }} line(s) still need their serial numbers.
                </p>
            </div>
        @endif

        <form method="POST" action="/sales/{{ $sale->id }}/serials">
            @csrf
            @method('PUT')

            <x-card title="Units on this {{ Str::lower($saleLabel) }}"
                    subtitle="One box per unit sold: three phones, three IMEIs" :padding="false">
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($sale->items as $item)
                        @php
                            $recorded = $item->serials->pluck('serial')->values();
                            $required = $item->requiresSerials();
                            $name = $item->product?->displayName() ?? 'Deleted product';
                            $shortfall = $missing[$item->id] ?? null;
                        @endphp

                        <div class="p-4 sm:p-5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $name }}</p>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        &times;{{ $item->quantity }} &middot; {{ money($item->price) }} each
                                    </p>
                                </div>

                                <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                                    @if ($required)
                                        <x-badge variant="blue" size="sm">serial required</x-badge>
                                    @endif

                                    @if ($shortfall !== null)
                                        <x-badge variant="amber" size="sm" dot>{{ $shortfall }} missing</x-badge>
                                    @elseif ($recorded->isNotEmpty())
                                        <x-badge variant="green" size="sm" dot>Recorded</x-badge>
                                    @endif
                                </div>
                            </div>

                            {{-- One box per unit sold: three phones, three IMEIs. --}}
                            <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                @for ($unit = 0; $unit < (int) $item->quantity; $unit++)
                                    <input type="text" name="serials[{{ $item->id }}][]" maxlength="120"
                                           value="{{ old('serials.' . $item->id . '.' . $unit, $recorded[$unit] ?? '') }}"
                                           placeholder="Unit {{ $unit + 1 }}"
                                           aria-label="Unit {{ $unit + 1 }} serial for {{ $name }}"
                                           autocomplete="off" spellcheck="false"
                                           class="{{ $control }}">
                                @endfor
                            </div>
                        </div>
                    @endforeach
                </div>

                <x-slot:footer>
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="{{ $btnPrimary }}">
                            <x-icon name="check-circle" size="4" />
                            Save serial numbers
                        </button>
                        <p class="max-w-xl text-xs text-gray-500 dark:text-gray-400">
                            A line needs one serial per unit, or none at all. Clearing every box on a line removes
                            its serials. The same serial cannot go out twice.
                        </p>
                    </div>
                </x-slot:footer>
            </x-card>
        </form>
    @endif
@endsection
