@extends('layouts.app')

@section('title', 'Backup history')

@section('content')
    <x-page-header title="Backup history" subtitle="Every run, by hand or by the scheduler."
                   :back="route('settings.index')" back-label="Back to settings" />

    <x-card title="All runs" :padding="false">
        <x-slot:actions>
            <span class="text-sm text-gray-500 dark:text-gray-400">
                Writing to
                <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-700">{{ $destination }}</code>
            </span>
        </x-slot:actions>

        @include('settings._backup_history', ['backups' => $backups])

        <x-slot:footer>
            {{ $backups->links() }}
        </x-slot:footer>
    </x-card>
@endsection
