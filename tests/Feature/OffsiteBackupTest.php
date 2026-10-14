<?php

use App\Mail\BackupReportMail;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Setting;
use App\Models\User;
use App\Services\BackupEncrypter;
use App\Services\BackupService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Offsite backup
|--------------------------------------------------------------------------
|
| Nothing here touches the network, and nothing here can: the destination is a
| faked disk or a stand-in that throws a message of our choosing, and the mailer
| is faked. A test that needs real bucket credentials is a test that fails in CI
| for ever, and a backup test that uploads something to a real bucket is a test
| that costs somebody money.
|
| The routes for the three new actions are declared here because routes/web.php
| belongs to somebody else this week. They are the ones named in the summary, so
| when they are registered for real these definitions become redundant rather
| than wrong.
*/
beforeEach(function () {
    Mail::fake();   // no report leaves the test, whatever the settings say

    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);

    $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'salespoint-offsite-test-' . bin2hex(random_bytes(4));
    mkdir($this->workspace, 0755, true);

    // A stand-in for the live database file, with something recognisable in it
    // so "is this really encrypted?" is a question with an answer.
    $this->plaintext = "SQLite format 3\x00" . 'Adaeze Okonkwo,+2348031234567,12 Allen Avenue'
        . str_repeat('|CREATE TABLE sales|', 200);

    $this->sourceDb = $this->workspace . DIRECTORY_SEPARATOR . 'live.sqlite';
    file_put_contents($this->sourceDb, $this->plaintext);

    $this->destination = $this->workspace . DIRECTORY_SEPARATOR . 'backups';

    config(['database.connections.sqlite.database' => $this->sourceDb]);
    Setting::set('backup_path', $this->destination);
    Setting::set('backup_disk', BackupService::LOCAL);

    // Retention off unless a test is about retention: every run prunes, and a
    // rule applied too early deletes the row the test is about to examine.
    Setting::set('backup_retention_days', 0);
    Setting::set('backup_retention_count', 0);
    Setting::set('backup_offsite_retention_days', 0);

    /*
     * The offsite destination. Configured *and* faked: the config entry is what
     * validation and preflight read, the fake is what the upload actually
     * writes to.
     */
    config(['filesystems.disks.offsite' => [
        'driver' => 'local',
        'root' => $this->workspace . DIRECTORY_SEPARATOR . 'bucket',
        'throw' => true,
    ]]);
    Storage::fake('offsite');

    Setting::set('backup_offsite_enabled', '1');
    Setting::set('backup_offsite_disk', 'offsite');
    Setting::set('backup_offsite_path', 'shop-backups');
    Setting::set('backup_encrypt', '1');

    $this->key = 'test-backup-key-0123456789abcdef0123456789abcdef';
    app(BackupService::class)->storeEncryptionKey($this->key);

    /*
     * Stand in for the destination with something that fails the way a real one
     * does. The message is the interesting part: BackupService has to tell "the
     * line is down, try again tonight" from "the key is wrong, fetch a human",
     * and it has only the message to go on.
     */
    $this->failOffsiteWith = function (string $message) {
        $broken = Mockery::mock()->shouldIgnoreMissing();
        $broken->shouldReceive('writeStream')->andThrow(new RuntimeException($message));

        Storage::set('offsite', $broken);
    };

    $this->fixOffsite = fn () => Storage::fake('offsite');

    // The routes this feature needs. See the summary for the real definitions.
    Route::middleware(['web', 'auth', 'role:admin'])->prefix('settings')->name('settings.')->group(function () {
        Route::post('/backups/offsite/test', [App\Http\Controllers\SettingController::class, 'testOffsite'])
            ->name('backups.offsite.test');
        Route::post('/backups/offsite/upload', [App\Http\Controllers\SettingController::class, 'uploadOffsiteNow'])
            ->name('backups.offsite.upload');
        Route::post('/backups/{backup}/offsite', [App\Http\Controllers\SettingController::class, 'uploadBackupOffsite'])
            ->name('backups.offsite.one');
    });

    // A route that renders the new settings partial on its own, so the partial
    // can be asserted on before somebody wires its @include into the tab.
    Route::middleware(['web', 'auth', 'role:admin'])
        ->get('/test-only/offsite-partial', fn () => view('settings._backup_offsite'))
        ->name('test.offsite.partial');

    /*
     * Name lookups are built once, when the application boots, so routes
     * declared after that - as these are - are reachable by URL but invisible
     * to route(). This is the one line that makes them real.
     */
    Route::getRoutes()->refreshNameLookups();
});

afterEach(function () {
    // Leave no temporary files behind, even when a test failed.
    if (isset($this->workspace) && is_dir($this->workspace)) {
        $walker = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workspace, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($walker as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($this->workspace);
    }
});

/** The object that was uploaded, as bytes. */
function uploadedBytes(Backup $backup): string
{
    return Storage::disk($backup->offsite_disk)->get($backup->offsite_path);
}

// --------------------------------------------------------------- the happy path

test('a backup is compressed, encrypted, uploaded and verified', function () {
    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->status)->toBe(Backup::STATUS_SUCCESS)
        ->and($backup->offsite_status)->toBe(Backup::OFFSITE_UPLOADED)
        ->and($backup->offsite_disk)->toBe('offsite')
        ->and($backup->offsite_path)->toStartWith('shop-backups/')
        ->and($backup->offsite_path)->toEndWith('.gz.enc')
        ->and($backup->offsite_encrypted)->toBeTrue()
        ->and($backup->offsite_error)->toBeNull()
        ->and($backup->offsite_uploaded_at)->not->toBeNull()
        ->and($backup->offsite_next_attempt_at)->toBeNull()
        ->and($backup->offsite_size_bytes)->toBeGreaterThan(0);

    Storage::disk('offsite')->assertExists($backup->offsite_path);

    // Verified, not assumed: the recorded size and checksum are of what is
    // actually in the bucket, which is the difference between a backup and a
    // half-finished upload that looks like one.
    expect($backup->offsite_size_bytes)->toBe(Storage::disk('offsite')->size($backup->offsite_path))
        ->and($backup->offsite_checksum)->toMatch('/^[0-9a-f]{64}$/')
        ->and($backup->offsite_checksum)->toBe(hash('sha256', uploadedBytes($backup)));
});

test('what lands in the bucket is encrypted, and the shop is not legible in it', function () {
    $backup = app(BackupService::class)->run($this->admin);

    $object = uploadedBytes($backup);

    expect(substr($object, 0, 8))->toBe(BackupEncrypter::MAGIC)
        ->and($object)->not->toContain('Adaeze Okonkwo')
        ->and($object)->not->toContain('+2348031234567')
        ->and($object)->not->toContain('12 Allen Avenue')
        ->and($object)->not->toContain('CREATE TABLE')
        ->and($object)->not->toContain('SQLite format');
});

