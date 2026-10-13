<?php

use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Setting;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
 * Backups, by hand and on a schedule.
 *
 * The in-memory test database has no file to copy, so each test points the
 * sqlite connection at a real temporary file and writes the backups to another
 * temporary folder. Nothing here touches the developer's own database or
 * storage/app/backups.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);

    $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'salespoint-backup-test-' . bin2hex(random_bytes(4));
    mkdir($this->workspace, 0755, true);

    // A stand-in for the live database file.
    $this->sourceDb = $this->workspace . DIRECTORY_SEPARATOR . 'live.sqlite';
    file_put_contents($this->sourceDb, 'SQLite format 3 - pretend database contents');

    $this->destination = $this->workspace . DIRECTORY_SEPARATOR . 'backups';

    config(['database.connections.sqlite.database' => $this->sourceDb]);
    Setting::set('backup_path', $this->destination);
    Setting::set('backup_disk', BackupService::LOCAL);
});

afterEach(function () {
    // Leave no temporary files behind, even when a test failed.
    if (isset($this->workspace) && is_dir($this->workspace)) {
        foreach (glob($this->workspace . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->workspace . '/*') ?: [] as $file) {
            is_dir($file) ? @rmdir($file) : @unlink($file);
        }
        @rmdir($this->workspace);
    }
});

// ------------------------------------------------------------------ running

test('a backup run records a row, writes the file and reports its size', function () {
    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->status)->toBe(Backup::STATUS_SUCCESS)
        ->and($backup->driver)->toBe('sqlite')
        ->and($backup->disk)->toBe(BackupService::LOCAL)
        ->and($backup->created_by)->toBe($this->admin->id)
        ->and($backup->size_bytes)->toBe(filesize($this->sourceDb))
        ->and(is_file($backup->path))->toBeTrue()
        // the copy is the database, not an empty file
        ->and(file_get_contents($backup->path))->toBe(file_get_contents($this->sourceDb));

    expect(Backup::count())->toBe(1);
});

test('the scheduler leaves created_by null, because there is no user behind a cron job', function () {
    $backup = app(BackupService::class)->run();

    expect($backup->created_by)->toBeNull()
        ->and($backup->succeeded())->toBeTrue();
});

test('a failed run is recorded with its error instead of throwing', function () {
    // The database file disappears - a disk unmounted, a path mistyped.
    unlink($this->sourceDb);

    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->status)->toBe(Backup::STATUS_FAILED)
        ->and($backup->error)->toContain('could not be found')
        ->and($backup->size_bytes)->toBe(0);
});

test('an unsupported database driver fails safely rather than silently', function () {
    /*
     * The driver on the *connection's config* is changed, not the default
     * connection: the service reads the driver from there, while the already
     * open test connection carries on working. Switching database.default
     * would send every query in the test at a database that is not there.
     */
    config(['database.connections.sqlite.driver' => 'oracle']);

    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->status)->toBe(Backup::STATUS_FAILED)
        ->and($backup->error)->toContain('not supported');
});

test('a destination that cannot be written is recorded as a failure', function () {
    // A path that cannot exist: a file where a folder would have to be.
    $file = $this->workspace . DIRECTORY_SEPARATOR . 'not-a-folder';
    file_put_contents($file, 'x');
    Setting::set('backup_path', $file . DIRECTORY_SEPARATOR . 'nested');

    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->status)->toBe(Backup::STATUS_FAILED)
        ->and($backup->error)->not->toBeEmpty();
});

test('the destination can be a configured filesystem disk instead of a folder', function () {
    // Configured *and* faked: the service only accepts disks that exist in
    // config/filesystems.php, so a typo in Settings cannot stop backups.
    config(['filesystems.disks.offsite' => ['driver' => 'local', 'root' => $this->workspace . '/offsite']]);
    Storage::fake('offsite');

    Setting::set('backup_disk', 'offsite');

    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->succeeded())->toBeTrue()
        ->and($backup->disk)->toBe('offsite')
        ->and($backup->path)->toStartWith('backups/');

    Storage::disk('offsite')->assertExists($backup->path);
});

test('an unknown disk name falls back to a local folder rather than failing', function () {
    Setting::set('backup_disk', 'disk-that-was-removed');

    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->succeeded())->toBeTrue()
        ->and($backup->disk)->toBe(BackupService::LOCAL);
});

// ---------------------------------------------------------------- retention

