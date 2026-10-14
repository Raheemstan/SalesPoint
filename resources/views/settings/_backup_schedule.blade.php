@php
    // Only disks that are configured in config/filesystems.php can be offered,
    // because BackupService falls back to a local folder for anything else.
    $disks = collect(config('filesystems.disks', []))->keys()
        ->mapWithKeys(fn ($name) => [$name => 'Disk: ' . $name])
        ->prepend('A folder on this machine', \App\Services\BackupService::LOCAL)
        ->all();

    $label = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
    $control = 'w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm'
        . ' focus:border-primary-500 focus:ring-primary-500'
        . ' dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
    $hint = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $error = 'mt-1 text-xs text-red-600 dark:text-red-400';
    $check = 'mt-0.5 h-5 w-5 shrink-0 rounded border-gray-300 text-primary-600'
        . ' focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700';
@endphp

{{-- These fields are part of the main settings form; the buttons are not (see index). --}}
<x-card title="Automatic backups" subtitle="A copy taken on a schedule, without anyone remembering to.">
    <div class="flex items-start gap-3 pb-4">
        <input type="checkbox" id="backup_enabled" name="backup_enabled" value="1"
               @checked(old('backup_enabled', setting('backup_enabled'))) class="{{ $check }}">
        <div class="min-w-0">
            <label for="backup_enabled" class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                Back up automatically
            </label>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                Needs the Laravel scheduler running on the server (see below). With it off, the
                <strong>Run a backup now</strong> button still works.
            </p>
        </div>
    </div>

    <div class="grid gap-4 border-t border-gray-200 pt-4 dark:border-gray-700 sm:grid-cols-3">
        <div>
            <label for="backup_frequency" class="{{ $label }}">How often</label>
            <select id="backup_frequency" name="backup_frequency" class="{{ $control }}">
                @foreach (['daily' => 'Every day', 'weekly' => 'Once a week (Monday)'] as $value => $text)
                    <option value="{{ $value }}"
                        @selected(old('backup_frequency', setting('backup_frequency', 'daily')) === $value)>{{ $text }}</option>
                @endforeach
            </select>
            @error('backup_frequency')
                <p class="{{ $error }}">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="backup_time" class="{{ $label }}">At what time</label>
            <input type="time" id="backup_time" name="backup_time"
                   value="{{ old('backup_time', setting('backup_time', '02:00')) }}"
                   class="{{ $control }} tabular-nums">
            <p class="{{ $hint }}">24-hour, HH:MM.</p>
            @error('backup_time')
                <p class="{{ $error }}">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="backup_disk" class="{{ $label }}">Where to put it</label>
            <select id="backup_disk" name="backup_disk" class="{{ $control }}">
                @foreach ($disks as $value => $text)
                    <option value="{{ $value }}"
                        @selected(old('backup_disk', setting('backup_disk', \App\Services\BackupService::LOCAL)) === $value)>{{ $text }}</option>
                @endforeach
            </select>
            @error('backup_disk')
                <p class="{{ $error }}">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="mt-4">
        <label for="backup_path" class="{{ $label }}">Folder</label>
        <input type="text" id="backup_path" name="backup_path"
               value="{{ old('backup_path', setting('backup_path')) }}"
               placeholder="D:\salespoint-backups" class="{{ $control }}">
        <p class="{{ $hint }}">
            Leave it empty for storage/app/backups. Currently writing to
            <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-700">{{ $backupDestination ?? '' }}</code>.
        </p>
        @error('backup_path')
            <p class="{{ $error }}">{{ $message }}</p>
        @enderror
    </div>

    <div class="mt-4 flex items-start gap-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-900
                ring-1 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-100 dark:ring-amber-800">
        <span class="mt-0.5 shrink-0 text-amber-600 dark:text-amber-300">
            <x-icon name="alert-triangle" size="5" />
        </span>
        <p class="min-w-0">
            <strong>A second location is the point of a backup.</strong> A copy sitting on the same drive
            as the database is lost with the drive. Point this at another drive, a USB disk that stays
            plugged in, or a folder your cloud client syncs - anything the shop's own machine failing
            would not take with it.
        </p>
    </div>

    <div class="mt-4 grid gap-4 border-t border-gray-200 pt-4 dark:border-gray-700 sm:grid-cols-2">
        <div>
            <label for="backup_retention_days" class="{{ $label }}">Delete backups older than (days)</label>
            <input type="number" id="backup_retention_days" name="backup_retention_days" min="0" max="3650"
                   value="{{ old('backup_retention_days', setting('backup_retention_days', 30)) }}"
                   class="{{ $control }} tabular-nums">
            <p class="{{ $hint }}">0 keeps them forever.</p>
            @error('backup_retention_days')
                <p class="{{ $error }}">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="backup_retention_count" class="{{ $label }}">Or keep only this many</label>
            <input type="number" id="backup_retention_count" name="backup_retention_count" min="0" max="1000"
                   value="{{ old('backup_retention_count', setting('backup_retention_count', 0)) }}"
                   class="{{ $control }} tabular-nums">
            <p class="{{ $hint }}">0 is no limit.</p>
            @error('backup_retention_count')
                <p class="{{ $error }}">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
        Whichever rules you set, the most recent successful backup is never deleted.
    </p>

    <x-slot:footer>
        Automatic backups need the Laravel scheduler running on the server - one cron entry:
        <code class="rounded bg-gray-100 px-1 py-0.5 dark:bg-gray-700">* * * * * cd {{ base_path() }} &amp;&amp; php artisan schedule:run</code>
    </x-slot:footer>
</x-card>
