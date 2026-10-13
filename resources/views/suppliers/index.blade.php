@extends('layouts.app')

@section('title', term('supplier', true, 'Suppliers'))

@section('content')
    @php
        $label = term('supplier', false, 'Supplier');
        $labelPlural = term('supplier', true, 'Suppliers');
        $owed = collect($balances)->filter(fn($b) => $b > 0)->sum();

        // Reopen whichever modal was submitted when validation sends the user back.
        $reopen = $errors->any() ? old('_supplier_id') : null;
    @endphp

    <div x-data="{ addModal: {{ $reopen === 'new' ? 'true' : 'false' }}, editing: {{ $reopen && $reopen !== 'new' ? (int) $reopen : 'null' }} }" class="space-y-4">

        {{-- Header --}}
        <div class="bg-white dark:bg-gray-800 rounded shadow p-4 flex flex-wrap justify-between items-center gap-3">
            <div>
                <h2 class="text-lg font-semibold">{{ $labelPlural }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Owed across this page: <strong>{{ money($owed) }}</strong>
                </p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('purchases.returns.index') }}"
                   class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm">Returns</a>
                <button type="button" @click="addModal = true"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">
                    + Add {{ $label }}
                </button>
            </div>
        </div>

        {{-- Filters --}}
        <form method="GET" class="bg-white dark:bg-gray-800 rounded shadow p-4 grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Name, phone or email"
                       class="w-full rounded border border-gray-300 dark:border-gray-600 px-3 py-2 bg-white dark:bg-gray-700">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Status</label>
                <select name="status" class="w-full rounded border border-gray-300 dark:border-gray-600 px-3 py-2 bg-white dark:bg-gray-700">
                    <option value="">All</option>
                    <option value="active" @selected(request('status') === 'active')>Active only</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactive only</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">Filter</button>
                <a href="{{ route('suppliers.index') }}" class="px-4 py-2 text-sm text-gray-500">Reset</a>
            </div>
        </form>

        {{-- List --}}
        <div class="bg-white dark:bg-gray-800 rounded shadow p-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-100 dark:bg-gray-700 text-left">
                    <tr>
                        <th class="p-2">Name</th>
                        <th class="p-2">Phone</th>
                        <th class="p-2 text-right">{{ term('purchase', true, 'Purchases') }}</th>
                        <th class="p-2 text-right">Balance</th>
                        <th class="p-2">Status</th>
                        <th class="p-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($suppliers as $supplier)
                        @php $balance = $balances[$supplier->id] ?? 0 @endphp
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <td class="p-2">
                                <a href="{{ route('suppliers.show', $supplier) }}" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                    {{ $supplier->name }}
                                </a>
                                @if ($supplier->email)
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $supplier->email }}</div>
                                @endif
                            </td>
                            <td class="p-2">{{ $supplier->phone }}</td>
                            <td class="p-2 text-right">{{ $supplier->purchases_count }}</td>
                            <td class="p-2 text-right {{ $balance > 0 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-500 dark:text-gray-400' }}">
                                {{ money($balance) }}
                                @if ($balance < 0)
                                    <div class="text-xs text-green-600 dark:text-green-400">in credit</div>
                                @endif
                            </td>
                            <td class="p-2">
                                @if ($supplier->is_active)
                                    <span class="text-xs px-2 py-1 rounded bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Active</span>
                                @else
                                    <span class="text-xs px-2 py-1 rounded bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">Inactive</span>
                                @endif
                            </td>
                            <td class="p-2 text-right whitespace-nowrap">
                                <button type="button" @click="editing = {{ $supplier->id }}"
                                        class="text-yellow-600 dark:text-yellow-400 hover:underline">Edit</button>
                                <form action="{{ route('suppliers.destroy', $supplier) }}" method="POST" class="inline"
                                      onsubmit="return confirm('{{ $supplier->purchases_count ? 'This supplier has purchases, so they will be deactivated instead of deleted. Continue?' : 'Delete this supplier?' }}');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="ml-2 text-red-600 dark:text-red-400 hover:underline">
                                        {{ $supplier->purchases_count ? 'Deactivate' : 'Delete' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-4 text-center text-gray-500 dark:text-gray-400">
                                No {{ Str::lower($labelPlural) }} yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div class="mt-4">{{ $suppliers->links() }}</div>
        </div>

        {{-- Add --}}
        <div x-show="addModal" style="display: none" class="fixed inset-0 bg-black bg-opacity-50 flex items-start justify-center z-50 p-4 overflow-y-auto">
            <div class="bg-white dark:bg-gray-800 rounded shadow p-6 w-full max-w-2xl my-8" @click.away="addModal = false">
                <h3 class="text-lg font-semibold mb-4">Add {{ $label }}</h3>
                <form method="POST" action="{{ route('suppliers.store') }}">
                    @csrf
                    @include('suppliers._form')
                    <div class="flex justify-end gap-2 mt-4">
                        <button type="button" @click="addModal = false"
                                class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600">Cancel</button>
                        <button type="submit" class="px-4 py-2 rounded bg-blue-600 hover:bg-blue-700 text-white">Save</button>
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
                 class="fixed inset-0 bg-black bg-opacity-50 flex items-start justify-center z-50 p-4 overflow-y-auto">
                <div class="bg-white dark:bg-gray-800 rounded shadow p-6 w-full max-w-2xl my-8" @click.away="editing = null">
                    <h3 class="text-lg font-semibold mb-4">Edit {{ $supplier->name }}</h3>
                    <form method="POST" action="{{ route('suppliers.update', $supplier) }}">
                        @csrf
                        @method('PUT')
                        @include('suppliers._form', ['supplier' => $supplier])
                        <div class="flex justify-end gap-2 mt-4">
                            <button type="button" @click="editing = null"
                                    class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600">Cancel</button>
                            <button type="submit" class="px-4 py-2 rounded bg-yellow-600 hover:bg-yellow-700 text-white">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
@endsection
