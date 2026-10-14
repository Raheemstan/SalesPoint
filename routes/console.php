<?php

use App\Services\BackupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled backups
|--------------------------------------------------------------------------
|
| The shop chooses the time in Settings > Backup, so the schedule cannot be a
| fixed ->dailyAt('02:00') baked in here. Instead the command is offered every
| day at the configured minute, and it decides whether tonight is one of its
| nights (daily, or Mondays when the shop picked weekly).
|
| Reading a setting at this point means a database query every time the
| scheduler boots, so it is wrapped: on a fresh install, or during the first
| migrate, the settings table may not exist yet and `php artisan` must still
| run. 02:00 is the fallback.
|
| This needs one cron entry on the server, as Laravel always does:
|
|   * * * * * cd /path/to/salespoint && php artisan schedule:run >> /dev/null 2>&1
|
*/
$backupTime = '02:00';

try {
    $backupTime = app(BackupService::class)->scheduleTime();
} catch (Throwable $e) {
    // No database yet (install, migrate, config:cache): keep the default.
}

Schedule::command('salespoint:backup')
    ->dailyAt($backupTime)
    ->withoutOverlapping()
    ->name('salespoint-backup');

/*
|--------------------------------------------------------------------------
| Catching up on offsite copies
|--------------------------------------------------------------------------
|
| A shop's internet is not up all night. If the upload failed at 02:00 because
| the line was down, waiting until 02:00 tomorrow to try again means the only
| copy of today's trading sits on the same machine as the database for 24 hours
| - which is the exact situation offsite backup exists to avoid.
|
| So the uploads that are still owed are retried every hour. Each backup carries
| its own backoff (see BackupService::recordOffsiteFailure), so this does not
| hammer a destination that is refusing us; and with offsite backup switched off
| the command reads one setting, counts no rows and exits.
|
*/
Schedule::command('salespoint:backup --upload-only')
    ->hourly()
    ->withoutOverlapping()
    ->name('salespoint-backup-upload');