test('the uploaded object decrypts back to the database, byte for byte', function () {
    /*
     * The test this whole feature stands on. An encrypted upload nobody can
     * reverse is not a backup, it is a very safe way to lose your data.
     */
    $backup = app(BackupService::class)->run($this->admin);

    $enc = $this->workspace . DIRECTORY_SEPARATOR . 'fetched.gz.enc';
    file_put_contents($enc, uploadedBytes($backup));

    $encrypter = app(BackupEncrypter::class);
    $encrypter->decryptFile($enc, $this->workspace . DIRECTORY_SEPARATOR . 'fetched.gz', $this->key);
    $encrypter->gunzip(
        $this->workspace . DIRECTORY_SEPARATOR . 'fetched.gz',
        $this->workspace . DIRECTORY_SEPARATOR . 'fetched.sqlite'
    );

    expect(file_get_contents($this->workspace . DIRECTORY_SEPARATOR . 'fetched.sqlite'))->toBe($this->plaintext);
});

test('the local copy stays a plain dump that can be used directly', function () {
    // Compression and encryption are for the copy that leaves the building. A
    // shop restoring from the machine in front of them should not need a key.
    $backup = app(BackupService::class)->run($this->admin);

    expect(is_file($backup->path))->toBeTrue()
        ->and(file_get_contents($backup->path))->toBe($this->plaintext)
        ->and($backup->size_bytes)->toBe(strlen($this->plaintext))
        // ...and the offsite copy is smaller, because it was compressed.
        ->and($backup->offsite_size_bytes)->toBeLessThan($backup->size_bytes);
});

test('with encryption switched off the copy is only compressed, and says so', function () {
    Setting::set('backup_encrypt', '0');

    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->offsite_status)->toBe(Backup::OFFSITE_UPLOADED)
        ->and($backup->offsite_encrypted)->toBeFalse()
        ->and($backup->offsite_path)->toEndWith('.gz')
        ->and($backup->offsite_path)->not->toEndWith('.enc');

    // A gzip file, not one of ours.
    expect(bin2hex(substr(uploadedBytes($backup), 0, 2)))->toBe('1f8b');
});

test('offsite is off by default, and then a backup is local only', function () {
    Setting::set('backup_offsite_enabled', '0');

    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->succeeded())->toBeTrue()
        ->and($backup->offsite_status)->toBe(Backup::OFFSITE_OFF)
        ->and($backup->offsite_path)->toBeNull()
        ->and($backup->offsite_label)->toBe('Local only');

    expect(Storage::disk('offsite')->allFiles())->toBe([]);
});

test('a key is generated rather than silently writing an unencrypted copy', function () {
    // A shop that ticked "encrypt" and typed nothing must not end up with
    // plaintext in a bucket because of it.
    Setting::set('backup_encryption_key', '');

    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->offsite_encrypted)->toBeTrue()
        ->and(app(BackupService::class)->hasEncryptionKey())->toBeTrue()
        ->and(substr(uploadedBytes($backup), 0, 8))->toBe(BackupEncrypter::MAGIC);

    // And the generated key opens it.
    $enc = $this->workspace . DIRECTORY_SEPARATOR . 'gen.gz.enc';
    file_put_contents($enc, uploadedBytes($backup));

    app(BackupEncrypter::class)->decryptFile(
        $enc,
        $this->workspace . DIRECTORY_SEPARATOR . 'gen.gz',
        app(BackupService::class)->encryptionKey()
    );

    expect(is_file($this->workspace . DIRECTORY_SEPARATOR . 'gen.gz'))->toBeTrue();
});

// ------------------------------------------------------------ when it goes wrong

test('a failed upload still leaves a usable local backup, with the reason recorded', function () {
    ($this->failOffsiteWith)('cURL error 6: Could not resolve host: s3.example.com');

    $backup = app(BackupService::class)->run($this->admin);

    // The local copy is a success and must not be made to look otherwise.
    expect($backup->status)->toBe(Backup::STATUS_SUCCESS)
        ->and($backup->error)->toBeNull()
        ->and(is_file($backup->path))->toBeTrue()
        ->and(file_get_contents($backup->path))->toBe($this->plaintext);

    // ...and the second copy is owed, with a reason and a time to try again.
    expect($backup->offsite_status)->toBe(Backup::OFFSITE_PENDING)
        ->and($backup->offsite_error)->toContain('Could not resolve host')
        ->and($backup->offsite_attempts)->toBe(1)
        ->and($backup->offsite_next_attempt_at)->not->toBeNull()
        ->and($backup->offsite_next_attempt_at->isFuture())->toBeTrue()
        ->and($backup->offsiteOwed())->toBeTrue();
});

test('a connection problem is retried; a refused key is reported as needing a person', function () {
    /*
     * The distinction that decides whether a shop ever finds out. A dropped
     * line heals by itself and must not page anybody; a mistyped secret never
     * heals, and retrying it quietly for six months is how a shop ends up with
     * no offsite backups and no idea.
     */
    ($this->failOffsiteWith)('cURL error 7: Failed to connect to s3.example.com port 443: Connection refused');
    $flaky = app(BackupService::class)->run($this->admin);

    ($this->failOffsiteWith)('AccessDenied: Access Denied (status code: 403)');
    $refused = app(BackupService::class)->run($this->admin);

    expect($flaky->offsite_status)->toBe(Backup::OFFSITE_PENDING)
        ->and($flaky->offsite_label)->toBe('Waiting to upload')
        ->and($refused->offsite_status)->toBe(Backup::OFFSITE_FAILED)
        ->and($refused->offsite_label)->toBe('Upload refused')
        ->and($refused->offsite_error)->toContain('Access Denied');

    // The transient one is tried again in minutes, the permanent one in a day:
    // long enough not to hammer a 403 all night, short enough that fixing the
    // key means tonight's run catches up without anybody pressing anything.
    expect($flaky->offsite_next_attempt_at->lessThan(now()->addHour()))->toBeTrue()
        ->and($refused->offsite_next_attempt_at->greaterThan(now()->addHours(20)))->toBeTrue();
});

test('missing credentials are caught before anything is sent anywhere', function () {
    /*
     * An empty access key is not a connection problem and must not be reported
     * as one, retried for a week, or left to time out: it is a sentence saying
     * which box to fill in. Checked with no network call at all.
     */
    config(['filesystems.disks.nothing-filled-in' => [
        'driver' => 's3',
        'key' => '',
        'secret' => '',
        'bucket' => '',
        'region' => 'us-east-1',
        'throw' => true,
    ]]);
    Setting::set('backup_offsite_disk', 'nothing-filled-in');

    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->succeeded())->toBeTrue()
        ->and($backup->offsite_status)->toBe(Backup::OFFSITE_FAILED)
        ->and($backup->offsite_error)->toContain('AWS_ACCESS_KEY_ID')
        ->and($backup->offsite_error)->toContain('AWS_BUCKET')
        ->and($backup->offsite_error)->toContain('.env');
});

