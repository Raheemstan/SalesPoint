@extends('layouts.app')
@section('title', 'Shifts')

@php
    /*
    |--------------------------------------------------------------------------
    | Shifts
    |--------------------------------------------------------------------------
    |
    | The open drawer comes first: it is the thing a cashier is looking for, and
    | it carries the one figure they check against the cash in front of them. The
    | drawers other people have left open come next (a shift nobody closes is
    | never reconciled), then the history.
    |
    | Deliberately no "expected across every tender" total anywhere on this page:
    | cash, POS and transfer are counted separately and added up only at sign-off,
    | on the Z report.
    */
    $cashier = term('cashier');
    $cashierPlural = term('cashier', true);

    // The same controls the report screens use, so the filter row matches them.
    $fieldLabel = 'mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $control = 'w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm
                focus:border-primary-500 focus:ring-primary-500
                dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

    $btnPrimary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700';
    $btnSecondary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700';
    $btnDanger = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700';
    $linkInline = 'font-medium text-primary-600 hover:underline dark:text-primary-400';

    // The live difference pill in the count form, in x-badge's own colours. The
    // classes are written out per state: an interpolated name compiles to nothing.
    $pill = 'inline-flex items-center rounded-full px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset';
    $pillBalanced = 'bg-green-100 text-green-800 ring-green-200 dark:bg-green-900/50 dark:text-green-200 dark:ring-green-800';
    $pillOff = 'bg-red-100 text-red-800 ring-red-200 dark:bg-red-900/50 dark:text-red-200 dark:ring-red-800';

    // money() puts the symbol first, so a negative would read "<symbol>-100.00".
    // Expected cash really can go negative: a day of refunds empties the drawer.
    $amount = fn ($value) => ($value < 0 ? '-' : '') . money(abs($value));

    // So the browser's running total is formatted the way money() formats.
    $decimals = (int) setting('currency_decimals', config('sectors.currency.decimals'));
@endphp

