@extends('layouts.app')

@section('title', 'Audit log')

@php
    /*
    | The audit log viewer.
    |
    | The filter row is the one every report shares, so the screen a manager
    | learns on the sales report is the screen they already know here. The
    | action column is a status pill like every other status in the app, keyed
    | off what the action did rather than off a hardcoded list - the dropdown
    | above it is built from whatever is actually in the log.
    */
    $actionVariant = function (string $action): string {
        $text = Str::lower($action);

        return match (true) {
            Str::contains($text, ['deleted', 'void', 'failed', 'removed', 'deactivated']) => 'red',
            Str::contains($text, ['refund']) => 'orange',
            Str::contains($text, ['created', 'added', 'ran ', 'reactivated']) => 'green',
            Str::contains($text, ['updated', 'edited', 'reset', 'changed', 'adjusted']) => 'blue',
            Str::contains($text, ['exported', 'downloaded', 'emailed', 'printed']) => 'indigo',
            default => 'gray',
        };
    };

    $recording = setting('enable_audit_log', '1') === '1';
@endphp

@section('content')
    <x-page-header title="Audit log" subtitle="Who did what, and from where. Newest first."
                   :back="route('settings.index')" back-label="Back to settings" />

    @unless ($recording)
        <div class="mb-4 flex items-start gap-3 rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200
                    dark:bg-amber-900/30 dark:ring-amber-800" role="status">
            <span class="mt-0.5 shrink-0 text-amber-600 dark:text-amber-300">
                <x-icon name="alert-triangle" size="5" />
            </span>
            <p class="min-w-0 text-sm text-amber-900 dark:text-amber-100">
                Recording is currently switched off in Settings &rsaquo; Security, so nothing new is
                being added. What is already here stays.
            </p>
        </div>
    @endunless

    @include('reports.partials.filter-bar', [
        'startDate' => (string) $filters['from'],
        'endDate' => (string) $filters['to'],
        'startKey' => 'from',
        'endKey' => 'to',
        'fields' => [
            [
                'name' => 'user_id',
                'label' => 'Person',
                'options' => $users->pluck('name', 'id')->all(),
                'value' => $filters['user_id'],
                'placeholder' => 'Anyone',
            ],
            [
                'name' => 'action',
                'label' => 'Action',
                'options' => $actions->mapWithKeys(fn ($a) => [$a => $a])->all(),
                'value' => $filters['action'],
                'placeholder' => 'Any action',
            ],
            [
                'name' => 'table_name',
                'label' => 'Table',
                'options' => $tables->mapWithKeys(fn ($t) => [$t => $t])->all(),
                'value' => $filters['table_name'],
                'placeholder' => 'Any table',
            ],
        ],
        'csvRoute' => route('settings.audit.csv', request()->query()),
        'note' => 'The export carries whatever is on screen, not the whole log.',
    ])

    <x-card :padding="false" class="mt-4">
        <x-table>
            <x-slot:head>
                <tr>
                    <th>When</th>
                    <th>Person</th>
                    <th>Action</th>
                    <th>Table</th>
                    <th class="text-right">Record</th>
                    <th>IP address</th>
                </tr>
            </x-slot:head>

            @forelse ($logs as $log)
                <tr>
                    <td class="whitespace-nowrap tabular-nums">{{ $log->created_at->format('d M Y H:i:s') }}</td>
                    <td>
                        {{-- Eager loaded; a deleted user still has their name here. --}}
                        <span class="font-medium">{{ $log->user->name ?? 'Deleted user #' . $log->user_id }}</span>
                        @if ($log->user?->role)
                            <span class="block text-xs capitalize text-gray-500 dark:text-gray-400">
                                {{ $log->user->role }}
                            </span>
                        @endif
                    </td>
                    <td>
                        <x-badge :variant="$actionVariant($log->action)" size="sm">{{ $log->action }}</x-badge>
                    </td>
                    <td class="text-gray-500 dark:text-gray-400">{{ $log->table_name }}</td>
                    <td class="text-right tabular-nums text-gray-500 dark:text-gray-400">
                        {{ $log->record_id ?: '-' }}
                    </td>
                    <td class="whitespace-nowrap tabular-nums text-gray-500 dark:text-gray-400">
                        {{ $log->ip_address ?: '-' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-empty icon="shield" title="Nothing recorded for these filters"
                                 message="Widen the date range, or clear the filters to see the whole log." />
                    </td>
                </tr>
            @endforelse
        </x-table>

        <x-slot:footer>
            {{ $logs->links() }}
        </x-slot:footer>
    </x-card>
@endsection
