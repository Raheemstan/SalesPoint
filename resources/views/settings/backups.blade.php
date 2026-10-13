@extends('layouts.app')

@section('title', 'Backup history')

@section('content')
<div class="bg-white dark:bg-gray-800 rounded shadow p-4 text-sm">

    <div class="flex items-center justify-between mb-4">
        <div>
            <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100">Backup history</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Every run, by hand or by the scheduler. Writing to <code>{{ $destination }}</code>.
            </p>
        </div>
        <a href="{{ route('settings.index') }}" class="text-blue-600 dark:text-blue-400 hover:underline">
            &larr; Back to settings
        </a>
    </div>

    @include('settings._backup_history', ['backups' => $backups])

    <div class="mt-4">
        {{ $backups->links() }}
    </div>
</div>
@endsection
