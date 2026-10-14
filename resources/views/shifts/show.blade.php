@extends('layouts.app')
@section('title', 'Z report · shift #' . $shift->id)

@php
    /*
    |--------------------------------------------------------------------------
    | The Z report
    |--------------------------------------------------------------------------
    |
    | One shift's end-of-day reconciliation, for the screen and for the filing
    | cabinet. Shops keep these, so it has to print as a document: see the print
    | stylesheet below.
    */
    $cashier = term('cashier');
    $sales = term('sale', true);
    $variance = $summary['variance_total'];

    $btnSecondary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700';

    // money() puts the symbol first, so a negative would read "<symbol>-100.00".
    // The sign belongs in front of the whole amount.
    $amount = fn ($value) => ($value < 0 ? '-' : '') . money(abs($value));

    $counted = $summary['counted_total'];
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

            /* Every panel of the report prints as plain paper: no theme, no tint,
               no ring (which is a box-shadow), whichever theme is on screen. */
            .z-card,
            .z-card * {
                background: transparent !important;
                color: #000 !important;
                box-shadow: none !important;
            }
            .z-card table { page-break-inside: avoid; }
            .z-sign { display: block !important; }
        }
        .z-sign { display: none; }
    </style>

    <x-page-header class="no-print"
                   :title="'Z report · shift #' . $shift->id"
                   :subtitle="$cashier . ': ' . ($shift->user->name ?? '-')"
                   :back="route('shifts.index')" back-label="All shifts">
        <x-slot:actions>
            <button type="button" onclick="window.print()" class="{{ $btnSecondary }}">
                <x-icon name="receipt" size="4" />
                Print
            </button>
        </x-slot:actions>
    </x-page-header>

    <div class="z-card space-y-4">

        {{-- The masthead, which is what the filed copy is identified by. --}}
        <x-card>
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ setting('store_name', 'SalesPoint') }}
            </p>
            <h2 class="mt-0.5 text-xl font-semibold text-gray-900 dark:text-gray-100 sm:text-2xl">
                End of day &middot; till reconciliation
            </h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Shift #{{ $shift->id }} &middot;
                {{ $cashier }}: <strong class="font-semibold text-gray-700 dark:text-gray-200">{{ $shift->user->name ?? '-' }}</strong> &middot;
                printed {{ now()->format('d M Y H:i') }}
            </p>

            <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 border-t border-gray-200 pt-4 text-sm dark:border-gray-700 sm:grid-cols-4">
                @foreach ([
                    ['Opened', $shift->opened_at->format('d M Y H:i')],
                    ['Closed', $shift->closed_at?->format('d M Y H:i') ?? 'Still open'],
                    ['Closed by', $shift->closedBy->name ?? '-'],
                    ['Opening float', money($shift->opening_float)],
                ] as [$term, $value])
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $term }}</dt>
                        <dd class="mt-0.5 font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-card>

        @if ($shift->isOpen())
            <div class="flex items-start gap-3 rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200 dark:bg-amber-950 dark:ring-amber-800">
                <span class="mt-0.5 shrink-0 text-amber-600 dark:text-amber-400">
                    <x-icon name="alert-triangle" size="5" />
                </span>
                <p class="text-sm text-amber-900 dark:text-amber-100">
                    This shift is still open, so every figure below is live and will keep moving until the drawer is counted.
                </p>
            </div>
        @elseif ($summary['drifted'])
            {{-- The frozen copy earning its keep: something changed after sign-off. --}}
            <div class="flex items-start gap-3 rounded-xl bg-orange-50 p-4 ring-1 ring-orange-200 dark:bg-orange-950 dark:ring-orange-800">
                <span class="mt-0.5 shrink-0 text-orange-600 dark:text-orange-400">
                    <x-icon name="alert-triangle" size="5" />
                </span>
                <p class="text-sm text-orange-900 dark:text-orange-100">
                    A record belonging to this shift has been changed since it was closed. The figures below are the ones it was
                    signed off against; recomputed from today's data the drawer would be expected to hold
                    {{ money(collect($summary['methods'])->sum('expected_live')) }}.
                </p>
            </div>
        @endif

        {{-- The headline four: what was owed, what was found, and the gap. --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-stat label="Opening float" :value="money($shift->opening_float)" icon="coins" />

            <x-stat label="Expected in the drawer" :value="$amount($summary['expected_total'])" icon="clock"
                    :hint="$shift->isOpen() ? 'still moving' : 'at sign-off'" />

            <x-stat label="Counted" :value="$counted === null ? '-' : money($counted)" icon="check-circle"
                    :hint="$counted === null ? 'not counted while the drawer is open' : 'what was physically there'" />

            <x-stat label="Difference"
                    :value="$variance === null
                        ? '-'
                        : (abs($variance) < 0.005 ? 'Balanced' : ($variance > 0 ? 'Over' : 'Short') . ' ' . money(abs($variance)))"
                    :icon="$variance !== null && abs($variance) >= 0.005 ? 'alert-triangle' : 'check-circle'"
                    :hint="$variance === null ? 'nothing to compare yet' : 'counted less expected'" />
        </div>

        {{-- What was sold --}}
        <x-card :title="$sales . ' during this shift'" :padding="false">
            <x-table :zebra="false">
                @foreach ([
                    ['Transactions', $summary['sales_count'], false],
                    ['Gross (subtotal before discount)', $summary['subtotal'], true],
                    ['Discounts given', -$summary['discounts'], true],
                    ['Tax collected', $summary['tax'], true],
                    ['Total taken', $summary['gross'], true],
                    ['Refunds paid out', -$summary['refunds'], true],
                ] as [$label, $value, $isMoney])
                    <tr>
                        <td>{{ $label }}</td>
                        <td class="text-right tabular-nums">{{ $isMoney ? $amount($value) : $value }}</td>
                    </tr>
                @endforeach

                <tr>
                    <td>Voided {{ Str::lower($sales) }}</td>
                    <td class="text-right tabular-nums">
                        {{ $summary['voids_count'] }}
                        @if ($summary['voids_count'] > 0)
                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                {{ money($summary['voids']) }}, not counted as takings
                            </span>
                        @endif
                    </td>
                </tr>

                <x-slot:foot>
                    <tr>
                        <td>Net {{ Str::lower($sales) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($summary['net']) }}</td>
                    </tr>
                </x-slot:foot>
            </x-table>
        </x-card>

        {{-- The reconciliation itself --}}
        <x-card title="Drawer count" subtitle="Each tender is counted and reconciled on its own" :padding="false">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>Method</th>
                        <th class="text-right">Float</th>
                        <th class="text-right">{{ $sales }}</th>
                        <th class="text-right">Refunds</th>
                        <th class="text-right">Expected</th>
                        <th class="text-right">Counted</th>
                        <th class="text-right">Difference</th>
                    </tr>
                </x-slot:head>

                @foreach ($summary['methods'] as $row)
                    <tr>
                        <td class="font-medium">{{ $row['label'] }}</td>
                        <td class="text-right tabular-nums">{{ money($row['opening_float']) }}</td>
                        <td class="text-right tabular-nums">{{ money($row['sales']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount(-$row['refunds']) }}</td>
                        <td class="text-right font-semibold tabular-nums">{{ $amount($row['expected']) }}</td>
                        <td class="text-right tabular-nums">
                            {{ $row['counted'] === null ? 'not counted' : money($row['counted']) }}
                        </td>
                        <td class="text-right tabular-nums">
                            @include('shifts.partials.difference', ['value' => $row['variance']])
                        </td>
                    </tr>
                @endforeach

                <x-slot:foot>
                    <tr>
                        <td>Total</td>
                        <td class="text-right tabular-nums">{{ money($shift->opening_float) }}</td>
                        <td class="text-right tabular-nums">{{ money(collect($summary['methods'])->sum('sales')) }}</td>
                        <td class="text-right tabular-nums">{{ $amount(-collect($summary['methods'])->sum('refunds')) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($summary['expected_total']) }}</td>
                        <td class="text-right tabular-nums">
                            {{ $counted === null ? 'not counted' : money($counted) }}
                        </td>
                        <td class="text-right tabular-nums">
                            @include('shifts.partials.difference', ['value' => $variance])
                        </td>
                    </tr>
                </x-slot:foot>
            </x-table>

            @if ($variance !== null && abs($variance) >= 0.005)
                <x-slot:footer>
                    {{-- Named in words as well as coloured: a filed Z report is printed in black and white. --}}
                    <div class="flex items-start gap-3 {{ $variance > 0 ? 'text-blue-900 dark:text-blue-100' : 'text-red-900 dark:text-red-100' }}">
                        <span class="mt-0.5 shrink-0 {{ $variance > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-red-600 dark:text-red-400' }}">
                            <x-icon name="alert-triangle" size="5" />
                        </span>
                        <p>
                            The drawer is <strong>{{ $variance > 0 ? 'OVER' : 'SHORT' }}</strong> by
                            <strong class="tabular-nums">{{ money(abs($variance)) }}</strong>.
                            {{ $variance > 0
                                ? 'More money was counted than the records explain - usually a sale rung up wrong or change not given.'
                                : 'Less money was counted than the records explain - this is the figure to account for.' }}
                        </p>
                    </div>
                </x-slot:footer>
            @endif
        </x-card>

        {{-- Notes --}}
        @if ($shift->note || $shift->close_note)
            <x-card title="Notes">
                <dl class="space-y-2 text-sm">
                    @if ($shift->note)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">On opening</dt>
                            <dd class="mt-0.5">{{ $shift->note }}</dd>
                        </div>
                    @endif
                    @if ($shift->close_note)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">On closing</dt>
                            <dd class="mt-0.5">{{ $shift->close_note }}</dd>
                        </div>
                    @endif
                </dl>
            </x-card>
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
