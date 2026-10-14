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
                            {--force : run even when scheduled backups are switched off in Settings}
                            {--upload-only : do not take a new backup, only retry the offsite uploads that are still owed}';

    protected $description = 'Back up the database, send a copy offsite, and apply retention';

    public function handle(BackupService $backups): int
    {
        if ($this->option('upload-only')) {
            return $this->uploadOnly($backups);
        }

        if (!$this->option('force') && !$backups->dueNow()) {
            $this->components->info('Scheduled backups are off, or today is not a backup day. Nothing to do.');

            return self::SUCCESS;
        }

        $this->components->info('Backing up to ' . $backups->destinationLabel());

        if ($backups->offsiteEnabled()) {
            $this->components->info('Offsite copy to ' . $backups->offsiteDestinationLabel());
        }

        $backup = $backups->run();
        $backups->recordAudit($backup);

        if ($backup->status === Backup::STATUS_FAILED) {
            // A non-zero exit is what a cron wrapper or a monitor watches for.
            $this->components->error('Backup failed: ' . $backup->error);

            return self::FAILURE;
        }

        $this->components->info("Backed up {$backup->filename} ({$backup->size_for_humans}).");

        return $this->reportOffsite($backups, $backup);
    }

    /**
     * Say what happened to the second copy.
     *
     * A refused upload exits non-zero even though the backup itself worked: the
     * shop has a copy, but it is on the same machine as the database, which is
     * the situation this whole feature exists to get out of, and a monitor
     * should see it. A *pending* upload does not, because it is expected to
     * clear on its own.
     */
    private function reportOffsite(BackupService $backups, Backup $backup): int
    {
        if (!$backups->offsiteEnabled()) {
            $this->components->warn(
                'Offsite backup is switched off, so this copy is on the same machine as the database. '
                . 'Switch it on in Settings > Backup.'
            );

            return self::SUCCESS;
        }

        return match ($backup->offsite_status) {
            Backup::OFFSITE_UPLOADED => $this->ok(
                'Offsite copy uploaded and verified: ' . $backup->offsite_path
                . ' (' . $backup->offsite_size_for_humans
                . ($backup->offsite_encrypted ? ', encrypted' : ', NOT encrypted') . ').'
            ),
            Backup::OFFSITE_FAILED => $this->bad(
                'The offsite copy was refused and needs attention: ' . $backup->offsite_error
            ),
            default => $this->waiting(
                'The offsite copy is still owed and will be retried: ' . $backup->offsite_error
            ),
        };
    }

    private function uploadOnly(BackupService $backups): int
    {
        if (!$backups->offsiteEnabled()) {
            $this->components->info('Offsite backup is switched off. Nothing to upload.');

            return self::SUCCESS;
        }

        $owed = $backups->pendingOffsiteCount();

        if ($owed === 0) {
            $this->components->info('Every backup already has an offsite copy.');

            return self::SUCCESS;
        }

        $this->components->info('Trying ' . $owed . ' backup(s) that still owe an offsite copy.');

        $result = $backups->uploadPending(force: true, limit: $owed);

        $this->components->info($result['uploaded'] . ' uploaded, ' . $result['failed'] . ' still waiting.');

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function ok(string $message): int
    {
        $this->components->info($message);

        return self::SUCCESS;
    }

    private function waiting(string $message): int
    {
        $this->components->warn($message);

        return self::SUCCESS;
    }

    private function bad(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
