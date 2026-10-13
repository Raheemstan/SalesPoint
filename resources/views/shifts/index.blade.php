@extends('layouts.app')
@section('title', 'Shifts')

@php
    $input = 'rounded-md border border-gray-300 dark:border-gray-600 px-2 py-1 bg-white dark:bg-gray-700';
    $cashier = term('cashier');

    // money() puts the symbol first, so a negative would read "<symbol>-100.00".
    // Expected cash really can go negative: a day of refunds empties the drawer.
    $amount = fn ($value) => ($value < 0 ? '-' : '') . money(abs($value));
@endphp

@section('content')
    <div class="space-y-6">

        {{-- The open drawer comes first: it is the thing a cashier is looking for. --}}
        @if ($open)
            @php $liveCash = $expected['cash']['expected']; @endphp

            <div class="bg-white dark:bg-gray-800 rounded shadow p-4 border-l-4 border-green-500">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Shift open</p>
                        <p class="text-lg font-semibold text-gray-800 dark:text-gray-100">
                            {{ $open->user->name ?? '-' }}
                            <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                                since {{ $open->opened_at->format('d M Y H:i') }}
                            </span>
                        </p>
                        @if ($open->note)
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $open->note }}</p>
                        @endif
                    </div>

                    <div class="text-right">
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Cash expected in the drawer</p>
                        <p class="text-3xl font-bold text-green-700 dark:text-green-300">{{ $amount($liveCash) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            float {{ money($expected['cash']['opening_float']) }}
                            + sales {{ money($expected['cash']['sales']) }}
                            &minus; refunds {{ money($expected['cash']['refunds']) }}
                        </p>
                    </div>
                </div>

                {{-- Close the drawer: the counted figures are the only money this page sends. --}}
                <form method="POST" action="{{ route('shifts.close', $open) }}" class="mt-5 border-t dark:border-gray-700 pt-4"
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
                              return '{{ currency() }}' + Number(value).toFixed(2);
                          },
                          label(value) {
                              if (value === null) return '';
                              if (Math.abs(value) < 0.005) return 'balanced';
                              return (value > 0 ? 'over ' : 'short ') + this.money(Math.abs(value));
                          }
                      }">
                    @csrf

                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-3">Count the drawer and close the shift</p>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        @foreach (\App\Models\Shift::METHODS as $method)
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-gray-400">
                                    {{ \App\Models\Shift::methodLabel($method) }} counted ({{ currency() }})
                                    <input type="number" step="0.01" min="0" required
                                           name="counted_{{ $method }}" x-model="counted.{{ $method }}"
                                           class="mt-1 block w-full {{ $input }}">
                                </label>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    Expected {{ $amount($expected[$method]['expected']) }}
                                    <span x-show="diff('{{ $method }}') !== null"
                                          x-text="'· ' + label(diff('{{ $method }}'))"
                                          :class="Math.abs(diff('{{ $method }}') ?? 0) < 0.005
                                              ? 'text-green-600 dark:text-green-400'
                                              : 'text-red-600 dark:text-red-400'"></span>
                                </p>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4">
                        <label class="block text-xs text-gray-500 dark:text-gray-400">
                            Note (explain any difference)
                            <textarea name="close_note" rows="2" maxlength="500"
                                      class="mt-1 block w-full {{ $input }}">{{ old('close_note') }}</textarea>
                        </label>
                    </div>

                    <div class="mt-4 flex items-center gap-4">
                        <button class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">Close shift</button>
                        <a href="{{ route('shifts.show', $open) }}" class="text-blue-600 hover:underline text-sm">Preview the Z report</a>
                    </div>
                </form>
            </div>
        @else
            @include('shifts.partials.no-open-shift', ['openShift' => $open])
        @endif

        {{-- Drawers other people have left open. A shift nobody closes is never reconciled. --}}
        @if ($otherOpen->isNotEmpty())
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-2">Other drawers still open</p>
                <ul class="text-sm space-y-1">
                    @foreach ($otherOpen as $other)
                        <li>
                            <a href="{{ route('shifts.show', $other) }}" class="text-blue-600 hover:underline">
                                {{ $other->user->name ?? '-' }}
                            </a>
                            <span class="text-gray-500 dark:text-gray-400">
                                since {{ $other->opened_at->format('d M H:i') }} · float {{ money($other->opening_float) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="bg-white dark:bg-gray-800 rounded shadow p-4 text-sm">
            <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100 mb-4">
                {{ $manager ? 'Shifts' : 'My shifts' }}
            </h2>

            <form method="GET" class="flex flex-wrap gap-3 mb-6 items-end">
                @if ($manager)
                    <label class="text-xs text-gray-500 dark:text-gray-400">From
                        <input type="date" name="from" value="{{ $from }}" class="{{ $input }}"></label>
                    <label class="text-xs text-gray-500 dark:text-gray-400">To
                        <input type="date" name="to" value="{{ $to }}" class="{{ $input }}"></label>
                    <select name="user" class="{{ $input }}">
                        <option value="">All {{ Str::plural($cashier) }}</option>
                        @foreach ($cashiers as $c)
                            <option value="{{ $c->id }}" @selected(request('user') == $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                @endif

                <select name="status" class="{{ $input }}">
                    <option value="">Any status</option>
                    <option value="open" @selected(request('status') === 'open')>Open</option>
                    <option value="closed" @selected(request('status') === 'closed')>Closed</option>
                </select>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded">Filter</button>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-gray-100 dark:bg-gray-700">
                            <th class="p-2">{{ $cashier }}</th>
                            <th class="p-2">Opened</th>
                            <th class="p-2">Closed</th>
                            <th class="p-2 text-right">Float</th>
                            <th class="p-2 text-right">Expected</th>
                            <th class="p-2 text-right">Counted</th>
                            <th class="p-2 text-right">Difference</th>
                            <th class="p-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($shifts as $row)
                            @php $variance = $row->variance_total; @endphp
                            <tr class="border-b border-gray-200 dark:border-gray-600">
                                <td class="p-2">{{ $row->user->name ?? '-' }}</td>
                                <td class="p-2">{{ $row->opened_at->format('d M Y H:i') }}</td>
                                <td class="p-2">
                                    @if ($row->isOpen())
                                        <span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Open</span>
                                    @else
                                        {{ $row->closed_at?->format('d M Y H:i') }}
                                    @endif
                                </td>
                                <td class="p-2 text-right">{{ money($row->opening_float) }}</td>
                                <td class="p-2 text-right">{{ $row->isOpen() ? '-' : $amount($row->expected_total) }}</td>
                                <td class="p-2 text-right">{{ $row->counted_total === null ? '-' : money($row->counted_total) }}</td>
                                <td class="p-2 text-right">
                                    @if ($variance === null)
                                        -
                                    @elseif (abs($variance) < 0.005)
                                        <span class="text-green-700 dark:text-green-300">Balanced</span>
                                    @else
                                        <span class="text-red-600 dark:text-red-400">
                                            {{ $variance > 0 ? 'Over' : 'Short' }} {{ money(abs($variance)) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="p-2">
                                    <a href="{{ route('shifts.show', $row) }}" class="text-blue-600 hover:underline">Z report</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="p-4 text-center text-gray-500 dark:text-gray-400">No shifts found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $shifts->links() }}</div>
        </div>
    </div>
@endsection
