@extends('layouts.app')

@section('title', 'Audit log')

@php
    $input = 'rounded-md border border-gray-300 dark:border-gray-600 px-2 py-1 bg-white dark:bg-gray-700 dark:text-gray-100 text-sm';
@endphp

@section('content')
<div class="bg-white dark:bg-gray-800 rounded shadow p-4 text-sm">

    <div class="flex flex-wrap items-start justify-between gap-2 mb-4">
        <div>
            <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100">Audit log</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Who did what, and from where. Newest first.
                @if (setting('enable_audit_log', '1') !== '1')
                    <span class="text-amber-600 dark:text-amber-400">
                        Recording is currently switched off in Settings &rsaquo; Security, so nothing new is
                        being added.
                    </span>
                @endif
            </p>
        </div>
        <a href="{{ route('settings.index') }}" class="text-blue-600 dark:text-blue-400 hover:underline">
            &larr; Back to settings
        </a>
    </div>

    <form method="GET" action="{{ route('settings.audit') }}" class="flex flex-wrap gap-3 items-end mb-4">
        <label class="text-xs text-gray-500 dark:text-gray-400">
            Person
            <select name="user_id" class="{{ $input }} block">
                <option value="">Anyone</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @selected((string) $filters['user_id'] === (string) $user->id)>
                        {{ $user->name }}
                    </option>
                @endforeach
            </select>
        </label>

        <label class="text-xs text-gray-500 dark:text-gray-400">
            Action
            <select name="action" class="{{ $input }} block">
                <option value="">Any action</option>
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected($filters['action'] === $action)>{{ $action }}</option>
                @endforeach
            </select>
        </label>

        <label class="text-xs text-gray-500 dark:text-gray-400">
            Table
            <select name="table_name" class="{{ $input }} block">
                <option value="">Any table</option>
                @foreach ($tables as $table)
                    <option value="{{ $table }}" @selected($filters['table_name'] === $table)>{{ $table }}</option>
                @endforeach
            </select>
        </label>

        <label class="text-xs text-gray-500 dark:text-gray-400">
            From
            <input type="date" name="from" value="{{ $filters['from'] }}" class="{{ $input }} block">
        </label>

        <label class="text-xs text-gray-500 dark:text-gray-400">
            To
            <input type="date" name="to" value="{{ $filters['to'] }}" class="{{ $input }} block">
        </label>

        <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded">Filter</button>

        @if (array_filter($filters))
            <a href="{{ route('settings.audit') }}" class="text-gray-500 dark:text-gray-400 hover:underline px-1">Clear</a>
        @endif

        {{-- The export carries whatever is being looked at, not the whole log. --}}
        <a href="{{ route('settings.audit.csv', request()->query()) }}"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-1.5 rounded">Export CSV</a>
    </form>

    <div class="overflow-x-auto">
        <table class="w-full text-left dark:text-gray-200">
            <thead>
                <tr class="bg-gray-100 dark:bg-gray-700">
                    <th class="p-2">When</th>
                    <th class="p-2">Person</th>
                    <th class="p-2">Action</th>
                    <th class="p-2">Table</th>
                    <th class="p-2 text-right">Record</th>
                    <th class="p-2">IP address</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="p-2 whitespace-nowrap">{{ $log->created_at->format('d M Y H:i:s') }}</td>
                        <td class="p-2">
                            {{-- Eager loaded; a deleted user still has their name here. --}}
                            {{ $log->user->name ?? 'Deleted user #' . $log->user_id }}
                            @if ($log->user?->role)
                                <span class="text-xs text-gray-400 capitalize">({{ $log->user->role }})</span>
                            @endif
                        </td>
                        <td class="p-2">{{ $log->action }}</td>
                        <td class="p-2 text-gray-500 dark:text-gray-400">{{ $log->table_name }}</td>
                        <td class="p-2 text-right text-gray-500 dark:text-gray-400">
                            {{ $log->record_id ?: '-' }}
                        </td>
                        <td class="p-2 text-gray-500 dark:text-gray-400">{{ $log->ip_address ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-3 text-gray-500 dark:text-gray-400">
                            Nothing recorded for these filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $logs->links() }}
    </div>
</div>
@endsection
