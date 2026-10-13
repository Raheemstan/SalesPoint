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