test('a destination that is not configured at all is refused rather than guessed at', function () {
    Setting::set('backup_offsite_disk', 'a-disk-somebody-deleted');

    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->succeeded())->toBeTrue()
        ->and($backup->offsite_status)->toBe(Backup::OFFSITE_FAILED)
        ->and($backup->offsite_error)->toContain('not configured');
});

test('an upload that arrives short is deleted rather than left looking like a backup', function () {
    // A half-finished multipart upload, a proxy that ate the body: from here
    // they all look like success, which is why the object is measured again.
    $liar = Mockery::mock()->shouldIgnoreMissing();
    $liar->shouldReceive('writeStream')->andReturn(true);
    $liar->shouldReceive('exists')->andReturn(true);
    $liar->shouldReceive('size')->andReturn(12);
    $liar->shouldReceive('delete')->once()->andReturn(true);

    Storage::set('offsite', $liar);

    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->succeeded())->toBeTrue()
        ->and($backup->offsite_status)->toBe(Backup::OFFSITE_PENDING)
        ->and($backup->offsite_error)->toContain('arrived')
        ->and($backup->offsite_error)->toContain('removed');
});

test('an upload that vanishes after a successful write is not recorded as offsite', function () {
    $liar = Mockery::mock()->shouldIgnoreMissing();
    $liar->shouldReceive('writeStream')->andReturn(true);
    $liar->shouldReceive('exists')->andReturn(false);

    Storage::set('offsite', $liar);

    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->offsite_status)->toBe(Backup::OFFSITE_PENDING)
        ->and($backup->offsite_error)->toContain('not in the bucket');
});

test('trouble nobody recognises stops being treated as temporary', function () {
    // Otherwise an unrecognised permanent fault retries for ever and never
    // says so, which is the same as having no backup, only noisier.
    ($this->failOffsiteWith)('Something nobody has seen before went wrong');

    $backup = app(BackupService::class)->run($this->admin);
    expect($backup->offsite_status)->toBe(Backup::OFFSITE_PENDING);

    $service = app(BackupService::class);

    for ($attempt = 2; $attempt <= BackupService::MAX_TRANSIENT_ATTEMPTS; $attempt++) {
        $service->sendOffsite($backup->fresh());
    }

    $backup = $backup->fresh();

    expect($backup->offsite_attempts)->toBe(BackupService::MAX_TRANSIENT_ATTEMPTS)
        ->and($backup->offsite_status)->toBe(Backup::OFFSITE_FAILED)
        ->and($backup->offsite_error)->toContain('attempts');
});

test('the service never throws, whatever the destination does', function () {
    // A scheduled run that explodes is a backup that silently stops happening.
    ($this->failOffsiteWith)('kaboom');

    expect(fn () => app(BackupService::class)->run($this->admin))->not->toThrow(Throwable::class);

    Storage::set('offsite', Mockery::mock()->shouldIgnoreMissing());

    expect(fn () => app(BackupService::class)->run($this->admin))->not->toThrow(Throwable::class)
        ->and(fn () => app(BackupService::class)->uploadPending(force: true))->not->toThrow(Throwable::class)
        ->and(fn () => app(BackupService::class)->testConnection())->not->toThrow(Throwable::class);
});

test('failures are sorted into the ones worth retrying and the ones that are not', function () {
    $service = app(BackupService::class);

    foreach ([
        'InvalidAccessKeyId: The AWS Access Key Id you provided does not exist',
        'SignatureDoesNotMatch: The request signature we calculated does not match',
        'AccessDenied (403 Forbidden)',
        'NoSuchBucket: The specified bucket does not exist',
        'Error retrieving credentials from the instance profile metadata service',
        'ExpiredToken: The provided token has expired',
    ] as $message) {
        expect($service->failureKind($message))->toBe(BackupService::FAILURE_PERMANENT, $message);
    }

    foreach ([
        'cURL error 6: Could not resolve host: s3.example.com',
        'Connection refused',
        'Operation timed out after 30000 milliseconds',
        'Error executing PutObject: 503 Service Unavailable',
        'Something completely unrecognised',
    ] as $message) {
        expect($service->failureKind($message))->toBe(BackupService::FAILURE_TRANSIENT, $message);
    }
});

// ----------------------------------------------------------------- the retry queue

test('a backup that could not be uploaded is retried on the next run', function () {
    ($this->failOffsiteWith)('cURL error 6: Could not resolve host: s3.example.com');

    $stranded = app(BackupService::class)->run($this->admin);
    expect($stranded->offsite_status)->toBe(Backup::OFFSITE_PENDING);

    // The connection comes back, and so does the next scheduled run. The clock
    // moves because each failure sets its own backoff - the point of which is
    // that a run five seconds later does not hammer a dead endpoint.
    ($this->fixOffsite)();
    $this->travelTo(now()->addMinutes(30));

    app(BackupService::class)->run($this->admin);

    $stranded = $stranded->fresh();

    expect($stranded->offsite_status)->toBe(Backup::OFFSITE_UPLOADED)
        ->and($stranded->offsite_path)->not->toBeNull();

    Storage::disk('offsite')->assertExists($stranded->offsite_path);

    $this->travelBack();
});

test('the backoff is respected, so a run moments later does not hammer the destination', function () {
    ($this->failOffsiteWith)('cURL error 6: Could not resolve host: s3.example.com');
    $stranded = app(BackupService::class)->run($this->admin);

    ($this->fixOffsite)();

    // No time has passed: the next attempt is not due yet.
    expect(app(BackupService::class)->uploadPending())->toBe(['uploaded' => 0, 'failed' => 0])
        ->and($stranded->fresh()->offsite_status)->toBe(Backup::OFFSITE_PENDING);

    // ...but a person pressing the button is not made to wait.
    expect(app(BackupService::class)->uploadPending(force: true))->toBe(['uploaded' => 1, 'failed' => 0])
        ->and($stranded->fresh()->offsite_status)->toBe(Backup::OFFSITE_UPLOADED);
});

test('a backup whose local copy has gone is not retried for ever', function () {
    ($this->failOffsiteWith)('cURL error 6: Could not resolve host: s3.example.com');
    $stranded = app(BackupService::class)->run($this->admin);

    unlink($stranded->path);
    ($this->fixOffsite)();

    app(BackupService::class)->uploadPending(force: true);

    expect($stranded->fresh()->offsite_error)->toContain('nothing left to upload');
});

