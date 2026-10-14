@extends('layouts.app')

@section('title', term('supplier', true, 'Suppliers'))

@php
    /*
    |--------------------------------------------------------------------------
    | The supplier list
    |--------------------------------------------------------------------------
    |
    | What is owed per supplier, one filter row, and the add/edit forms in
    | modals so the list is never left.
    |
    | The balances are a page's worth of sums, not the whole book, so the tiles
    | that come from them say so.
    |
    | Flash messages and validation errors are printed by the shell.
    */
    $label = term('supplier', false, 'Supplier');
    $labelPlural = term('supplier', true, 'Suppliers');
    $noun = Str::lower($label);
    $nouns = Str::lower($labelPlural);

    $owed = collect($balances)->filter(fn($b) => $b > 0)->sum();
    $owedCount = collect($balances)->filter(fn($b) => $b > 0)->count();
    $credit = abs(collect($balances)->filter(fn($b) => $b < 0)->sum());

    // Reopen whichever modal was submitted when validation sends the user back.
    $reopen = $errors->any() ? old('_supplier_id') : null;

    // The one filter-control class string every screen shares.
    $fieldLabel = 'mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $control = 'w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm
                focus:border-primary-500 focus:ring-primary-500
                dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

    $modalPanel = 'w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-xl bg-white shadow-xl ring-1 ring-gray-200
                   dark:bg-gray-800 dark:ring-gray-700';
    $secondary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700';
    $primary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700';
@endphp

