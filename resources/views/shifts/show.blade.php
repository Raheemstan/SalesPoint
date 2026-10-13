@extends('layouts.app')
@section('title', 'Z report · shift #' . $shift->id)

@php
    $cashier = term('cashier');
    $sales = term('sale', true);
    $variance = $summary['variance_total'];

    // money() puts the symbol first, so a negative would read "<symbol>-100.00".
    // The sign belongs in front of the whole amount.
    $amount = fn ($value) => ($value < 0 ? '-' : '') . money(abs($value));
@endphp

@section('content')
    {{--
        Print stylesheet. Shops file these reports, so the printed page has to be the
        report and nothing else: the layout's sidebar and top bar go, the dark theme
        goes (printing a dark background wastes a cartridge and reads badly), and the
        on-screen-only controls go with them. Kept here rather than in the layout
        because this is the only page that prints as a document.
    --}}
    <style>
        @media print {
            aside,
            body > div > div > header,
            .no-print { display: none !important; }

            body > div { display: block !important; height: auto !important; overflow: visible !important; }
            body > div > div { display: block !important; }
            main { overflow: visible !important; padding: 0 !important; }

            html, body { background: #fff !important; color: #000 !important; }
            .z-card { background: #fff !important; color: #000 !important; box-shadow: none !important; }
            .z-card table { page-break-inside: avoid; }
            .z-sign { display: block !important; }
        }
        .z-sign { display: none; }
    </style>

    <div class="z-card bg-white dark:bg-gray-800 rounded shadow p-4 md:p-6 text-sm space-y-6">

        <div class="flex flex-wrap justify-between items-start gap-4">
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ setting('store_name', 'SalesPoint') }}
                </p>
                <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100">
                    End of day · till reconciliation
                </h2>
                <p class="text-gray-500 dark:text-gray-400">
                    Shift #{{ $shift->id }} ·
                    {{ $cashier }}: <strong>{{ $shift->user->name ?? '-' }}</strong> ·
                    printed {{ now()->format('d M Y H:i') }}
                </p>
            </div>

            <div class="flex gap-3 no-print">
                <button type="button" onclick="window.print()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded">
                    Print
                </button>
                <a href="{{ route('shifts.index') }}" class="px-4 py-2 rounded border dark:border-gray-600">Back</a>
            </div>
        </div>

        @if ($shift->isOpen())
            <p class="p-3 rounded border border-yellow-400 bg-yellow-50 dark:bg-yellow-900/40 text-yellow-900 dark:text-yellow-100">
                This shift is still open, so every figure below is live and will keep moving until the drawer is counted.
            </p>
        @elseif ($summary['drifted'])
            {{-- The frozen copy earning its keep: something changed after sign-off. --}}
            <p class="p-3 rounded border border-orange-400 bg-orange-50 dark:bg-orange-900/40 text-orange-900 dark:text-orange-100">
                A record belonging to this shift has been changed since it was closed. The figures below are the ones it was
                signed off against; recomputed from today's data the drawer would be expected to hold
                {{ money(collect($summary['methods'])->sum('expected_live')) }}.
            </p>
        @endif

        {{-- Who and when --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="p-3 rounded bg-gray-50 dark:bg-gray-700">
                <p class="text-xs text-gray-500 dark:text-gray-400">Opened</p>
                <p class="font-semibold">{{ $shift->opened_at->format('d M Y H:i') }}</p>
            </div>
            <div class="p-3 rounded bg-gray-50 dark:bg-gray-700">
                <p class="text-xs text-gray-500 dark:text-gray-400">Closed</p>
                <p class="font-semibold">{{ $shift->closed_at?->format('d M Y H:i') ?? 'Still open' }}</p>
            </div>
            <div class="p-3 rounded bg-gray-50 dark:bg-gray-700">
                <p class="text-xs text-gray-500 dark:text-gray-400">Closed by</p>
                <p class="font-semibold">{{ $shift->closedBy->name ?? '-' }}</p>
            </div>
            <div class="p-3 rounded bg-gray-50 dark:bg-gray-700">
                <p class="text-xs text-gray-500 dark:text-gray-400">Opening float</p>
                <p class="font-semibold">{{ money($shift->opening_float) }}</p>
            </div>
        </div>

        {{-- What was sold --}}
        <div>
            <h3 class="font-semibold text-gray-700 dark:text-gray-200 mb-2">{{ $sales }} during this shift</h3>
            <table class="w-full">
                <tbody>
                    @foreach ([
                        ['Transactions', $summary['sales_count'], false],
                        ['Gross (subtotal before discount)', $summary['subtotal'], true],
                        ['Discounts given', -$summary['discounts'], true],
                        ['Tax collected', $summary['tax'], true],
                        ['Total taken', $summary['gross'], true],
                        ['Refunds paid out', -$summary['refunds'], true],
                    ] as [$label, $value, $isMoney])
                        <tr class="border-b border-gray-200 dark:border-gray-600">
                            <td class="py-1.5">{{ $label }}</td>
                            <td class="py-1.5 text-right">{{ $isMoney ? $amount($value) : $value }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-b border-gray-200 dark:border-gray-600">
                        <td class="py-1.5">Voided {{ Str::lower($sales) }}</td>
                        <td class="py-1.5 text-right">
                            {{ $summary['voids_count'] }}
                            @if ($summary['voids_count'] > 0)
                                ({{ money($summary['voids']) }}, not counted as takings)
                            @endif
                        </td>
                    </tr>
                    <tr class="font-bold">
                        <td class="py-2">Net {{ Str::lower($sales) }}</td>
                        <td class="py-2 text-right">{{ $amount($summary['net']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- The reconciliation itself --}}
        <div>
            <h3 class="font-semibold text-gray-700 dark:text-gray-200 mb-2">Drawer count</h3>
            <table class="w-full">
                <thead>
                    <tr class="bg-gray-100 dark:bg-gray-700 text-left">
                        <th class="p-2">Method</th>
                        <th class="p-2 text-right">Float</th>
                        <th class="p-2 text-right">{{ $sales }}</th>
                        <th class="p-2 text-right">Refunds</th>
                        <th class="p-2 text-right">Expected</th>
                        <th class="p-2 text-right">Counted</th>
                        <th class="p-2 text-right">Difference</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary['methods'] as $row)
                        <tr class="border-b border-gray-200 dark:border-gray-600">
                            <td class="p-2">{{ $row['label'] }}</td>
                            <td class="p-2 text-right">{{ money($row['opening_float']) }}</td>
                            <td class="p-2 text-right">{{ money($row['sales']) }}</td>
                            <td class="p-2 text-right">{{ $amount(-$row['refunds']) }}</td>
                            <td class="p-2 text-right font-semibold">{{ $amount($row['expected']) }}</td>
                            <td class="p-2 text-right">{{ $row['counted'] === null ? 'not counted' : money($row['counted']) }}</td>
                            <td class="p-2 text-right">
                                @include('shifts.partials.difference', ['value' => $row['variance']])
                            </td>
                        </tr>
                    @endforeach
                    <tr class="font-bold bg-gray-50 dark:bg-gray-700">
                        <td class="p-2">Total</td>
                        <td class="p-2 text-right">{{ money($shift->opening_float) }}</td>
                        <td class="p-2 text-right">{{ money(collect($summary['methods'])->sum('sales')) }}</td>
                        <td class="p-2 text-right">{{ $amount(-collect($summary['methods'])->sum('refunds')) }}</td>
                        <td class="p-2 text-right">{{ $amount($summary['expected_total']) }}</td>
                        <td class="p-2 text-right">
                            {{ $summary['counted_total'] === null ? 'not counted' : money($summary['counted_total']) }}
                        </td>
                        <td class="p-2 text-right">
                            @include('shifts.partials.difference', ['value' => $variance])
                        </td>
                    </tr>
                </tbody>
            </table>

            @if ($variance !== null && abs($variance) >= 0.005)
                <p class="mt-3 p-3 rounded border {{ $variance > 0
                    ? 'border-blue-400 bg-blue-50 dark:bg-blue-900/40 text-blue-900 dark:text-blue-100'
                    : 'border-red-400 bg-red-50 dark:bg-red-900/40 text-red-900 dark:text-red-100' }}">
                    The drawer is <strong>{{ $variance > 0 ? 'OVER' : 'SHORT' }}</strong> by
                    <strong>{{ money(abs($variance)) }}</strong>.
                    {{ $variance > 0
                        ? 'More money was counted than the records explain - usually a sale rung up wrong or change not given.'
                        : 'Less money was counted than the records explain - this is the figure to account for.' }}
                </p>
            @endif
        </div>

        {{-- Notes --}}
        @if ($shift->note || $shift->close_note)
            <div class="space-y-2">
                <h3 class="font-semibold text-gray-700 dark:text-gray-200">Notes</h3>
                @if ($shift->note)
                    <p><span class="text-gray-500 dark:text-gray-400">On opening:</span> {{ $shift->note }}</p>
                @endif
                @if ($shift->close_note)
                    <p><span class="text-gray-500 dark:text-gray-400">On closing:</span> {{ $shift->close_note }}</p>
                @endif
            </div>
        @endif

        {{-- Only on paper: a filed Z report is signed by the person who counted and the person who checked. --}}
        <div class="z-sign pt-10">
            <table class="w-full">
                <tr>
                    <td class="pt-8 pr-8" style="border-top: 1px solid #000; width: 50%;">Counted by ({{ $cashier }})</td>
                    <td class="pt-8 pl-8" style="border-top: 1px solid #000; width: 50%;">Checked by (Manager)</td>
                </tr>
            </table>
        </div>
    </div>
@endsection
