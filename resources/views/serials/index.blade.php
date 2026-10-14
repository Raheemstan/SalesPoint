@extends('layouts.app')

@section('title', 'Serial lookup')

@php
    /*
     * "Somebody is standing at the counter with this phone - when did it go out,
     * and on whose sale?" That question is the whole reason serials are recorded,
     * so it gets its own screen rather than being buried in a report.
     *
     * An exact match is what is wanted. A partial one is offered when nothing
     * matches exactly, because the number is being read off a worn label.
     */
    $saleLabel = term('sale', false, 'Sale');

    $control = 'w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm
                focus:border-primary-500 focus:ring-primary-500
                dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

    $btnPrimary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700';
    $btnSecondary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700';
    $linkInline = 'font-medium text-primary-600 hover:underline dark:text-primary-400';

    // A sale that is not simply "completed" is why a unit may be back on the counter.
    $statusBadge = [
        \App\Models\Sale::STATUS_PARTIAL => ['amber', 'Partially refunded'],
        \App\Models\Sale::STATUS_REFUNDED => ['orange', 'Refunded'],
        \App\Models\Sale::STATUS_VOIDED => ['red', 'Voided'],
    ];

    $rows = $matches->isNotEmpty() ? $matches : ($partial->isNotEmpty() ? $partial : $recent);

    $heading = match (true) {
        $matches->isNotEmpty() => 'Found',
        $partial->isNotEmpty() => 'No exact match. These contain what you typed',
        $search !== '' => 'No serial like that has been sold',
        default => 'Recently recorded',
    };
@endphp

@section('content')
    <x-page-header title="Serial / IMEI lookup"
                   :subtitle="'Find the ' . Str::lower($saleLabel) . ' a unit went out on'" />

    <x-card>
        <form method="GET" action="/serials" class="flex flex-wrap items-end gap-3">
            <div class="w-full sm:w-80">
                <label for="serial" class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Serial or IMEI
                </label>
                <input type="text" id="serial" name="serial" value="{{ $search }}" maxlength="120" autofocus
                       autocomplete="off" spellcheck="false"
                       placeholder="Serial or IMEI" class="{{ $control }} font-mono">
            </div>

            <div class="flex min-h-touch flex-wrap items-center gap-2">
                <button type="submit" class="{{ $btnPrimary }}">
                    <x-icon name="search" size="4" />
                    Look up
                </button>

                @if ($search !== '')
                    <a href="/serials" class="{{ $btnSecondary }}">
                        <x-icon name="x" size="4" />
                        Clear
                    </a>
                @endif
            </div>
        </form>
    </x-card>

    <x-card :title="$heading"
            :subtitle="$rows->isNotEmpty() ? $rows->count() . ' ' . Str::plural('serial', $rows->count()) : null"
            :padding="false" class="mt-4">
        <x-table>
            <x-slot:head>
                <tr>
                    <th>Serial</th>
                    <th>{{ term('product', false, 'Product') }}</th>
                    <th>{{ $saleLabel }}</th>
                    <th>Sold</th>
                    <th>{{ term('cashier', false, 'Cashier') }}</th>
                    <th>{{ term('customer', false, 'Customer') }}</th>
                </tr>
            </x-slot:head>

            @forelse ($rows as $row)
                @php $sale = $row->saleItem?->sale; @endphp
                <tr>
                    <td class="font-mono font-medium">{{ $row->serial }}</td>
                    <td>{{ $row->product?->displayName() ?? 'Deleted product' }}</td>
                    <td>
                        @if ($sale)
                            <a href="{{ route('sales.show', $sale) }}" class="{{ $linkInline }} font-mono">
                                {{ $sale->invoice_number }}
                            </a>
                            @if ($sale->status !== \App\Models\Sale::STATUS_COMPLETED)
                                @php [$variant, $label] = $statusBadge[$sale->status] ?? ['gray', $sale->status]; @endphp
                                <span class="mt-1 block">
                                    <x-badge :variant="$variant" size="sm">{{ $label }}</x-badge>
                                </span>
                            @endif
                        @else
                            <span class="text-gray-400 dark:text-gray-500">-</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap">{{ $sale?->created_at?->format('d M Y H:i') ?? '-' }}</td>
                    <td>{{ $sale?->user?->name ?? '-' }}</td>
                    <td class="text-gray-500 dark:text-gray-400">{{ $sale?->customer?->name ?? 'Walk-in' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-empty icon="search"
                                 :title="$search !== '' ? 'Nothing to show for that number' : 'No serials recorded yet'"
                                 :message="'Serials are recorded against each ' . Str::lower($saleLabel) . ' line.'" />
                    </td>
                </tr>
            @endforelse
        </x-table>
    </x-card>
@endsection
