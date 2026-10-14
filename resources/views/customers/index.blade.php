@extends('layouts.app')
@section('title', term('customer', true))

@php
    /*
    |--------------------------------------------------------------------------
    | The customer list
    |--------------------------------------------------------------------------
    |
    | Four tiles for what the shop is owed, one filter row above the table it
    | scopes, and the add/edit form in a modal so the list is never left.
    |
    | Flash messages and validation errors are printed by the shell, so this
    | page prints neither.
    */
    $noun = Str::lower(term('customer'));
    $nouns = Str::lower(term('customer', true));

    // The one filter-control class string every screen shares.
    $label = 'mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $control = 'w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm
                focus:border-primary-500 focus:ring-primary-500
                dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

    // The modal is a single Alpine form; this is the row it starts with. On a
    // validation failure the page comes back with old input, so the form reopens
    // on the row that failed instead of losing what was typed.
    $blank = ['id' => null, 'name' => '', 'phone' => '', 'email' => '', 'address' => '', 'credit_limit' => '0', 'is_active' => true, 'notes' => ''];
    $initial = array_replace($blank, array_intersect_key(old(), $blank));
    // An unticked checkbox is absent from old input, so only trust it when something was posted.
    $initial['is_active'] = old() ? (bool) old('is_active') : true;

    $sortUrl = fn($column) => route('customers.index', array_merge(request()->query(), [
        'sort' => $column,
        'direction' => $sort === $column && $direction === 'asc' ? 'desc' : 'asc',
    ]));
    // aria-sort tells a screen reader what the arrow is saying.
    $ariaSort = fn($column) => $sort === $column ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none';
    $arrow = fn($column) => $sort === $column ? ($direction === 'asc' ? '↑' : '↓') : '';

    $filters = ['balance' => 'With a balance', 'overdue' => 'Overdue', 'inactive' => 'Inactive'];
@endphp