@section('content')
    <div x-data="{ addModal: {{ $reopen === 'new' ? 'true' : 'false' }}, editing: {{ $reopen && $reopen !== 'new' ? (int) $reopen : 'null' }} }">

        <x-page-header :title="$labelPlural" subtitle="Who the shop buys from, and what is still owed to them.">
            <x-slot:actions>
                <a href="{{ route('purchases.returns.index') }}" class="{{ $secondary }}">
                    <x-icon name="undo" size="4" />
                    Returns
                </a>

                <button type="button" @click="addModal = true" class="{{ $primary }}">
                    <x-icon name="plus" size="4" />
                    Add {{ $label }}
                </button>
            </x-slot:actions>
        </x-page-header>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-3">
            <x-stat label="{{ $labelPlural }}" :value="number_format($suppliers->total())" icon="truck"
                    :hint="request('search') || request('status') ? 'matching this filter' : 'on file'" />

            <x-stat label="Owed" :value="money($owed)" icon="coins"
                    :trend="$owed > 0 ? 'up' : null" invert
                    :hint="$owedCount > 0
                        ? 'across ' . number_format($owedCount) . ' ' . Str::lower(Str::plural($label, $owedCount)) . ' on this page'
                        : 'nothing owed on this page'" />

            <x-stat label="In credit" :value="money($credit)" icon="undo"
                    hint="paid ahead on this page" />
        </div>

        {{-- One filter row, above the table it scopes --}}
        <form method="GET"
              class="mt-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-full sm:w-64">
                    <label for="filter-search" class="{{ $fieldLabel }}">Search</label>
                    <input type="search" id="filter-search" name="search" value="{{ request('search') }}"
                           placeholder="Name, phone or email" class="{{ $control }}">
                </div>

                <div class="w-full sm:w-auto sm:min-w-44">
                    <label for="filter-status" class="{{ $fieldLabel }}">Status</label>
                    <select id="filter-status" name="status" class="{{ $control }}">
                        <option value="">All</option>
                        <option value="active" @selected(request('status') === 'active')>Active only</option>
                        <option value="inactive" @selected(request('status') === 'inactive')>Inactive only</option>
                    </select>
                </div>

                <div class="flex min-h-touch flex-wrap items-center gap-2">
                    <button type="submit" class="{{ $primary }}">
                        <x-icon name="search" size="4" />
                        Filter
                    </button>

                    @if (request('search') || request('status'))
                        <a href="{{ route('suppliers.index') }}" class="{{ $secondary }}">Reset</a>
                    @endif
                </div>
            </div>
        </form>

        {{-- List --}}
        <x-card :padding="false" class="mt-4">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>Name</th>
                        <th>Phone</th>
                        <th class="text-right">{{ term('purchase', true, 'Purchases') }}</th>
                        <th class="text-right">Balance</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </x-slot:head>

                @forelse ($suppliers as $supplier)
                    @php $balance = $balances[$supplier->id] ?? 0 @endphp
                    <tr>
                        <td>
                            <a href="{{ route('suppliers.show', $supplier) }}"
                               class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                {{ $supplier->name }}
                            </a>
                            @if ($supplier->email)
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $supplier->email }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $supplier->phone }}</td>
                        <td class="text-right tabular-nums">{{ number_format($supplier->purchases_count) }}</td>
                        <td class="text-right tabular-nums {{ $balance > 0 ? 'font-semibold text-red-700 dark:text-red-400' : 'text-gray-500 dark:text-gray-400' }}">
                            {{ money($balance) }}
                            @if ($balance < 0)
                                <span class="block text-xs text-green-700 dark:text-green-400">in credit</span>
                            @endif
                        </td>
                        <td>
                            @if ($supplier->is_active)
                                <x-badge variant="green" size="sm" dot>Active</x-badge>
                            @else
                                <x-badge variant="gray" size="sm">Inactive</x-badge>
                            @endif
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <div class="flex items-center justify-end gap-3">
                                <button type="button" @click="editing = {{ $supplier->id }}"
                                        aria-label="Edit {{ $supplier->name }}"
                                        class="inline-flex min-h-touch items-center font-medium text-primary-600 hover:underline dark:text-primary-400">
                                    Edit
                                </button>

                                <form action="{{ route('suppliers.destroy', $supplier) }}" method="POST"
                                      onsubmit="return confirm('{{ $supplier->purchases_count ? 'This supplier has purchases, so they will be deactivated instead of deleted. Continue?' : 'Delete this supplier?' }}');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            aria-label="{{ $supplier->purchases_count ? 'Deactivate' : 'Delete' }} {{ $supplier->name }}"
                                            class="inline-flex min-h-touch items-center font-medium text-red-600 hover:underline dark:text-red-400">
                                        {{ $supplier->purchases_count ? 'Deactivate' : 'Delete' }}
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-empty icon="truck" title="No {{ $nouns }} yet."
                                     :message="request('search') || request('status')
                                        ? 'Nothing matches this filter. Reset it to see everyone on file.'
                                        : 'Add the businesses you buy stock from to record deliveries and keep their account.'">
                                <x-slot:action>
                                    @if (request('search') || request('status'))
                                        <a href="{{ route('suppliers.index') }}" class="{{ $secondary }}">Reset the filter</a>
                                    @endif
                                    <button type="button" @click="addModal = true" class="{{ $primary }}">
                                        <x-icon name="plus" size="4" />
                                        Add {{ $label }}
                                    </button>
                                </x-slot:action>
                            </x-empty>
                        </td>
                    </tr>
                @endforelse
            </x-table>

            @if ($suppliers->hasPages())
                <x-slot:footer>{{ $suppliers->links() }}</x-slot:footer>
            @endif
        </x-card>

        {{-- Add --}}
        <div x-show="addModal" style="display: none" @keydown.escape.window="addModal = false"
             class="fixed inset-0 z-modal flex items-start justify-center overflow-y-auto bg-gray-900/60 p-4"
             role="dialog" aria-modal="true" aria-labelledby="supplier-modal-new-title">
            <div class="{{ $modalPanel }} my-8" @click.away="addModal = false">
                <form method="POST" action="{{ route('suppliers.store') }}">
                    @csrf

                    <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                        <h2 id="supplier-modal-new-title" class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            Add {{ $label }}
                        </h2>

                        <button type="button" @click="addModal = false" aria-label="Close"
                                class="-m-1.5 flex h-touch w-touch shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700">
                            <x-icon name="x" size="5" />
                        </button>
                    </div>

                    <div class="p-4 sm:p-5">
                        {{-- supplier => null explicitly: the table loop above leaves its own
                             $supplier behind in scope, and an @include inherits it, which
                             silently pre-filled this "add" form with the last row. --}}
                        @include('suppliers._form', ['supplier' => null])
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                        <button type="button" @click="addModal = false" class="{{ $secondary }}">Cancel</button>
                        <button type="submit" class="{{ $primary }}">
                            <x-icon name="check-circle" size="4" />
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{--
            One modal per row, server-rendered. A single shared modal filled in by
            JavaScript loses the user's typing the moment validation fails.
        --}}
        @foreach ($suppliers as $supplier)
            <div x-show="editing === {{ $supplier->id }}" style="display: none"
                 @keydown.escape.window="editing = null"
                 class="fixed inset-0 z-modal flex items-start justify-center overflow-y-auto bg-gray-900/60 p-4"
                 role="dialog" aria-modal="true" aria-labelledby="supplier-modal-{{ $supplier->id }}-title">
                <div class="{{ $modalPanel }} my-8" @click.away="editing = null">
                    <form method="POST" action="{{ route('suppliers.update', $supplier) }}">
                        @csrf
                        @method('PUT')

                        <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                            <h2 id="supplier-modal-{{ $supplier->id }}-title"
                                class="truncate text-base font-semibold text-gray-900 dark:text-gray-100">
                                Edit {{ $supplier->name }}
                            </h2>

                            <button type="button" @click="editing = null" aria-label="Close"
                                    class="-m-1.5 flex h-touch w-touch shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700">
                                <x-icon name="x" size="5" />
                            </button>
                        </div>

                        <div class="p-4 sm:p-5">
                            @include('suppliers._form', ['supplier' => $supplier])
                        </div>

                        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                            <button type="button" @click="editing = null" class="{{ $secondary }}">Cancel</button>
                            <button type="submit" class="{{ $primary }}">
                                <x-icon name="check-circle" size="4" />
                                Update
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
@endsection