// ------------------------------------------------------------------- retention

test('a local backup is never pruned while its offsite copy is still owed', function () {
    /*
     * The rule that outranks every retention setting. While the upload is owed,
     * the local file is the only copy of the shop's history in existence -
     * deleting it because it is the third of two, or eight days old, would be
     * this feature destroying the very thing it was added to protect.
     */
    ($this->failOffsiteWith)('cURL error 6: Could not resolve host: s3.example.com');

    $service = app(BackupService::class);

    $first = $service->run($this->admin);
    $second = $service->run($this->admin);
    $third = $service->run($this->admin);

    expect(Backup::count())->toBe(3);

    // Both rules, as aggressively as the form allows.
    Setting::set('backup_retention_count', 1);
    Setting::set('backup_retention_days', 1);
    $first->forceFill(['created_at' => now()->subYear()])->save();

    $service->prune();

    expect(Backup::count())->toBe(3)
        ->and(is_file($first->fresh()->path))->toBeTrue()
        ->and(is_file($second->fresh()->path))->toBeTrue()
        ->and(is_file($third->fresh()->path))->toBeTrue();
});

test('once the offsite copy exists, local retention prunes the file but keeps the record', function () {
    /*
     * Local retention is usually days and offsite retention months. Deleting
     * the row with the local file would take the only note of where the remote
     * object is, and nothing would ever tidy the bucket again.
     */
    $service = app(BackupService::class);

    $old = $service->run($this->admin);
    $service->run($this->admin);   // so $old is not the protected newest one

    $old->forceFill(['created_at' => now()->subYear()])->save();
    Setting::set('backup_retention_days', 7);

    $service->prune();

    $old = $old->fresh();

    expect($old)->not->toBeNull()
        ->and(is_file($old->path))->toBeFalse()
        ->and($old->local_pruned_at)->not->toBeNull()
        ->and($old->hasLocalCopy())->toBeFalse()
        // The offsite copy, and the record of it, are untouched.
        ->and($old->offsite_status)->toBe(Backup::OFFSITE_UPLOADED);

    Storage::disk('offsite')->assertExists($old->offsite_path);
});

test('offsite retention deletes the old objects and then the row', function () {
    $service = app(BackupService::class);

    $old = $service->run($this->admin);
    $newest = $service->run($this->admin);

    $oldPath = $old->offsite_path;

    // Both copies are now well past their time.
    $old->forceFill([
        'offsite_uploaded_at' => now()->subDays(200),
        'created_at' => now()->subDays(200),
    ])->save();

    Setting::set('backup_offsite_retention_days', 90);
    Setting::set('backup_retention_days', 30);

    $service->prune();

    expect(Backup::find($old->id))->toBeNull()
        ->and(Backup::find($newest->id))->not->toBeNull();

    Storage::disk('offsite')->assertMissing($oldPath);
    Storage::disk('offsite')->assertExists($newest->fresh()->offsite_path);
});

test('an expired offsite copy leaves the row alone while the local file is still here', function () {
    $service = app(BackupService::class);

    $old = $service->run($this->admin);
    $service->run($this->admin);

    $oldPath = $old->offsite_path;
    $old->forceFill(['offsite_uploaded_at' => now()->subDays(200)])->save();

    Setting::set('backup_offsite_retention_days', 90);   // local retention stays off

    $service->prune();

    $old = $old->fresh();

    expect($old)->not->toBeNull()
        ->and($old->offsite_status)->toBe(Backup::OFFSITE_EXPIRED)
        ->and($old->offsite_label)->toBe('Offsite copy expired')
        ->and(is_file($old->path))->toBeTrue();

    Storage::disk('offsite')->assertMissing($oldPath);
});

test('the most recent offsite copy is never deleted, whatever retention says', function () {
    $service = app(BackupService::class);
    $only = $service->run($this->admin);

    $only->forceFill(['offsite_uploaded_at' => now()->subYears(5)])->save();
    Setting::set('backup_offsite_retention_days', 1);

    $service->prune();

    expect($only->fresh()->offsite_status)->toBe(Backup::OFFSITE_UPLOADED);
    Storage::disk('offsite')->assertExists($only->fresh()->offsite_path);
});

test('offsite retention of 0 keeps the copies for ever', function () {
    $service = app(BackupService::class);

    $old = $service->run($this->admin);
    $service->run($this->admin);

    $old->forceFill(['offsite_uploaded_at' => now()->subYears(5)])->save();
    Setting::set('backup_offsite_retention_days', 0);

    $service->prune();

    expect($old->fresh()->offsite_status)->toBe(Backup::OFFSITE_UPLOADED);
});

test('a download falls back to the offsite copy once the local file has been pruned', function () {
    $service = app(BackupService::class);
    $backup = $service->run($this->admin);

    // What local retention leaves behind.
    unlink($backup->path);
    $backup->forceFill(['local_pruned_at' => now()])->save();

    $this->get(route('settings.backups.download', $backup))
        ->assertOk()
        ->assertDownload(basename($backup->offsite_path));
});

// --------------------------------------------------------------- test connection

test('the test connection action writes a probe and deletes it again', function () {
    /*
     * Write-then-delete, not a listing: write and delete are the two
     * permissions this feature needs, and a key that can list a bucket but not
     * write to it passes a read-only check and fails every single backup.
     */
    $response = $this->postJson(route('settings.backups.offsite.test'), [
        'backup_offsite_disk' => 'offsite',
        'backup_offsite_path' => 'shop-backups',
    ])->assertOk();

    expect($response->json('ok'))->toBeTrue()
        ->and($response->json('message'))->toContain('Connected')
        ->and($response->json('message'))->toContain('shop-backups');

    // No litter left in the bucket.
    expect(Storage::disk('offsite')->allFiles())->toBe([]);

    expect(AuditLog::where('action', 'Tested Offsite Backup')->exists())->toBeTrue();
});

test('the test connection action says what is wrong in words a shopkeeper can act on', function () {
    config(['filesystems.disks.nothing-filled-in' => [
        'driver' => 's3',
        'key' => '',
        'secret' => 'x',
        'bucket' => 'x',
        'region' => 'us-east-1',
    ]]);

    $response = $this->postJson(route('settings.backups.offsite.test'), [
        'backup_offsite_disk' => 'nothing-filled-in',
    ])->assertOk();

    expect($response->json('ok'))->toBeFalse()
        ->and($response->json('kind'))->toBe(BackupService::FAILURE_PERMANENT)
        ->and($response->json('message'))->toContain('AWS_ACCESS_KEY_ID')
        ->and($response->json('hint'))->toContain('access key');

    expect(AuditLog::where('action', 'Offsite Backup Test Failed')->exists())->toBeTrue();
});