@section('content')
    <div x-data="{ form: @js($initial) }">

        <x-page-header :title="term('customer', true)"
                       subtitle="Who buys on account, and what is still to collect.">
            <x-slot:actions>
                <button type="button"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
                        x-on:click="form = @js($initial); form.id = null; $dispatch('open-modal', 'customer-form')">
                    <x-icon name="plus" size="4" />
                    Add {{ $noun }}
                </button>
            </x-slot:actions>
        </x-page-header>

        {{-- What the shop is owed, at a glance --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-stat label="Active {{ $nouns }}" :value="number_format($summary['customers'])" icon="users" />

            <x-stat label="Owing" :value="number_format($summary['accounts'])" icon="receipt"
                    :hint="$summary['accounts'] === 0 ? 'nothing on account' : 'with a balance to pay'" />

            <x-stat label="Total outstanding" :value="money($summary['owed'])" icon="coins" />

            <x-stat label="Overdue" :value="money($summary['overdue'])" icon="alert-triangle"
                    :trend="$summary['overdue'] > 0 ? 'up' : null" invert
                    :hint="$summary['overdue'] > 0 ? 'past the agreed due date' : 'nothing past due'" />
        </div>

        {{-- One filter row, above the table it scopes --}}
        <form method="GET"
              class="mt-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-full sm:w-64">
                    <label for="filter-search" class="{{ $label }}">Search</label>
                    <input type="search" id="filter-search" name="search" value="{{ request('search') }}"
                           placeholder="Name, phone or email" class="{{ $control }}">
                </div>

                <div class="w-full sm:w-auto sm:min-w-44">
                    <label for="filter-filter" class="{{ $label }}">Show</label>
                    <select id="filter-filter" name="filter" class="{{ $control }}">
                        <option value="">All {{ $nouns }}</option>
                        @foreach ($filters as $value => $text)
                            <option value="{{ $value }}" @selected(request('filter') === $value)>{{ $text }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Carried so filtering does not silently reset the sort. --}}
                <input type="hidden" name="sort" value="{{ $sort }}">
                <input type="hidden" name="direction" value="{{ $direction }}">

                <div class="flex min-h-touch flex-wrap items-center gap-2">
                    <button type="submit"
                            class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                        <x-icon name="search" size="4" />
                        Filter
                    </button>

                    @if (request('search') || request('filter'))
                        <a href="{{ route('customers.index') }}"
                           class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                            Clear
                        </a>
                    @endif
                </div>
            </div>
        </form>

        <x-card :padding="false" class="mt-4">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th aria-sort="{{ $ariaSort('name') }}">
                            <a href="{{ $sortUrl('name') }}" class="inline-flex items-center gap-1 hover:underline">
                                Name
                                @if ($sort === 'name')
                                    <span aria-hidden="true">{{ $arrow('name') }}</span>
                                @endif
                            </a>
                        </th>
                        <th>Contact</th>
                        <th class="text-right">Credit limit</th>
                        <th class="text-right" aria-sort="{{ $ariaSort('balance') }}">
                            <a href="{{ $sortUrl('balance') }}" class="inline-flex items-center gap-1 hover:underline">
                                Balance
                                @if ($sort === 'balance')
                                    <span aria-hidden="true">{{ $arrow('balance') }}</span>
                                @endif
                            </a>
                        </th>
                        <th>Status</th>
                        <th><span class="sr-only">Actions</span></th>
                    </tr>
                </x-slot:head>

                @forelse ($customers as $customer)
                    <tr>
                        <td>
                            <a href="{{ route('customers.show', $customer) }}"
                               class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                {{ $customer->name }}
                            </a>
                        </td>
                        <td class="text-gray-500 dark:text-gray-400">
                            {{ $customer->phone ?: '—' }}
                            @if ($customer->email)
                                <span class="block text-xs">{{ $customer->email }}</span>
                            @endif
                        </td>
                        <td class="text-right tabular-nums">
                            {{-- 0 means the shop has not capped them. --}}
                            {{ (float) $customer->credit_limit > 0 ? money($customer->credit_limit) : 'No limit' }}
                        </td>
                        <td class="text-right font-semibold tabular-nums {{ $customer->outstanding > 0 ? 'text-orange-700 dark:text-orange-400' : 'text-gray-400 dark:text-gray-500' }}">
                            {{ $customer->outstanding > 0 ? money($customer->outstanding) : '—' }}
                        </td>
                        <td>
                            <div class="flex flex-wrap items-center gap-1">
                                @if (!$customer->is_active)
                                    <x-badge variant="gray" size="sm">Inactive</x-badge>
                                @endif
                                @if ($customer->overdue > 0)
                                    <x-badge variant="red" size="sm">{{ money($customer->overdue) }} overdue</x-badge>
                                @endif
                                @if ($customer->isOverLimit())
                                    <x-badge variant="amber" size="sm">Over limit</x-badge>
                                @endif
                                @if ($customer->is_active && $customer->overdue <= 0 && !$customer->isOverLimit())
                                    <x-badge variant="green" size="sm" dot>Active</x-badge>
                                @endif
                            </div>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <div class="flex items-center justify-end gap-3">
                                <button type="button" aria-label="Edit {{ $customer->name }}"
                                        class="inline-flex min-h-touch items-center font-medium text-primary-600 hover:underline dark:text-primary-400"
                                        x-on:click="form = @js([
                                            'id' => $customer->id,
                                            'name' => $customer->name,
                                            'phone' => $customer->phone,
                                            'email' => $customer->email,
                                            'address' => $customer->address,
                                            'credit_limit' => (float) $customer->credit_limit,
                                            'is_active' => (bool) $customer->is_active,
                                            'notes' => $customer->notes,
                                        ]); $dispatch('open-modal', 'customer-form')">
                                    Edit
                                </button>

                                <form method="POST" action="{{ route('customers.destroy', $customer) }}"
                                      onsubmit="return confirm('Delete {{ addslashes($customer->name) }}?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" aria-label="Delete {{ $customer->name }}"
                                            class="inline-flex min-h-touch items-center font-medium text-red-600 hover:underline dark:text-red-400">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-empty icon="users"
                                     title="No {{ $nouns }} found."
                                     :message="request('search') || request('filter')
                                        ? 'Nothing matches this filter. Clear it to see everyone on file.'
                                        : 'Add the people and businesses who buy from you to sell on account and keep a statement.'">
                                <x-slot:action>
                                    @if (request('search') || request('filter'))
                                        <a href="{{ route('customers.index') }}"
                                           class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                                            Clear the filter
                                        </a>
                                    @endif
                                    <button type="button"
                                            class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700"
                                            x-on:click="form = @js($initial); form.id = null; $dispatch('open-modal', 'customer-form')">
                                        <x-icon name="plus" size="4" />
                                        Add {{ $noun }}
                                    </button>
                                </x-slot:action>
                            </x-empty>
                        </td>
                    </tr>
                @endforelse
            </x-table>

            @if ($customers->hasPages())
                <x-slot:footer>{{ $customers->links() }}</x-slot:footer>
            @endif
        </x-card>

        {{-- Reopened automatically when validation sent us back, so nothing typed is lost. --}}
        <x-modal name="customer-form" :show="$errors->any()" focusable>
            @include('customers._form')
        </x-modal>
    </div>
@endsection