@section('content')
    <x-page-header :title="$manager ? 'Shifts' : 'My shifts'"
                   subtitle="A drawer is opened with its float and closed by counting what is in it">
        @if ($open)
            <x-slot:actions>
                <a href="{{ route('shifts.show', $open) }}" class="{{ $btnSecondary }}">
                    <x-icon name="receipt" size="4" />
                    Preview the Z report
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- The open drawer: whose it is, what it should hold, and how it is closed. --}}
    @if ($open)
        @php $liveCash = $expected['cash']['expected']; @endphp

        <x-card>
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-3 lg:gap-6">
                <div>
                    <x-badge variant="green" size="sm" dot>Shift open</x-badge>

                    <p class="mt-2 text-base font-semibold text-gray-900 dark:text-gray-100">
                        {{ $open->user->name ?? '-' }}
                    </p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        since {{ $open->opened_at->format('d M Y H:i') }}
                    </p>
                    @if ($open->note)
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $open->note }}</p>
                    @endif

                    {{-- The same figure, in the same shape, as the dashboard's "My shift" card. --}}
                    <p class="mt-4 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Cash expected in the drawer
                    </p>
                    <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                        {{ $amount($liveCash) }}
                    </p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        float {{ money($expected['cash']['opening_float']) }}
                        + sales {{ money($expected['cash']['sales']) }}
                        &minus; refunds {{ money($expected['cash']['refunds']) }}
                    </p>
                </div>

                {{-- Close the drawer: the counted figures are the only money this page sends. --}}
                <form method="POST" action="{{ route('shifts.close', $open) }}"
                      class="border-t border-gray-200 pt-5 dark:border-gray-700 lg:col-span-2 lg:border-l lg:border-t-0 lg:pl-6 lg:pt-0"
                      x-data="{
                          counted: { cash: '', pos: '', transfer: '' },
                          expected: {
                              cash: {{ $expected['cash']['expected'] }},
                              pos: {{ $expected['pos']['expected'] }},
                              transfer: {{ $expected['transfer']['expected'] }}
                          },
                          diff(method) {
                              if (this.counted[method] === '') return null;
                              return Number(this.counted[method]) - this.expected[method];
                          },
                          money(value) {
                              return '{{ currency() }}' + Number(value).toLocaleString(undefined, {
                                  minimumFractionDigits: {{ $decimals }},
                                  maximumFractionDigits: {{ $decimals }},
                              });
                          },
                          label(value) {
                              if (value === null) return '';
                              if (Math.abs(value) < 0.005) return 'Balanced';
                              return (value > 0 ? 'Over ' : 'Short ') + this.money(Math.abs(value));
                          }
                      }">
                    @csrf

                    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                        Count the drawer and close the shift
                    </h2>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                        Enter what is physically there. What the drawer should hold is worked out again on the server.
                    </p>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        @foreach (\App\Models\Shift::METHODS as $method)
                            <div>
                                <label for="counted_{{ $method }}" class="{{ $fieldLabel }}">
                                    {{ \App\Models\Shift::methodLabel($method) }} counted ({{ currency() }})
                                </label>

                                <input type="number" step="0.01" min="0" required inputmode="decimal"
                                       id="counted_{{ $method }}" name="counted_{{ $method }}"
                                       x-model="counted.{{ $method }}"
                                       class="{{ $control }} text-right tabular-nums">

                                <p class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    <span>Expected {{ $amount($expected[$method]['expected']) }}</span>
                                    <span x-show="diff('{{ $method }}') !== null" x-cloak
                                          x-text="label(diff('{{ $method }}'))"
                                          class="{{ $pill }}"
                                          :class="Math.abs(diff('{{ $method }}') ?? 0) < 0.005
                                              ? '{{ $pillBalanced }}'
                                              : '{{ $pillOff }}'"></span>
                                </p>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4">
                        <label for="close_note" class="{{ $fieldLabel }}">Note (explain any difference)</label>
                        <textarea id="close_note" name="close_note" rows="2" maxlength="500"
                                  class="{{ $control }}">{{ old('close_note') }}</textarea>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <button type="submit" class="{{ $btnDanger }}">
                            <x-icon name="check-circle" size="4" />
                            Close shift
                        </button>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            A closed drawer cannot be counted again.
                        </p>
                    </div>
                </form>
            </div>
        </x-card>
    @else
        {{--
            Shared with the till, so it is not restyled here: see
            shifts/partials/no-open-shift.blade.php.
        --}}
        @include('shifts.partials.no-open-shift', ['openShift' => $open])
    @endif

    {{-- Drawers other people have left open. A shift nobody closes is never reconciled. --}}
    @if ($otherOpen->isNotEmpty())
        <x-card title="Other drawers still open" subtitle="Nothing is reconciled until these are counted"
                :padding="false" class="mt-4">
            <x-table :dense="true">
                <x-slot:head>
                    <tr>
                        <th>{{ $cashier }}</th>
                        <th>Open since</th>
                        <th class="text-right">Float</th>
                        <th><span class="sr-only">Z report</span></th>
                    </tr>
                </x-slot:head>

                @foreach ($otherOpen as $other)
                    <tr>
                        <td class="font-medium">{{ $other->user->name ?? '-' }}</td>
                        <td class="whitespace-nowrap text-gray-500 dark:text-gray-400">
                            {{ $other->opened_at->format('d M Y H:i') }}
                        </td>
                        <td class="text-right tabular-nums">{{ money($other->opening_float) }}</td>
                        <td class="text-right">
                            <a href="{{ route('shifts.show', $other) }}" class="{{ $linkInline }}">Z report</a>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    @endif

    {{-- One filter row, above the history it scopes. --}}
    <form method="GET" class="mt-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <div class="flex flex-wrap items-end gap-3">
            @if ($manager)
                <div class="w-full sm:w-auto">
                    <label for="shift-from" class="{{ $fieldLabel }}">From</label>
                    <input type="date" id="shift-from" name="from" value="{{ $from }}" class="{{ $control }}">
                </div>

                <div class="w-full sm:w-auto">
                    <label for="shift-to" class="{{ $fieldLabel }}">To</label>
                    <input type="date" id="shift-to" name="to" value="{{ $to }}" class="{{ $control }}">
                </div>

                <div class="w-full sm:w-auto sm:min-w-44">
                    <label for="shift-user" class="{{ $fieldLabel }}">{{ $cashier }}</label>
                    <select id="shift-user" name="user" class="{{ $control }}">
                        <option value="">All {{ $cashierPlural }}</option>
                        @foreach ($cashiers as $c)
                            <option value="{{ $c->id }}" @selected(request('user') == $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="w-full sm:w-auto sm:min-w-40">
                <label for="shift-status" class="{{ $fieldLabel }}">Status</label>
                <select id="shift-status" name="status" class="{{ $control }}">
                    <option value="">Any status</option>
                    <option value="open" @selected(request('status') === 'open')>Open</option>
                    <option value="closed" @selected(request('status') === 'closed')>Closed</option>
                </select>
            </div>

            <div class="flex min-h-touch flex-wrap items-center gap-2">
                <button type="submit" class="{{ $btnPrimary }}">
                    <x-icon name="search" size="4" />
                    Filter
                </button>

                @if (request()->hasAny(['from', 'to', 'user', 'status']))
                    <a href="{{ route('shifts.index') }}"
                       class="inline-flex min-h-touch items-center rounded-md px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        Reset
                    </a>
                @endif
            </div>
        </div>
    </form>

    <x-card title="Shift history"
            :subtitle="$manager
                ? \Carbon\Carbon::parse($from)->format('j M Y') . ' to ' . \Carbon\Carbon::parse($to)->format('j M Y')
                : 'Every drawer you have opened'"
            :padding="false" class="mt-4">
        <x-table>
            <x-slot:head>
                <tr>
                    <th>{{ $cashier }}</th>
                    <th>Opened</th>
                    <th>Closed</th>
                    <th class="text-right">Float</th>
                    <th class="text-right">Expected</th>
                    <th class="text-right">Counted</th>
                    <th class="text-right">Difference</th>
                    <th><span class="sr-only">Z report</span></th>
                </tr>
            </x-slot:head>

            @forelse ($shifts as $row)
                @php $variance = $row->variance_total; @endphp
                <tr>
                    <td class="font-medium">{{ $row->user->name ?? '-' }}</td>
                    <td class="whitespace-nowrap">{{ $row->opened_at->format('d M Y H:i') }}</td>
                    <td class="whitespace-nowrap">
                        @if ($row->isOpen())
                            <x-badge variant="green" size="sm" dot>Open</x-badge>
                        @else
                            {{ $row->closed_at?->format('d M Y H:i') }}
                        @endif
                    </td>
                    <td class="text-right tabular-nums">{{ money($row->opening_float) }}</td>
                    <td class="text-right tabular-nums">
                        {{ $row->isOpen() ? '-' : $amount($row->expected_total) }}
                    </td>
                    <td class="text-right tabular-nums">
                        {{ $row->counted_total === null ? '-' : money($row->counted_total) }}
                    </td>
                    <td class="text-right tabular-nums">
                        {{-- Over and short are named in words as well as coloured. --}}
                        @if ($variance === null)
                            <span class="text-gray-400 dark:text-gray-500">-</span>
                        @elseif (abs($variance) < 0.005)
                            <x-badge variant="green" size="sm">Balanced</x-badge>
                        @else
                            <x-badge :variant="$variance > 0 ? 'blue' : 'red'" size="sm">{{ $variance > 0 ? 'Over' : 'Short' }} {{ money(abs($variance)) }}</x-badge>
                        @endif
                    </td>
                    <td class="text-right">
                        <a href="{{ route('shifts.show', $row) }}" class="{{ $linkInline }}">Z report</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-empty icon="clock" title="No shifts to show"
                                 :message="$manager
                                    ? 'Try a wider date range, or a different status.'
                                    : 'Every drawer you open and close is listed here.'" />
                    </td>
                </tr>
            @endforelse
        </x-table>

        @if ($shifts->hasPages())
            <x-slot:footer>{{ $shifts->links() }}</x-slot:footer>
        @endif
    </x-card>
@endsection
