@extends('layouts.app')

@section('title', 'Daily Summary')

@php
    /*
    | Two time frames on purpose, and labelled as such:
    |
    |   the tiles      today and this month, the headline a shopkeeper asks for
    |   everything else the chosen date range, day by day, plus the till shifts
    |                  that made those days up
    |
    | The shift figures are ShiftService::summarise()'s, not this page's. Expected
    | versus counted is the one number that tells a mistake from theft, and it has
    | exactly one home.
    */
    $cashier = term('cashier');
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));

    $days = count($trend['labels']);
    $takings = array_sum($trend['values']);
    $tradingDays = count(array_filter($trend['values'], fn($v) => $v != 0));
@endphp

@section('content')
    <x-page-header title="Daily summary"
                   :subtitle="\Carbon\Carbon::parse($startDate)->format('j M Y') . ' to ' . \Carbon\Carbon::parse($endDate)->format('j M Y')" />

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat label="Takings today" :value="$amount($data['sales_today'])" icon="coins" hint="net of refunds" />
        <x-stat label="This month" :value="$amount($data['sales_this_month'])" icon="calendar" hint="net of refunds" />
        <x-stat label="{{ term('sale', true) }} today" :value="number_format($data['transactions_today'])" icon="receipt"
                hint="voided sales not counted" />
        <x-stat label="{{ term('product', true) }}" :value="number_format($data['total_products'])" icon="box"
                :href="feature('stock') ? route('reports.valuation') : null" />
    </div>

    @include('reports.partials.filter-bar', [
        'startDate' => $startDate,
        'endDate' => $endDate,
        'note' => 'The chart, the day-by-day table and the shift list below all cover the range chosen here. The tiles above are always today and this month.',
    ])

    <x-card title="Takings per day" :subtitle="$days . ' days in range'" class="mt-4">
        @if ($takings == 0)
            <x-empty icon="chart-line" title="Nothing taken in this period"
                     message="Choose a wider date range, or come back once the till has taken something." />
        @else
            <div class="h-72">
                <canvas id="daily-takings" role="img"
                        aria-label="Takings per day over the selected period"></canvas>
            </div>

            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                <span class="font-semibold">{{ $amount($takings) }}</span> over
                {{ $tradingDays }} trading {{ Str::plural('day', $tradingDays) }}
                ({{ $days }} in range), an average of
                {{ $amount($tradingDays > 0 ? $takings / $tradingDays : 0) }} a trading day.
            </p>
        @endif
    </x-card>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-5">
        {{-- How the money came in over the range --}}
        <x-card title="By payment method" :padding="false" class="lg:col-span-2">
            @php $takenTotal = array_sum(array_column($methods, 'taken')); @endphp

            <x-table :dense="true">
                <x-slot:head>
                    <tr>
                        <th>Method</th>
                        <th class="text-right">Taken</th>
                        <th class="text-right">Refunded</th>
                        <th class="text-right">Net</th>
                    </tr>
                </x-slot:head>

                @foreach ($methods as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="text-right tabular-nums">{{ $amount($row['taken']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($row['refunds']) }}</td>
                        <td class="text-right tabular-nums">{{ $amount($row['net']) }}</td>
                    </tr>
                @endforeach

                <x-slot:foot>
                    <tr>
                        <td>Total</td>
                        <td class="text-right tabular-nums">{{ $amount($takenTotal) }}</td>
                        <td class="text-right tabular-nums">{{ $amount(array_sum(array_column($methods, 'refunds'))) }}</td>
                        <td class="text-right tabular-nums">{{ $amount(array_sum(array_column($methods, 'net'))) }}</td>
                    </tr>
                </x-slot:foot>
            </x-table>
        </x-card>

        {{-- The chart's figures, exactly --}}
        <x-card title="Day by day" :padding="false" class="lg:col-span-3">
            <div class="max-h-96 overflow-y-auto">
                <x-table :dense="true">
                    <x-slot:head>
                        <tr>
                            <th>Date</th>
                            <th class="text-right">Takings</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($trend['labels'] as $i => $date)
                        <tr>
                            <td class="whitespace-nowrap">{{ \Carbon\Carbon::parse($date)->format('D j M Y') }}</td>
                            <td class="text-right tabular-nums {{ $trend['values'][$i] == 0 ? 'text-gray-400 dark:text-gray-500' : '' }}">
                                {{ $trend['values'][$i] == 0 ? '-' : $amount($trend['values'][$i]) }}
                            </td>
                        </tr>
                    @endforeach
                </x-table>
            </div>

            <x-slot:footer>
                {{ $amount($summary['takings']) }} taken across
                {{ number_format($summary['transactions']) }}
                {{ Str::lower(Str::plural(term('sale'), $summary['transactions'])) }}.
            </x-slot:footer>
        </x-card>
    </div>

    {{-- Shifts: the Z-report side of the day --}}
    <x-card title="Till shifts" subtitle="Expected against counted, per drawer" :padding="false" class="mt-4">
        <x-slot:actions>
            <a href="{{ route('shifts.index') }}"
               class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">All shifts</a>
        </x-slot:actions>

        <x-table>
            <x-slot:head>
                <tr>
                    <th>{{ $cashier }}</th>
                    <th>Opened</th>
                    <th>Closed</th>
                    <th class="text-right">{{ term('sale', true) }}</th>
                    <th class="text-right">Net taken</th>
                    <th class="text-right">Expected</th>
                    <th class="text-right">Counted</th>
                    <th class="text-right">Variance</th>
                    <th></th>
                </tr>
            </x-slot:head>

            @forelse ($shifts as $row)
                @php
                    $shift = $row['shift'];
                    $s = $row['summary'];
                    $variance = $s['variance_total'];
                @endphp
                <tr>
                    <td>
                        <span class="font-medium">{{ $shift->user->name ?? '-' }}</span>
                        @if ($shift->isOpen())
                            <x-badge variant="green" size="sm" dot class="ml-1">open</x-badge>
                        @endif
                    </td>
                    <td class="whitespace-nowrap text-gray-500 dark:text-gray-400">
                        {{ $shift->opened_at->format('d M, H:i') }}
                    </td>
                    <td class="whitespace-nowrap text-gray-500 dark:text-gray-400">
                        {{ $shift->closed_at ? $shift->closed_at->format('d M, H:i') : '-' }}
                    </td>
                    <td class="text-right tabular-nums">
                        {{ number_format($s['sales_count']) }}
                        @if ($s['voids_count'] > 0)
                            <span class="block text-xs text-red-700 dark:text-red-400">
                                {{ $s['voids_count'] }} voided
                            </span>
                        @endif
                    </td>
                    <td class="text-right tabular-nums">{{ $amount($s['net']) }}</td>
                    <td class="text-right tabular-nums">{{ $amount($s['expected_total']) }}</td>
                    <td class="text-right tabular-nums">
                        {{-- Null, not zero: an uncounted drawer has no figure, and
                             printing zero would read as "counted, and empty". --}}
                        {{ $s['counted_total'] === null ? '-' : $amount($s['counted_total']) }}
                    </td>
                    <td class="text-right tabular-nums">
                        @if ($variance === null)
                            <span class="text-gray-400 dark:text-gray-500">not counted</span>
                        @elseif ($variance == 0)
                            <x-badge variant="green" size="sm">balanced</x-badge>
                        @else
                            <x-badge :variant="$variance > 0 ? 'amber' : 'red'" size="sm">
                                {{ $variance > 0 ? 'over ' : 'short ' }}{{ money(abs($variance)) }}
                            </x-badge>
                        @endif
                    </td>
                    <td class="text-right">
                        <a href="{{ route('shifts.show', $shift) }}"
                           class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                            Z report
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        <x-empty icon="clock" title="No shifts in this period"
                                 message="A shift is opened with a cash float and closed with a counted drawer; the difference is the variance.">
                            <x-slot:action>
                                <a href="{{ route('shifts.index') }}"
                                   class="inline-flex min-h-touch items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                                    Shifts
                                </a>
                            </x-slot:action>
                        </x-empty>
                    </td>
                </tr>
            @endforelse
        </x-table>

        @if ($shifts->contains(fn($row) => $row['summary']['drifted']))
            <x-slot:footer>
                A shift marked as drifted was signed off, and something has changed since - a
                backdated sale, a void or a refund. The frozen figures are shown; open the
                Z report to see what the live ones say.
            </x-slot:footer>
        @endif
    </x-card>
@endsection

@push('scripts')
    @include('reports.partials.charts')

    <script>
        spChart('daily-takings', (t) => ({
            type: 'bar',
            data: {
                labels: @json(array_map(fn($d) => \Carbon\Carbon::parse($d)->format('j M'), $trend['labels'])),
                datasets: [spBar(t, 'Takings', @json($trend['values']), t.series[0])],
            },
            options: spAxes(t),
        }));
    </script>
@endpush