test('retention deletes backups older than the configured number of days', function () {
    // Retention off while the fixtures are made: every run prunes, and a rule
    // applied too early would delete the row this test is about to backdate.
    Setting::set('backup_retention_days', 0);

    $service = app(BackupService::class);

    $old = $service->run($this->admin);
    $oldPath = $old->path;
    $old->forceFill(['created_at' => now()->subDays(30)])->save();

    $recent = $service->run($this->admin);

    expect(Backup::count())->toBe(2);

    Setting::set('backup_retention_days', 7);
    $service->prune();

    expect(Backup::find($old->id))->toBeNull()
        ->and(Backup::find($recent->id))->not->toBeNull()
        // the file goes with the row
        ->and(is_file($oldPath))->toBeFalse()
        ->and(is_file($recent->fresh()->path))->toBeTrue();
});

test('retention can instead keep only the most recent few', function () {
    Setting::set('backup_retention_days', 0);  // age is not the rule here
    Setting::set('backup_retention_count', 2);

    $service = app(BackupService::class);

    $first = $service->run($this->admin);
    $second = $service->run($this->admin);
    $third = $service->run($this->admin);

    // Each run prunes, so the oldest is already gone.
    expect(Backup::count())->toBe(2)
        ->and(Backup::find($first->id))->toBeNull()
        ->and(Backup::find($third->id))->not->toBeNull();
});

test('the most recent successful backup is never deleted, whatever retention says', function () {
    Setting::set('backup_retention_days', 1);

    $service = app(BackupService::class);
    $only = $service->run($this->admin);

    // Backdate it well past the retention window.
    $only->forceFill(['created_at' => now()->subYear()])->save();

    $service->prune();

    expect(Backup::find($only->id))->not->toBeNull()
        ->and(is_file($only->fresh()->path))->toBeTrue();
});

test('retention keeps everything when it is switched off', function () {
    Setting::set('backup_retention_days', 0);
    Setting::set('backup_retention_count', 0);

    $service = app(BackupService::class);
    $old = $service->run($this->admin);
    $old->forceFill(['created_at' => now()->subYears(5)])->save();
    $service->run($this->admin);

    $service->prune();

    expect(Backup::count())->toBe(2);
});

// ------------------------------------------------------------------- the UI

test('an admin can run a backup from the settings screen', function () {
    $this->post(route('settings.backups.run'))->assertRedirect()->assertSessionHas('success');

    expect(Backup::count())->toBe(1);
    expect(AuditLog::where('action', 'Ran Database Backup')->exists())->toBeTrue();
});

test('a failed run from the screen says so rather than appearing to work', function () {
    unlink($this->sourceDb);

    $this->post(route('settings.backups.run'))->assertSessionHas('error');

    expect(Backup::where('status', Backup::STATUS_FAILED)->count())->toBe(1);
    expect(AuditLog::where('action', 'Database Backup Failed')->exists())->toBeTrue();
});

test('a stored backup can be downloaded, and the download is recorded', function () {
    $backup = app(BackupService::class)->run($this->admin);

    $this->get(route('settings.backups.download', $backup))
        ->assertOk()
        ->assertDownload($backup->filename);

    expect(AuditLog::where('action', 'Downloaded Database Backup')->where('record_id', $backup->id)->exists())
        ->toBeTrue();
});

