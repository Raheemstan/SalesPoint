@extends('layouts.app')

@section('title', 'Branches')

@section('content')
    <div class="space-y-4">

        {{-- What this screen is for. Most shops meet it on the day they open their second site. --}}
        <div class="bg-white dark:bg-gray-800 rounded shadow p-4 flex flex-wrap gap-4 justify-between items-center">
            <div class="text-sm text-gray-600 dark:text-gray-300">
                One business, several shops. Each branch keeps its own stock, tills, drawers and sales;
                the catalogue, customers and suppliers are shared by all of them.
            </div>
            <a href="{{ route('transfers.index') }}"
               class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                Stock transfers
            </a>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
            <div class="overflow-x-auto">
                <table class="w-full table-auto text-sm">
                    <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                        <tr>
                            <th class="p-2 text-left">Code</th>
                            <th class="p-2 text-left">Branch</th>
                            <th class="p-2 text-left">Contact</th>
                            <th class="p-2 text-right">Staff</th>
                            <th class="p-2 text-right">Units held</th>
                            <th class="p-2 text-right">Stock at cost</th>
                            <th class="p-2 text-right">Takings</th>
                            <th class="p-2 text-left">Status</th>
                            <th class="p-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($branches as $branch)
                            <tr class="border-t dark:border-gray-700 {{ $active && $active->id === $branch->id ? 'bg-primary-50 dark:bg-primary-900/20' : '' }}">
                                <td class="p-2 font-mono font-semibold">{{ $branch->code }}</td>
                                <td class="p-2">
                                    {{ $branch->name }}
                                    @if ($branch->is_default)
                                        <span class="ml-1 text-xs text-gray-500 dark:text-gray-400">(default)</span>
                                    @endif
                                    @if ($active && $active->id === $branch->id)
                                        <span class="ml-1 text-xs font-medium text-primary-700 dark:text-primary-300">&mdash; you are here</span>
                                    @endif
                                </td>
                                <td class="p-2 text-gray-600 dark:text-gray-400">
                                    {{ $branch->address ?: '-' }}
                                    @if ($branch->phone)
                                        <span class="block text-xs">{{ $branch->phone }}</span>
                                    @endif
                                </td>
                                <td class="p-2 text-right">{{ $branch->users_count }}</td>
                                <td class="p-2 text-right">{{ number_format((int) $branch->stock_units) }}</td>
                                <td class="p-2 text-right">{{ money($branch->stock_value) }}</td>
                                <td class="p-2 text-right">{{ money($branch->takings) }}</td>
                                <td class="p-2">
                                    @if ($branch->is_active)
                                        <span class="px-2 py-0.5 rounded text-xs bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">Open</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded text-xs bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">Closed</span>
                                    @endif
                                </td>
                                <td class="p-2 text-right">
                                    <a href="{{ route('branches.show', $branch) }}" class="text-blue-600 dark:text-blue-400 hover:underline">
                                        View
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="p-4 text-center text-gray-500 dark:text-gray-400">
                                    No branches yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($canManage)
            {{-- Inline, because the resource route deliberately has no create page:
                 opening a shop is five fields, and a whole screen for it is a screen
                 nobody visits twice. --}}
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">Open a branch</h2>

                <form method="POST" action="{{ route('branches.store') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                    @csrf

                    <div class="lg:col-span-2">
                        <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Name</label>
                        <input type="text" name="name" id="name" value="{{ old('name') }}" required maxlength="255"
                               class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>

                    <div>
                        <label for="code" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Code</label>
                        <input type="text" name="code" id="code" value="{{ old('code') }}" required maxlength="12"
                               placeholder="IKJ"
                               class="w-full px-3 py-2 border rounded-md shadow-sm uppercase dark:bg-gray-800 dark:text-white dark:border-gray-600">
                        <x-input-error :messages="$errors->get('code')" class="mt-1" />
                    </div>

                    <div class="lg:col-span-2">
                        <label for="address" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Address</label>
                        <input type="text" name="address" id="address" value="{{ old('address') }}" maxlength="255"
                               class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                        <x-input-error :messages="$errors->get('address')" class="mt-1" />
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Phone</label>
                        <input type="text" name="phone" id="phone" value="{{ old('phone') }}" maxlength="40"
                               class="w-full px-3 py-2 border rounded-md shadow-sm dark:bg-gray-800 dark:text-white dark:border-gray-600">
                        <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                    </div>

                    <div class="sm:col-span-2 lg:col-span-6">
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm">
                            Open branch
                        </button>
                        <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">
                            A new branch starts with no stock. Send it some from the transfers screen.
                        </span>
                    </div>
                </form>
            </div>
        @endif
    </div>
@endsection
