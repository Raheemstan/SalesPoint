@extends('layouts.app')
@section('title', term('customer', true))

@php
    $noun = strtolower(term('customer'));
    $nouns = strtolower(term('customer', true));
    $input = 'rounded-md border border-gray-300 dark:border-gray-600 px-2 py-1 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100';

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
    $arrow = fn($column) => $sort === $column ? ($direction === 'asc' ? ' ↑' : ' ↓') : '';
@endphp

@section('content')
    <div x-data="{ form: @js($initial) }" class="space-y-6">

        {{-- What the shop is owed, at a glance --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <p class="text-xs text-gray-500">Active {{ $nouns }}</p>
                <p class="text-xl font-bold">{{ number_format($summary['customers']) }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <p class="text-xs text-gray-500">Owing</p>
                <p class="text-xl font-bold">{{ number_format($summary['accounts']) }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <p class="text-xs text-gray-500">Total outstanding</p>
                <p class="text-xl font-bold">{{ money($summary['owed']) }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <p class="text-xs text-gray-500">Overdue</p>
                <p class="text-xl font-bold {{ $summary['overdue'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                    {{ money($summary['overdue']) }}
                </p>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded shadow p-4 text-sm">
            <div class="flex flex-col md:flex-row justify-between md:items-end gap-4 mb-4">
                <form method="GET" class="flex flex-wrap items-end gap-2">
                    <input type="text" name="search" value="{{ request('search') }}"
                           placeholder="Name, phone or email" class="{{ $input }}">
                    <select name="filter" class="{{ $input }}">
                        <option value="">All {{ $nouns }}</option>
                        @foreach (['balance' => 'With a balance', 'overdue' => 'Overdue', 'inactive' => 'Inactive'] as $value => $label)
                            <option value="{{ $value }}" @selected(request('filter') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <input type="hidden" name="sort" value="{{ $sort }}">
                    <input type="hidden" name="direction" value="{{ $direction }}">
                    <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded">Filter</button>
                    @if (request('search') || request('filter'))
                        <a href="{{ route('customers.index') }}" class="px-3 py-1.5 rounded border border-gray-300 dark:border-gray-600">Clear</a>
                    @endif
                </form>

                <button type="button" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded self-start"
                        x-on:click="form = @js($initial); form.id = null; $dispatch('open-modal', 'customer-form')">
                    + Add {{ $noun }}
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-gray-100 dark:bg-gray-700">
                            <th class="p-2"><a href="{{ $sortUrl('name') }}" class="hover:underline">Name{{ $arrow('name') }}</a></th>
                            <th class="p-2">Contact</th>
                            <th class="p-2 text-right">Credit limit</th>
                            <th class="p-2 text-right"><a href="{{ $sortUrl('balance') }}" class="hover:underline">Balance{{ $arrow('balance') }}</a></th>
                            <th class="p-2">Status</th>
                            <th class="p-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($customers as $customer)
                            <tr class="border-b border-gray-200 dark:border-gray-600">
                                <td class="p-2">
                                    <a href="{{ route('customers.show', $customer) }}" class="text-blue-600 hover:underline font-medium">{{ $customer->name }}</a>
                                </td>
                                <td class="p-2 text-gray-500">
                                    {{ $customer->phone ?: '—' }}
                                    @if ($customer->email)
                                        <span class="block text-xs">{{ $customer->email }}</span>
                                    @endif
                                </td>
                                <td class="p-2 text-right">{{ (float) $customer->credit_limit > 0 ? money($customer->credit_limit) : 'No limit' }}</td>
                                <td class="p-2 text-right font-semibold {{ $customer->outstanding > 0 ? 'text-orange-600 dark:text-orange-400' : 'text-gray-400' }}">
                                    {{ $customer->outstanding > 0 ? money($customer->outstanding) : '—' }}
                                </td>
                                <td class="p-2 space-x-1 whitespace-nowrap">
                                    @if ($customer->overdue > 0)
                                        <span class="px-2 py-0.5 rounded text-xs bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                            {{ money($customer->overdue) }} overdue
                                        </span>
                                    @endif
                                    @if ($customer->isOverLimit())
                                        <span class="px-2 py-0.5 rounded text-xs bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">Over limit</span>
                                    @endif
                                    @if (!$customer->is_active)
                                        <span class="px-2 py-0.5 rounded text-xs bg-gray-200 text-gray-700 dark:bg-gray-600 dark:text-gray-200">Inactive</span>
                                    @endif
                                </td>
                                <td class="p-2 whitespace-nowrap space-x-2">
                                    <button type="button" class="text-blue-600 hover:underline"
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
                                    <form method="POST" action="{{ route('customers.destroy', $customer) }}" class="inline"
                                          onsubmit="return confirm('Delete {{ addslashes($customer->name) }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:underline">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-4 text-center text-gray-500">No {{ $nouns }} found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $customers->links() }}</div>
        </div>

        {{-- Reopened automatically when validation sent us back, so nothing typed is lost. --}}
        <x-modal name="customer-form" :show="$errors->any()" focusable>
            @include('customers._form')
        </x-modal>
    </div>
@endsection
