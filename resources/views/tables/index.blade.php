@extends('layouts.app')
@section('title', term('table', true, 'Tables'))

@php
    /*
    |--------------------------------------------------------------------------
    | The floor view - the home screen of a restaurant install
    |--------------------------------------------------------------------------
    |
    | Read at arm's length, on a tablet, while walking. That is the whole brief,
    | and it is why this is a grid of big cards rather than a table of rows: a
    | server wants to know which tables are free and which have been sitting too
    | long, and both have to be answerable without reading any text.
    |
    | Colour carries the state (green free, amber occupied) and so does a word,
    | because colour alone is no good to a colour-blind server in a dark room.
    | Every card is a single large tap target - nothing here needs a mouse, and
    | nothing is revealed by hovering.
    */
    $server = term('cashier');
    $guestWord = fn (int $n) => lcfirst(term('customer', $n !== 1));

    // Tables grouped the way the room is laid out. Null area last, under a
    // neutral heading, so a shop that never sets areas sees one plain grid.
    $grouped = $tables->groupBy(fn ($table) => $table->area ?: '');
@endphp

@section('content')
    <x-page-header :title="term('table', true, 'Tables')"
                   :subtitle="$summary['occupied'] . ' of ' . $summary['tables'] . ' occupied · ' . $summary['guests'] . ' ' . $guestWord($summary['guests']) . ' seated'">
        @if ($manager)
            <x-slot:actions>
                <button type="button"
                        @click="$dispatch('manage-table', { mode: 'create' })"
                        class="inline-flex min-h-touch items-center gap-2 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white
                               hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2
                               dark:focus:ring-offset-gray-900">
                    <x-icon name="plus" size="5" />
                    Add {{ lcfirst(term('table', false, 'Table')) }}
                </button>
            </x-slot:actions>
        @endif
    </x-page-header>

    <div class="space-y-6"
         x-data="{
             seat: { name: '', url: '', seats: 2 },
             openSeat(name, url, seats) {
                 this.seat = { name: name, url: url, seats: seats };
                 $dispatch('open-modal', 'seat-table');
             }
         }">

        {{-- What the floor is worth right now: the one figure a manager asks for. --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <x-stat label="Occupied" :value="$summary['occupied'] . ' / ' . $summary['tables']" icon="building" />
            <x-stat :label="ucfirst($guestWord(2)) . ' seated'" :value="$summary['guests']" icon="users" />
            <x-stat label="Open bills" :value="money($summary['open_total'])" icon="coins" />
            <x-stat label="Free" :value="$summary['tables'] - $summary['occupied']" icon="check-circle" />
        </div>

        @if ($tables->isEmpty())
            <x-card>
                <x-empty icon="building"
                         :title="'No ' . lcfirst(term('table', true, 'tables')) . ' yet'"
                         :message="$manager
                            ? 'Add the tables in your room and they will appear here as a floor plan.'
                            : 'A manager needs to add the tables before orders can be opened.'">
                    @if ($manager)
                        <x-slot:action>
                            <button type="button"
                                    @click="$dispatch('manage-table', { mode: 'create' })"
                                    class="inline-flex min-h-touch items-center gap-2 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700">
                                <x-icon name="plus" size="5" />
                                Add {{ lcfirst(term('table', false, 'Table')) }}
                            </button>
                        </x-slot:action>
                    @endif
                </x-empty>
            </x-card>
        @endif

        @foreach ($grouped as $area => $areaTables)
            <div>
                @if ($area !== '' || $grouped->count() > 1)
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ $area !== '' ? $area : 'Unassigned' }}
                    </h2>
                @endif

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                    @foreach ($areaTables as $table)
                        @php
                            $order = $table->openOrder;
                            $total = $totals[$table->id] ?? 0;
                        @endphp

                        <div class="relative rounded-xl bg-white shadow-sm ring-1 dark:bg-gray-800
                                    {{ $order
                                        ? 'ring-amber-300 dark:ring-amber-700/70'
                                        : ($table->is_active ? 'ring-green-300 dark:ring-green-800' : 'ring-gray-200 dark:ring-gray-700') }}">

                            @if ($order)
                                {{-- Occupied: the whole card opens the order. --}}
                                <a href="{{ route('table-orders.show', $order) }}"
                                   class="block min-h-touch rounded-xl p-3 focus:outline-none focus:ring-2 focus:ring-primary-500">
                                    <div class="flex items-start justify-between gap-2">
                                        <span class="truncate text-lg font-bold text-gray-900 dark:text-gray-100">{{ $table->name }}</span>
                                        <x-badge variant="amber" dot>{{ $order->seatedFor() }}</x-badge>
                                    </div>

                                    <p class="mt-2 text-2xl font-semibold tabular-nums tracking-tight text-gray-900 dark:text-gray-100">
                                        {{ money($total) }}
                                    </p>

                                    <dl class="mt-1 space-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                                        <div class="flex items-center gap-1">
                                            <dt class="sr-only">{{ ucfirst($guestWord(2)) }}</dt>
                                            <x-icon name="users" size="3" />
                                            <dd>{{ $order->guests }} {{ $guestWord($order->guests) }}</dd>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <dt class="sr-only">{{ $server }}</dt>
                                            <x-icon name="user" size="3" />
                                            <dd class="truncate">{{ $order->user->name ?? '-' }}</dd>
                                        </div>
                                    </dl>
                                </a>
                            @elseif ($table->is_active)
                                {{-- Free: tapping it asks how many are sitting down. --}}
                                <button type="button"
                                        @click="openSeat(@js($table->name), '{{ route('table-orders.store', $table) }}', {{ (int) $table->seats }})"
                                        class="block w-full min-h-touch rounded-xl p-3 text-left focus:outline-none focus:ring-2 focus:ring-primary-500">
                                    <div class="flex items-start justify-between gap-2">
                                        <span class="truncate text-lg font-bold text-gray-900 dark:text-gray-100">{{ $table->name }}</span>
                                        <x-badge variant="green" dot>Free</x-badge>
                                    </div>

                                    <p class="mt-2 flex items-center gap-1 text-sm font-medium text-primary-700 dark:text-primary-300">
                                        <x-icon name="plus" size="4" />
                                        Open {{ lcfirst(term('sale')) }}
                                    </p>

                                    <p class="mt-1 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                        <x-icon name="users" size="3" />
                                        Seats {{ $table->seats }}
                                    </p>
                                </button>
                            @else
                                <div class="p-3 opacity-60">
                                    <div class="flex items-start justify-between gap-2">
                                        <span class="truncate text-lg font-bold text-gray-900 dark:text-gray-100">{{ $table->name }}</span>
                                        <x-badge variant="gray">Out of service</x-badge>
                                    </div>
                                    <p class="mt-2 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                        <x-icon name="users" size="3" />
                                        Seats {{ $table->seats }}
                                    </p>
                                </div>
                            @endif

                            @if ($manager)
                                {{--
                                    Always visible, never hover-only: on a touchscreen
                                    there is no hover, so a hidden edit affordance is
                                    simply an edit affordance that does not exist.
                                --}}
                                <button type="button"
                                        @click.stop="$dispatch('manage-table', {
                                            mode: 'edit',
                                            url: '{{ route('tables.update', $table) }}',
                                            destroy: '{{ route('tables.destroy', $table) }}',
                                            name: @js($table->name),
                                            area: @js($table->area),
                                            seats: {{ (int) $table->seats }},
                                            sort_order: {{ (int) $table->sort_order }},
                                            is_active: {{ $table->is_active ? 'true' : 'false' }}
                                        })"
                                        class="absolute bottom-1.5 right-1.5 inline-flex h-9 w-9 items-center justify-center rounded-lg
                                               text-gray-400 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2
                                               focus:ring-primary-500 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                        aria-label="Edit {{ $table->name }}">
                                    <x-icon name="cog" size="5" />
                                </button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        {{-- Seat guests. One modal for every free table; Alpine fills in which. --}}
        <x-modal name="seat-table" maxWidth="sm" focusable>
            <form method="POST" :action="seat.url" class="p-5">
                @csrf

                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Open <span x-text="seat.name"></span>
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    How many are sitting down?
                </p>

                <label class="mt-4 block text-sm font-medium text-gray-700 dark:text-gray-200">
                    {{ ucfirst($guestWord(2)) }}
                    <input type="number" name="guests" min="1" max="99" required
                           x-model="seat.seats"
                           class="mt-1 block w-full min-h-touch rounded-lg border-gray-300 text-lg dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                </label>

                <label class="mt-3 block text-sm font-medium text-gray-700 dark:text-gray-200">
                    Note <span class="font-normal text-gray-500 dark:text-gray-400">(optional)</span>
                    <input type="text" name="note" maxlength="500"
                           placeholder="Birthday, high chair, allergy..."
                           class="mt-1 block w-full min-h-touch rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                </label>

                <div class="mt-5 flex gap-2">
                    <button type="button" @click="$dispatch('close-modal', 'seat-table')"
                            class="min-h-touch flex-1 rounded-lg bg-gray-100 px-4 text-sm font-semibold text-gray-700
                                   hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                        Cancel
                    </button>
                    <button class="min-h-touch flex-1 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700">
                        Open {{ lcfirst(term('sale')) }}
                    </button>
                </div>
            </form>
        </x-modal>

        @if ($manager)
            @include('tables._form')
        @endif
    </div>
@endsection