test('the test connection action reports an unreachable destination as something to wait out', function () {
    ($this->failOffsiteWith)('cURL error 6: Could not resolve host: s3.example.com');

    // put() is what the probe uses, and the stand-in refuses everything.
    $broken = Mockery::mock()->shouldIgnoreMissing();
    $broken->shouldReceive('put')->andThrow(new RuntimeException('cURL error 6: Could not resolve host: s3.example.com'));
    Storage::set('offsite', $broken);

    $response = $this->postJson(route('settings.backups.offsite.test'))->assertOk();

    expect($response->json('ok'))->toBeFalse()
        ->and($response->json('kind'))->toBe(BackupService::FAILURE_TRANSIENT)
        ->and($response->json('message'))->toContain('Could not resolve host')
        ->and($response->json('hint'))->toContain('internet');
});

test('the test connection action notices a destination that accepts and discards', function () {
    $blackhole = Mockery::mock()->shouldIgnoreMissing();
    $blackhole->shouldReceive('put')->andReturn(true);
    $blackhole->shouldReceive('exists')->andReturn(false);
    Storage::set('offsite', $blackhole);

    $response = $this->postJson(route('settings.backups.offsite.test'))->assertOk();

    expect($response->json('ok'))->toBeFalse()
        ->and($response->json('message'))->toContain('not there afterwards');
});

test('the test connection action warns when it can write but not delete', function () {
    $writeOnly = Mockery::mock()->shouldIgnoreMissing();
    $writeOnly->shouldReceive('put')->andReturn(true);
    $writeOnly->shouldReceive('exists')->andReturn(true);
    $writeOnly->shouldReceive('delete')->andReturn(false);
    Storage::set('offsite', $writeOnly);

    $response = $this->postJson(route('settings.backups.offsite.test'))->assertOk();

    // Not fatal - backups would upload - but retention could never tidy up.
    expect($response->json('ok'))->toBeTrue()
        ->and($response->json('message'))->toContain('could not be deleted')
        ->and($response->json('message'))->toContain('delete permission');
});

test('the test connection action refuses a disk that is not configured, and a path that climbs out', function () {
    $this->postJson(route('settings.backups.offsite.test'), ['backup_offsite_disk' => 'made-up'])
        ->assertStatus(422);

    $this->postJson(route('settings.backups.offsite.test'), ['backup_offsite_path' => '../../etc'])
        ->assertStatus(422);

    $this->postJson(route('settings.backups.offsite.test'), ['backup_offsite_path' => '/absolute'])
        ->assertStatus(422);
});

// ----------------------------------------------------------------- upload by hand

test('the upload now action flushes the backups that are waiting', function () {
    ($this->failOffsiteWith)('cURL error 6: Could not resolve host: s3.example.com');

    app(BackupService::class)->run($this->admin);
    app(BackupService::class)->run($this->admin);

    expect(app(BackupService::class)->pendingOffsiteCount())->toBe(2);

    ($this->fixOffsite)();

    $response = $this->postJson(route('settings.backups.offsite.upload'))->assertOk();

    expect($response->json('ok'))->toBeTrue()
        ->and($response->json('message'))->toContain('2 backup(s) uploaded')
        ->and(app(BackupService::class)->pendingOffsiteCount())->toBe(0)
        ->and(Storage::disk('offsite')->allFiles())->toHaveCount(2);

    expect(AuditLog::where('action', 'Uploaded Backups Offsite')->exists())->toBeTrue();
});

test('the upload now action says plainly when there is nothing to do, or nowhere to send it', function () {
    app(BackupService::class)->run($this->admin);

    expect($this->postJson(route('settings.backups.offsite.upload'))->json('message'))
        ->toContain('already has an offsite copy');

    Setting::set('backup_offsite_enabled', '0');

    $response = $this->postJson(route('settings.backups.offsite.upload'))->assertOk();

    expect($response->json('ok'))->toBeFalse()
        ->and($response->json('message'))->toContain('switched off');
});

test('one backup can be uploaded on its own from the history', function () {
    ($this->failOffsiteWith)('cURL error 6: Could not resolve host: s3.example.com');
    $backup = app(BackupService::class)->run($this->admin);

    ($this->fixOffsite)();

    $this->post(route('settings.backups.offsite.one', $backup))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($backup->fresh()->offsite_status)->toBe(Backup::OFFSITE_UPLOADED);
    expect(AuditLog::where('action', 'Uploaded Backup Offsite')->where('record_id', $backup->id)->exists())
        ->toBeTrue();
});

