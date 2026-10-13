<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * The scheduled backup. Registered in routes/console.php.
 *
 * It decides for itself whether tonight is a night it should run, rather than
 * the schedule deciding: the shop sets the time and the frequency in Settings,
 * and the cron entry is a fixed "run every minute" that must not need editing
 * when they change their mind.
 */
class RunBackup extends Command
{
    protected $signature = 'salespoint:backup
                            {--force : run even when scheduled backups are switched off in Settings}';

    protected $description = 'Back up the database to the configured location and apply retention';

    public function handle(BackupService $backups): int
    {
        if (!$this->option('force') && !$backups->dueNow()) {
            $this->components->info('Scheduled backups are off, or today is not a backup day. Nothing to do.');

            return self::SUCCESS;
        }

        $this->components->info('Backing up to ' . $backups->destinationLabel());

        $backup = $backups->run();
        $backups->recordAudit($backup);

        if ($backup->status === Backup::STATUS_FAILED) {
            // A non-zero exit is what a cron wrapper or a monitor watches for.
            $this->components->error('Backup failed: ' . $backup->error);

            return self::FAILURE;
        }

        $this->components->info("Backed up {$backup->filename} ({$backup->size_for_humans}).");

        return self::SUCCESS;
    }
}