test('downloading a backup whose file has gone says so instead of crashing', function () {
    $backup = app(BackupService::class)->run($this->admin);
    unlink($backup->path);

    $this->get(route('settings.backups.download', $backup))
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('the one-off download still works and keeps no copy', function () {
    $before = count(glob($this->destination . '/*') ?: []);

    $this->post(route('settings.backup.download'))->assertOk();

    // The kept-backups folder is untouched and no row was written.
    expect(count(glob($this->destination . '/*') ?: []))->toBe($before)
        ->and(Backup::count())->toBe(0);

    expect(AuditLog::where('action', 'Downloaded Database Backup')->exists())->toBeTrue();
});

test('the one-off download reports a failure rather than sending an empty file', function () {
    unlink($this->sourceDb);

    $this->post(route('settings.backup.download'))->assertRedirect()->assertSessionHas('error');
});

test('the history page and the backup tab both list runs with size and status', function () {
    app(BackupService::class)->run($this->admin);
    $failed = Backup::create([
        'filename' => 'backup-failed.sqlite',
        'path' => '',
        'disk' => BackupService::LOCAL,
        'driver' => 'sqlite',
        'status' => Backup::STATUS_FAILED,
        'error' => 'mysqldump is not installed',
    ]);

    $this->get(route('settings.backups'))->assertOk()
        ->assertSee('Success')
        ->assertSee('Failed')
        ->assertSee('mysqldump is not installed');

    $this->get(route('settings.index'))->assertOk()
        ->assertSee('Run a backup now')
        ->assertSee('backup-failed.sqlite');
});

test('a manager cannot run, list or download backups', function () {
    $backup = app(BackupService::class)->run($this->admin);

    $this->actingAs(User::factory()->manager()->create());

    $this->post(route('settings.backups.run'))->assertForbidden();
    $this->get(route('settings.backups'))->assertForbidden();
    $this->get(route('settings.backups.download', $backup))->assertForbidden();
    $this->post(route('settings.backup.download'))->assertForbidden();
});

// ------------------------------------------------------------- the schedule

test('the schedule settings are validated and stored', function () {
    $this->post(route('settings.update'), [
        'backup_enabled' => '1',
        'backup_frequency' => 'weekly',
        'backup_time' => '23:30',
        'backup_retention_days' => 14,
        'backup_retention_count' => 5,
        'backup_disk' => BackupService::LOCAL,
        'backup_path' => $this->destination,
    ])->assertSessionHasNoErrors();

    expect(setting('backup_enabled'))->toBe('1')
        ->and(setting('backup_frequency'))->toBe('weekly')
        ->and(setting('backup_time'))->toBe('23:30');

    // Rubbish is refused rather than stored and read back later.
    $this->post(route('settings.update'), ['backup_frequency' => 'hourly'])->assertSessionHasErrors('backup_frequency');
    $this->post(route('settings.update'), ['backup_time' => '25:00'])->assertSessionHasErrors('backup_time');
    $this->post(route('settings.update'), ['backup_time' => 'tea time'])->assertSessionHasErrors('backup_time');
    $this->post(route('settings.update'), ['backup_retention_days' => -1])->assertSessionHasErrors('backup_retention_days');
    $this->post(route('settings.update'), ['backup_disk' => 'made-up'])->assertSessionHasErrors('backup_disk');
});

test('the command does nothing when scheduled backups are switched off', function () {
    Setting::set('backup_enabled', '0');

    $this->artisan('salespoint:backup')->assertSuccessful();

    expect(Backup::count())->toBe(0);
});

test('the command runs when scheduled backups are on', function () {
    Setting::set('backup_enabled', '1');
    Setting::set('backup_frequency', 'daily');

    $this->artisan('salespoint:backup')->assertSuccessful();

    expect(Backup::count())->toBe(1);
    expect(Backup::first()->created_by)->toBeNull();
});

test('--force runs even when scheduled backups are off', function () {
    Setting::set('backup_enabled', '0');

    $this->artisan('salespoint:backup --force')->assertSuccessful();

    expect(Backup::count())->toBe(1);
});

test('the command exits non-zero when the backup fails, so a monitor notices', function () {
    Setting::set('backup_enabled', '1');
    unlink($this->sourceDb);

    $this->artisan('salespoint:backup')->assertFailed();

    expect(Backup::where('status', Backup::STATUS_FAILED)->count())->toBe(1);
});

test('a weekly schedule only runs on Mondays', function () {
    Setting::set('backup_enabled', '1');
    Setting::set('backup_frequency', 'weekly');

    $service = app(BackupService::class);

    $this->travelTo(now()->next('Monday'));
    expect($service->dueNow())->toBeTrue();

    $this->travelTo(now()->next('Thursday'));
    expect($service->dueNow())->toBeFalse();

    $this->travelBack();
});

test('the configured time is used, and nonsense falls back to 02:00', function () {
    $service = app(BackupService::class);

    Setting::set('backup_time', '04:45');
    expect($service->scheduleTime())->toBe('04:45');

    // Can only happen if the row is edited outside the validated form.
    Setting::set('backup_time', 'whenever');
    expect($service->scheduleTime())->toBe('02:00');
});

// ------------------------------------------------------------- the migration

test('the migration rolls back and forward again', function () {
    /*
     * A down() that does not work is a migration you cannot undo on a shop's
     * live database. SQLite in particular refuses to drop an indexed column
     * while the index is still there, which is the usual way this breaks.
     */
    $migration = require database_path('migrations/2026_10_14_000005_add_user_status_two_factor_and_backups.php');

    $migration->down();

    expect(Schema::hasTable('backups'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'is_active'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'two_factor_secret'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'last_login_at'))->toBeFalse();

    $migration->up();

    expect(Schema::hasTable('backups'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'is_active'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'two_factor_confirmed_at'))->toBeTrue()
        // the backfill runs again, so nobody comes back deactivated
        ->and(User::find($this->admin->id)->is_active)->toBeTrue();
});