test('uploading one backup that still cannot go reports the reason rather than claiming success', function () {
    ($this->failOffsiteWith)('AccessDenied: Access Denied (403)');
    $backup = app(BackupService::class)->run($this->admin);

    $this->post(route('settings.backups.offsite.one', $backup))
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('a manager cannot test the connection or upload backups', function () {
    $backup = app(BackupService::class)->run($this->admin);

    $this->actingAs(User::factory()->manager()->create());

    $this->postJson(route('settings.backups.offsite.test'))->assertForbidden();
    $this->postJson(route('settings.backups.offsite.upload'))->assertForbidden();
    $this->post(route('settings.backups.offsite.one', $backup))->assertForbidden();
});

// --------------------------------------------------------------- email reporting

test('no report is sent unless reports are switched on', function () {
    app(BackupService::class)->run($this->admin);

    Mail::assertNothingSent();
});

test('a report is sent after a run, with the facts and no attachment by default', function () {
    Setting::set('backup_email_report', '1');
    Setting::set('backup_email_to', 'owner@example.com, bookkeeper@example.com');

    $backup = app(BackupService::class)->run($this->admin);

    Mail::assertSent(BackupReportMail::class, function (BackupReportMail $mail) use ($backup) {
        return $mail->hasTo('owner@example.com')
            && $mail->hasTo('bookkeeper@example.com')
            && $mail->attachmentPath === null
            && $mail->backup->id === $backup->id
            && $mail->facts['offsiteEnabled'] === true
            && $mail->facts['offsiteKept'] === 1;
    });
});

test('the report says so when the backup failed, and does not call it a success', function () {
    Setting::set('backup_email_report', '1');
    Setting::set('backup_email_to', 'owner@example.com');

    unlink($this->sourceDb);

    app(BackupService::class)->run($this->admin);

    Mail::assertSent(BackupReportMail::class, function (BackupReportMail $mail) {
        return !$mail->backup->succeeded()
            && str_contains($mail->envelope()->subject, 'BACKUP FAILED');
    });
});

test('the report subject distinguishes a local success with a refused offsite copy', function () {
    Setting::set('backup_email_report', '1');
    Setting::set('backup_email_to', 'owner@example.com');
    ($this->failOffsiteWith)('AccessDenied: Access Denied (403)');

    app(BackupService::class)->run($this->admin);

    Mail::assertSent(BackupReportMail::class, function (BackupReportMail $mail) {
        return str_contains($mail->envelope()->subject, 'offsite copy FAILED');
    });
});

test('with no address anywhere, nothing is sent rather than failing the run', function () {
    Setting::set('backup_email_report', '1');
    Setting::set('backup_email_to', '');
    Setting::set('store_email', '');

    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->succeeded())->toBeTrue();
    Mail::assertNothingSent();
});

test('the shop email address is used when no report address is given', function () {
    Setting::set('backup_email_report', '1');
    Setting::set('backup_email_to', '');
    Setting::set('store_email', 'shop@example.com');

    app(BackupService::class)->run($this->admin);

    Mail::assertSent(BackupReportMail::class, fn (BackupReportMail $mail) => $mail->hasTo('shop@example.com'));
});

test('the backup is attached when it is encrypted and under the limit', function () {
    Setting::set('backup_email_report', '1');
    Setting::set('backup_email_attach', '1');
    Setting::set('backup_email_to', 'owner@example.com');
    Setting::set('backup_email_max_mb', 15);

    app(BackupService::class)->run($this->admin);

    Mail::assertSent(BackupReportMail::class, function (BackupReportMail $mail) {
        return $mail->attachmentPath !== null
            && str_ends_with($mail->attachmentPath, '.gz.enc')
            && $mail->attachmentNote === null;
    });
});

test('an oversized backup is reported without the attachment, and says why', function () {
    /*
     * The failure mode this guards against is the quiet one: a growing shop
     * crosses a provider's attachment ceiling one night and the backup simply
     * stops arriving, with nothing said. Incompressible data, so compression
     * cannot rescue it.
     */
    file_put_contents($this->sourceDb, random_bytes(1024 * 1024 + 50000));

    Setting::set('backup_email_report', '1');
    Setting::set('backup_email_attach', '1');
    Setting::set('backup_email_to', 'owner@example.com');
    Setting::set('backup_email_max_mb', 1);

    app(BackupService::class)->run($this->admin);

    Mail::assertSent(BackupReportMail::class, function (BackupReportMail $mail) {
        return $mail->attachmentPath === null
            && str_contains((string) $mail->attachmentNote, 'over the')
            && str_contains((string) $mail->attachmentNote, 'offsite copy is the one to rely on');
    });
});

test('an unencrypted backup is refused as an attachment, not sent anyway', function () {
    Setting::set('backup_encrypt', '0');
    Setting::set('backup_email_report', '1');
    Setting::set('backup_email_attach', '1');
    Setting::set('backup_email_to', 'owner@example.com');

    app(BackupService::class)->run($this->admin);

    Mail::assertSent(BackupReportMail::class, function (BackupReportMail $mail) {
        return $mail->attachmentPath === null
            && str_contains((string) $mail->attachmentNote, 'encryption is switched off')
            && str_contains((string) $mail->attachmentNote, 'every customer');
    });
});

test('the report renders without blowing up', function () {
    // A mail view is rendered somewhere nobody is looking; a missing variable
    // in it is a backup report that never arrives.
    Setting::set('backup_email_report', '1');
    Setting::set('backup_email_to', 'owner@example.com');

    $backup = app(BackupService::class)->run($this->admin);

    $html = (new BackupReportMail($backup, [
        'shop' => 'Mama Put Stores',
        'when' => '14 Oct 2026, 02:00',
        'localDestination' => $this->destination,
        'offsiteEnabled' => true,
        'offsiteDestination' => 'disk "offsite" (shop-backups/)',
        'encrypted' => true,
        'localKept' => 1,
        'offsiteKept' => 1,
        'localRetention' => 30,
        'localRetentionCount' => 0,
        'offsiteRetention' => 90,
        'pending' => 0,
        'needsSchedule' => true,
    ]))->render();

    expect($html)->toContain('Mama Put Stores')
        ->toContain('shop-backups')
        ->toContain('Automatic backups are switched off');
});

// ------------------------------------------------------------------- the settings

test('the offsite settings are validated and stored', function () {
    $this->post(route('settings.update'), [
        'backup_offsite_enabled' => '1',
        'backup_offsite_disk' => 'offsite',
        'backup_offsite_path' => 'shop/backups-2026',
        'backup_offsite_retention_days' => 120,
        'backup_encrypt' => '1',
        'backup_email_report' => '1',
        'backup_email_to' => 'owner@example.com, book@example.com',
        'backup_email_attach' => '1',
        'backup_email_max_mb' => 10,
    ])->assertSessionHasNoErrors();

    expect(setting('backup_offsite_enabled'))->toBe('1')
        ->and(setting('backup_offsite_disk'))->toBe('offsite')
        ->and(setting('backup_offsite_path'))->toBe('shop/backups-2026')
        ->and(setting('backup_offsite_retention_days'))->toBe('120')
        ->and(setting('backup_email_report'))->toBe('1')
        ->and(setting('backup_email_to'))->toBe('owner@example.com, book@example.com')
        ->and(setting('backup_email_max_mb'))->toBe('10');
});

test('rubbish in the offsite settings is refused rather than stored and read back later', function () {
    $this->post(route('settings.update'), ['backup_offsite_disk' => 'made-up'])
        ->assertSessionHasErrors('backup_offsite_disk');

    // No fallback for an offsite destination: writing the shop's data somewhere
    // other than where they said is worse than refusing the value.
    $this->post(route('settings.update'), ['backup_offsite_disk' => BackupService::LOCAL])
        ->assertSessionHasErrors('backup_offsite_disk');

    foreach (['../../etc/passwd', '/absolute/path', 'C:\\windows', 'has spaces'] as $path) {
        $this->post(route('settings.update'), ['backup_offsite_path' => $path])
            ->assertSessionHasErrors('backup_offsite_path');
    }

    $this->post(route('settings.update'), ['backup_offsite_retention_days' => -1])
        ->assertSessionHasErrors('backup_offsite_retention_days');

    $this->post(route('settings.update'), ['backup_email_to' => 'not-an-address'])
        ->assertSessionHasErrors('backup_email_to');

    $this->post(route('settings.update'), ['backup_email_to' => 'fine@example.com, broken@'])
        ->assertSessionHasErrors('backup_email_to');

    // Above 25 MB providers reject or truncate, so the box will not take it.
    $this->post(route('settings.update'), ['backup_email_max_mb' => 50])
        ->assertSessionHasErrors('backup_email_max_mb');

    $this->post(route('settings.update'), ['backup_email_max_mb' => 0])
        ->assertSessionHasErrors('backup_email_max_mb');

    // A short key is the failure nobody notices: it still uploads, still looks
    // encrypted, and is openable with a word list.
    $this->post(route('settings.update'), ['backup_encryption_key' => 'short'])
        ->assertSessionHasErrors('backup_encryption_key');
});

test('the encryption key is stored encrypted at rest, because it is inside the dump', function () {
    /*
     * The settings table is in the backup. A key sitting there in plaintext
     * would be shipped to the bucket alongside the data it protects - encrypted
     * with itself, which is not a plan. Crypt means the copy inside the dump is
     * useless without APP_KEY, which lives in .env and not in the database.
     */
    $this->post(route('settings.update'), [
        'backup_encryption_key' => 'a-brand-new-key-long-enough-to-pass-validation',
    ])->assertSessionHasNoErrors();

    $stored = Setting::where('key', 'backup_encryption_key')->value('value');

    expect($stored)->not->toBe('a-brand-new-key-long-enough-to-pass-validation')
        ->and($stored)->not->toContain('brand-new-key')
        ->and(app(BackupService::class)->encryptionKey())->toBe('a-brand-new-key-long-enough-to-pass-validation');

    expect(AuditLog::where('action', 'Changed Backup Encryption Key')->exists())->toBeTrue();
});

test('saving the settings form with the key box empty keeps the key already saved', function () {
    /*
     * Every other setting treats an empty field as "clear it". That rule would
     * be a disaster here: the box is blank on every page load, so saving the
     * Store tab would wipe the key and orphan every backup in the bucket.
     */
    $this->post(route('settings.update'), ['store_name' => 'Mama Put Stores', 'backup_encryption_key' => ''])
        ->assertSessionHasNoErrors();

    expect(app(BackupService::class)->encryptionKey())->toBe($this->key)
        ->and(app(BackupService::class)->hasEncryptionKey())->toBeTrue();
});

test('the encryption key is never rendered back to the browser', function () {
    $distinctive = 'ZZZ-never-render-me-0123456789abcdefghijklmnop';
    app(BackupService::class)->storeEncryptionKey($distinctive);

    $stored = (string) Setting::where('key', 'backup_encryption_key')->value('value');

    foreach (['settings.index', 'settings.backups', 'test.offsite.partial'] as $screen) {
        $html = $this->get(route($screen))->assertOk()->getContent();

        expect($html)->not->toContain($distinctive)
            // nor the ciphertext, which is just as much of a leak once APP_KEY
            // is known - and APP_KEY is in a file on the same server.
            ->and($html)->not->toContain($stored);
    }

    // Not even in the settings array the view is handed.
    $settings = $this->get(route('settings.index'))->viewData('settings');

    expect($settings)->toBeArray()->not->toHaveKey('backup_encryption_key');
});

test('the settings screen says whether a key is set without showing it', function () {
    $html = $this->get(route('test.offsite.partial'))->assertOk()->getContent();

    expect($html)->toContain('a key is saved')
        ->and($html)->toContain('salespoint:backup:key --show');

    Setting::set('backup_encryption_key', '');

    expect($this->get(route('test.offsite.partial'))->getContent())->toContain('not set yet');
});

// ------------------------------------------------------- "when online, set it up"

test('offsite backup with the schedule off says so and offers to switch it on', function () {
    /*
     * The one combination that looks set up and is not: a shop that typed in
     * bucket credentials but has nothing taking a backup. Said on screen, and
     * offered - not switched on behind their back, because a nightly job
     * appearing on somebody's server is not ours to decide.
     */
    Setting::set('backup_enabled', '0');

    expect(app(BackupService::class)->needsSchedule())->toBeTrue();

    $html = $this->get(route('test.offsite.partial'))->assertOk()->getContent();

    expect($html)->toContain('Automatic backups are switched off')
        ->and($html)->toContain('Switch automatic backups on');

    // With the schedule on, the nudge is gone.
    Setting::set('backup_enabled', '1');

    expect(app(BackupService::class)->needsSchedule())->toBeFalse()
        ->and($this->get(route('test.offsite.partial'))->getContent())
        ->not->toContain('Switch automatic backups on');
});

test('the nudge does not appear when offsite backup is off', function () {
    Setting::set('backup_offsite_enabled', '0');
    Setting::set('backup_enabled', '0');

    expect(app(BackupService::class)->needsSchedule())->toBeFalse();
});

test('the settings partial renders, and names the waiting backups', function () {
    ($this->failOffsiteWith)('cURL error 6: Could not resolve host: s3.example.com');
    app(BackupService::class)->run($this->admin);

    $html = $this->get(route('test.offsite.partial'))->assertOk()->getContent();

    expect($html)->toContain('Send a copy of every backup offsite')
        ->toContain('1 backup still')
        ->toContain('Upload the 1 waiting backup now')
        ->toContain('disk &quot;offsite&quot; (shop-backups/)')
        // No leftover component tags: a Blade @if inside a component tag's
        // attributes renders the tag as literal markup and the field vanishes.
        ->not->toContain('<x-');
});

// ----------------------------------------------------------------- the command

test('the command reports the offsite outcome and exits non-zero when it was refused', function () {
    Setting::set('backup_enabled', '1');

    $this->artisan('salespoint:backup')->assertSuccessful();
    expect(Backup::latest('id')->first()->offsite_status)->toBe(Backup::OFFSITE_UPLOADED);

    ($this->failOffsiteWith)('AccessDenied: Access Denied (403)');

    // The backup worked, but the only copy is on the same machine as the
    // database - a monitor should see that.
    $this->artisan('salespoint:backup')->assertFailed();
});

test('the command can be asked to do nothing but flush the waiting uploads', function () {
    ($this->failOffsiteWith)('cURL error 6: Could not resolve host: s3.example.com');
    app(BackupService::class)->run($this->admin);

    ($this->fixOffsite)();

    $before = Backup::count();

    $this->artisan('salespoint:backup --upload-only')->assertSuccessful();

    // No new backup was taken, and the waiting one went.
    expect(Backup::count())->toBe($before)
        ->and(app(BackupService::class)->pendingOffsiteCount())->toBe(0);
});

test('upload-only does nothing at all when offsite backup is off', function () {
    Setting::set('backup_offsite_enabled', '0');

    $this->artisan('salespoint:backup --upload-only')->assertSuccessful();

    expect(Backup::count())->toBe(0);
});

// ------------------------------------------------- the decrypt and restore commands

test('the decrypt command opens an encrypted backup with the key from Settings', function () {
    $backup = app(BackupService::class)->run($this->admin);

    $enc = $this->workspace . DIRECTORY_SEPARATOR . 'fetched.sqlite.gz.enc';
    file_put_contents($enc, uploadedBytes($backup));

    $this->artisan('salespoint:backup:decrypt', ['file' => $enc])->assertSuccessful();

    $dump = $this->workspace . DIRECTORY_SEPARATOR . 'fetched.sqlite';

    expect(is_file($dump))->toBeTrue()
        ->and(file_get_contents($dump))->toBe($this->plaintext);
});

test('the decrypt command refuses the wrong key instead of writing rubbish', function () {
    $backup = app(BackupService::class)->run($this->admin);

    $enc = $this->workspace . DIRECTORY_SEPARATOR . 'fetched.sqlite.gz.enc';
    file_put_contents($enc, uploadedBytes($backup));

    $this->artisan('salespoint:backup:decrypt', ['file' => $enc, '--key' => 'not-the-right-key-at-all'])
        ->assertFailed();

    expect(is_file($this->workspace . DIRECTORY_SEPARATOR . 'fetched.sqlite.gz'))->toBeFalse();
});

test('the decrypt command says what to do with a file that is not encrypted', function () {
    $plain = $this->workspace . DIRECTORY_SEPARATOR . 'plain.sql';
    file_put_contents($plain, 'INSERT INTO sales VALUES (1);');

    $this->artisan('salespoint:backup:decrypt', ['file' => $plain])->assertFailed();
    $this->artisan('salespoint:backup:decrypt', ['file' => $plain . '-missing'])->assertFailed();
});

test('the restore command fetches the offsite copy, checks it and decrypts it', function () {
    $backup = app(BackupService::class)->run($this->admin);

    // What the shop is left with after local retention: no local file, an
    // object in a bucket, and a row that remembers where it is.
    unlink($backup->path);
    $backup->forceFill(['local_pruned_at' => now()])->save();

    $out = $this->workspace . DIRECTORY_SEPARATOR . 'restored';

    $this->artisan('salespoint:backup:restore', ['--out' => $out])->assertSuccessful();

    $restored = $out . DIRECTORY_SEPARATOR . str_replace('.gz.enc', '', basename($backup->offsite_path));

    expect(is_file($restored))->toBeTrue()
        ->and(file_get_contents($restored))->toBe($this->plaintext);
});

test('the restore command refuses a copy that does not match its recorded checksum', function () {
    $backup = app(BackupService::class)->run($this->admin);

    unlink($backup->path);
    $backup->forceFill(['local_pruned_at' => now()])->save();

    // Bit-rot in the bucket, or somebody else's file in its place.
    Storage::disk('offsite')->put($backup->offsite_path, 'not the backup you uploaded');

    $this->artisan('salespoint:backup:restore', ['--out' => $this->workspace . DIRECTORY_SEPARATOR . 'restored'])
        ->assertFailed();
});

test('the restore command says so when a row has no copy left anywhere', function () {
    $backup = app(BackupService::class)->run($this->admin);

    unlink($backup->path);
    $backup->forceFill([
        'local_pruned_at' => now(),
        'offsite_status' => Backup::OFFSITE_EXPIRED,
        'offsite_path' => null,
    ])->save();

    $this->artisan('salespoint:backup:restore', [
        'backup' => $backup->id,
        '--out' => $this->workspace . DIRECTORY_SEPARATOR . 'restored',
    ])->assertFailed();

    $this->artisan('salespoint:backup:restore', ['backup' => 99999])->assertFailed();
});

test('the key command shows the key and can replace it', function () {
    $this->artisan('salespoint:backup:key --show')
        ->expectsOutput($this->key)
        ->assertSuccessful();

    // Replacing warns first: every backup already taken needs the old key.
    $this->artisan('salespoint:backup:key')
        ->expectsConfirmation('Replace the backup encryption key?', 'no')
        ->assertSuccessful();

    expect(app(BackupService::class)->encryptionKey())->toBe($this->key);

    $this->artisan('salespoint:backup:key')
        ->expectsConfirmation('Replace the backup encryption key?', 'yes')
        ->assertSuccessful();

    expect(app(BackupService::class)->encryptionKey())->not->toBe($this->key)
        ->and(app(BackupService::class)->encryptionKey())->toHaveLength(64);
});

test('a key that cannot be read back is refused loudly rather than quietly replaced', function () {
    /*
     * APP_KEY changed since the key was saved. Treating the unreadable value as
     * the key would start encrypting tonight's backup with something different
     * and leave every older one unopenable, with nothing said.
     */
    Setting::set('backup_encryption_key', base64_encode(json_encode([
        'iv' => base64_encode(random_bytes(16)),
        'value' => base64_encode(random_bytes(32)),
        'mac' => bin2hex(random_bytes(32)),
    ])));

    expect(fn () => app(BackupService::class)->encryptionKey())
        ->toThrow(RuntimeException::class, 'APP_KEY');

    // And a run records it rather than throwing out of the scheduler.
    $backup = app(BackupService::class)->run($this->admin);

    expect($backup->succeeded())->toBeTrue()
        ->and($backup->offsite_status)->toBe(Backup::OFFSITE_FAILED)
        ->and($backup->offsite_error)->toContain('APP_KEY');
});

// ------------------------------------------------------------------ the migration

test('the migration rolls back and forward again', function () {
    /*
     * A down() that does not work is a migration you cannot undo on a shop's
     * live database. SQLite in particular refuses to drop an indexed column
     * while the index is still there, which is the usual way this breaks.
     */
    $migration = require database_path('migrations/2026_10_14_000013_add_offsite_backup_columns.php');

    $migration->down();

    expect(Schema::hasColumn('backups', 'offsite_status'))->toBeFalse()
        ->and(Schema::hasColumn('backups', 'offsite_checksum'))->toBeFalse()
        ->and(Schema::hasColumn('backups', 'local_pruned_at'))->toBeFalse()
        // the columns that were there before are untouched
        ->and(Schema::hasColumn('backups', 'filename'))->toBeTrue();

    $migration->up();

    expect(Schema::hasColumn('backups', 'offsite_status'))->toBeTrue()
        ->and(Schema::hasColumn('backups', 'offsite_uploaded_at'))->toBeTrue()
        ->and(Schema::hasColumn('backups', 'local_pruned_at'))->toBeTrue();

    // And the default still means "we have not tried", not "it is offsite".
    $backup = app(BackupService::class)->dump();
    @unlink($backup);

    Setting::set('backup_offsite_enabled', '0');

    expect(app(BackupService::class)->run($this->admin)->offsite_status)->toBe(Backup::OFFSITE_OFF);
});

test('a backup is not scoped to a branch, because it is a copy of the whole database', function () {
    // Multi-branch landed alongside this work. A backup is the entire file -
    // every branch's data at once - so scoping one to a branch would mean a
    // branch manager "restoring" a database that is not theirs.
    expect(array_keys((new Backup)->getCasts()))->not->toContain('branch_id')
        ->and(Schema::hasColumn('backups', 'branch_id'))->toBeFalse();
});
