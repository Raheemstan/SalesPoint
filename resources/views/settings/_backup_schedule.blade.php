@php
    // Only disks that are configured in config/filesystems.php can be offered,
    // because BackupService falls back to a local folder for anything else.
    $disks = collect(config('filesystems.disks', []))->keys()
        ->mapWithKeys(fn ($name) => [$name => 'Disk: ' . $name])
        ->prepend('A folder on this machine', \App\Services\BackupService::LOCAL)
        ->all();
@endphp

{{-- These fields are part of the main settings form; the buttons are not (see index). --}}
<x-section title="Automatic backups">
    <x-checkbox name="backup_enabled" label="Back up automatically" :checked="setting('backup_enabled')" />

    <div class="grid md:grid-cols-3 gap-4 mt-2">
        <x-select name="backup_frequency" label="How often"
            :options="['daily' => 'Every day', 'weekly' => 'Once a week (Monday)']"
            :value="setting('backup_frequency', 'daily')" />

        <x-input name="backup_time" label="At what time (24-hour, HH:MM)" type="time"
            :value="setting('backup_time', '02:00')" />

        <x-select name="backup_disk" label="Where to put it" :options="$disks"
            :value="setting('backup_disk', \App\Services\BackupService::LOCAL)" />
    </div>

    <x-input name="backup_path" label="Folder (leave empty for storage/app/backups)"
        :value="setting('backup_path')" placeholder="D:\salespoint-backups" />
    <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">
        <strong>A second location is the point of a backup.</strong> A copy sitting on the same drive as
        the database is lost with the drive. Point this at another drive, a USB disk that stays plugged
        in, or a folder your cloud client syncs - anything the shop's own machine failing would not take
        with it. Currently writing to <code>{{ $backupDestination ?? '' }}</code>.
    </p>

    <div class="grid md:grid-cols-2 gap-4 mt-2">
        <x-input name="backup_retention_days" label="Delete backups older than (days, 0 = keep forever)"
            type="number" min="0" max="3650" :value="setting('backup_retention_days', 30)" />

        <x-input name="backup_retention_count" label="Or keep only this many (0 = no limit)"
            type="number" min="0" max="1000" :value="setting('backup_retention_count', 0)" />
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">
        Whichever rules you set, the most recent successful backup is never deleted.
    </p>

    <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">
        Automatic backups need the Laravel scheduler running on the server - one cron entry:
        <code>* * * * * cd {{ base_path() }} &amp;&amp; php artisan schedule:run</code>.
        Without it, the Run now button below still works.
    </p>
</x-section>
