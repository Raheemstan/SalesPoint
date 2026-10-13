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
    $input = 'rounded-md border border-gray-300 dark:border-gray-600 px-3 py-2 bg-white dark:bg-gray-700 focus:ring-2 focus:ring-blue-500 focus:outline-none';
    $saleLabel = term('sale', false, 'Sale');
@endphp

@section('content')
    <div class="space-y-6">

        <div class="bg-white dark:bg-gray-800 p-6 rounded shadow-sm">
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-1">Serial / IMEI lookup</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                Enter a serial to find the {{ Str::lower($saleLabel) }} it went out on.
            </p>

            <form method="GET" action="/serials" class="flex flex-wrap gap-3">
                <input type="text" name="serial" value="{{ $search }}" maxlength="120" autofocus
                       placeholder="Serial or IMEI" class="w-72 {{ $input }}">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                    Look up
                </button>
                @if ($search !== '')
                    <a href="/serials" class="px-4 py-2 rounded border dark:border-gray-600">Clear</a>
                @endif
            </form>
        </div>

        @php
            $rows = $matches->isNotEmpty() ? $matches : ($partial->isNotEmpty() ? $partial : $recent);

            $heading = match (true) {
                $matches->isNotEmpty() => 'Found',
                $partial->isNotEmpty() => 'No exact match. These contain what you typed',
                $search !== '' => 'No serial like that has been sold',
                default => 'Recently recorded',
            };
        @endphp

        <div class="bg-white dark:bg-gray-800 p-6 rounded shadow-sm">
            <h3 class="font-semibold text-gray-800 dark:text-gray-100 mb-3">{{ $heading }}</h3>

            @if ($rows->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Nothing to show. Serials are recorded against each
                    {{ Str::lower($saleLabel) }} line.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="bg-gray-100 dark:bg-gray-700">
                                <th class="p-2">Serial</th>
                                <th class="p-2">{{ term('product', false, 'Product') }}</th>
                                <th class="p-2">{{ $saleLabel }}</th>
                                <th class="p-2">Sold</th>
                                <th class="p-2">{{ term('cashier', false, 'Cashier') }}</th>
                                <th class="p-2">{{ term('customer', false, 'Customer') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php $sale = $row->saleItem?->sale; @endphp
                                <tr class="border-b border-gray-200 dark:border-gray-600">
                                    <td class="p-2 font-mono">{{ $row->serial }}</td>
                                    <td class="p-2">{{ $row->product?->displayName() ?? 'Deleted product' }}</td>
                                    <td class="p-2">
                                        @if ($sale)
                                            <a href="{{ route('sales.show', $sale) }}"
                                               class="text-blue-600 dark:text-blue-400 hover:underline font-mono">
                                                {{ $sale->invoice_number }}
                                            </a>
                                            @if ($sale->status !== 'completed')
                                                <span class="text-xs text-red-600 dark:text-red-400">
                                                    {{ str_replace('_', ' ', $sale->status) }}
                                                </span>
                                            @endif
                                        @else
                                            <span class="text-gray-400">-</span>
                                        @endif
                                    </td>
                                    <td class="p-2">{{ $sale?->created_at?->format('d M Y H:i') ?? '-' }}</td>
                                    <td class="p-2">{{ $sale?->user?->name ?? '-' }}</td>
                                    <td class="p-2">{{ $sale?->customer?->name ?? 'Walk-in' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
