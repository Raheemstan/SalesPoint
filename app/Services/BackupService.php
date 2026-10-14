<?php

namespace App\Services;

use App\Mail\BackupReportMail;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Taking a copy of the database, by hand or on a schedule, and getting one of
 * those copies off the premises.
 *
 * The dump itself used to live in SettingController::downloadBackup(). It is
 * here now because the scheduler needs exactly the same thing, and a copy of
 * this logic in a command is a copy that will drift. The controller calls
 * through to it.
 *
 * Two things worth keeping in mind when reading this:
 *
 *  - config(), never env(). env() returns null once the config is cached, which
 *    is how an earlier version of this code silently produced empty backups.
 *  - mysqldump is given an argument *array* and the password through the
 *    environment: no shell to inject into, and the password never appears in
 *    the process list where any other user on the box could read it.
 *
 * A scheduled run must not throw. An uncaught exception in a nightly command is
 * an email nobody gets; a failed row in the backups table is visible on the
 * screen the shop already looks at. Every outward-facing method here either
 * returns a Backup row or an array of plain facts, and swallows what went wrong
 * into that row and the log.
 *
 * ## Offsite
 *
 * A backup in storage/app/backups is lost with the machine that holds the
 * database: one stolen till, one dead disk, one fire, one ransomware run. So
 * after a successful dump the file is compressed, encrypted and uploaded to an
 * S3-compatible bucket.
 *
 * S3-compatible rather than any one vendor's own API, because this service
 * already accepted a configured Laravel disk, which makes the whole thing close
 * to a config change - and Laravel's `s3` driver talks to Backblaze B2,
 * Cloudflare R2, Wasabi, DigitalOcean Spaces and a MinIO box in the back office
 * as well as it talks to AWS. The shop is not locked to one supplier.
 *
 * Email is a *notification* channel here, never the transport. Mail providers
 * cap attachments somewhere between 10 and 25 MB, and a POS database grows past
 * that quietly: the backup would simply stop arriving one night and nobody
 * would know. A report that says what happened is useful; a report that *is*
 * the backup is a time bomb.
 *
 * Google Drive is deliberately not offered: it needs an OAuth refresh-token
 * dance plus a third-party Flysystem adapter, and every tightening of Google's
 * app verification would break every install on the same day.
 */
class BackupService
{
    /**
     * The disk name recorded for "a plain directory on this machine" - the
     * default, and what an absolute `backup_path` means. Anything else in the
     * `disk` column is a configured Laravel filesystem disk.
     */
    public const LOCAL = 'filesystem';

    /** Something went wrong that trying again later could plausibly fix. */
    public const FAILURE_TRANSIENT = 'transient';

    /** Something went wrong that only a person can fix. */
    public const FAILURE_PERMANENT = 'permanent';

    /**
     * How many goes a transient-looking failure gets before it is reported as
     * something a person needs to look at.
     *
     * Without this, a destination that is quietly broken in a way we do not
     * recognise retries for ever and never says so - which is the same as not
     * having a backup, only noisier in the log.
     */
    public const MAX_TRANSIENT_ATTEMPTS = 5;

    /** How many waiting backups one run tries to catch up on. */
    public const CATCHUP_BATCH = 5;

    public function __construct(private BackupEncrypter $encrypter)
    {
    }

    /**
     * Run a backup end to end: dump, copy to the destination, record it, send
     * it offsite, catch up on anything owed, prune, report.
     *
     * Always returns a Backup row - check `status` for the local copy and
     * `offsite_status` for the second one. Never throws.
     *
     * @param  User|null  $user  null when the scheduler ran it
     */
    public function run(?User $user = null): Backup
    {
        $driver = $this->driver();
        $filename = $this->filename();
        $temp = null;
        $artifact = null;

        try {
            $temp = $this->dump($filename);
            [$disk, $path, $size] = $this->store($temp, $filename);

            $backup = Backup::create([
                'filename' => $filename,
                'path' => $path,
                'disk' => $disk,
                'driver' => $driver,
                'size_bytes' => $size,
                'status' => Backup::STATUS_SUCCESS,
                // Owed from the moment the local copy exists, so a crash
                // between here and the upload leaves it queued rather than
                // forgotten.
                'offsite_status' => $this->offsiteEnabled() ? Backup::OFFSITE_PENDING : Backup::OFFSITE_OFF,
                'created_by' => $user?->id,
            ]);

            /*
             * Compressing and encrypting is done once, from the temporary dump
             * that is still on disk, and the result is used for both the upload
             * and the email attachment. Doing it twice would mean gzipping the
             * shop's whole history twice for no reason.
             */
            if ($this->offsiteEnabled() || $this->emailAttachEnabled()) {
                $artifact = $this->packageSafely($backup, $temp);
            }

            /*
             * Only when there is something to send: a packaging failure has
             * already been recorded against the row, and trying to upload
             * nothing would overwrite that reason with a vaguer one.
             */
            if ($this->offsiteEnabled() && $artifact !== null) {
                $this->sendOffsite($backup, $artifact);
            }
        } catch (Throwable $e) {
            Log::error('Backup failed: ' . $e->getMessage());

            $backup = Backup::create([
                'filename' => $filename,
                'path' => '',
                'disk' => $this->diskName(),
                'driver' => $driver,
                'size_bytes' => 0,
                'status' => Backup::STATUS_FAILED,
                // Truncated: a mysqldump failure can run to pages of output, and
                // the first line is the part that says what to fix.
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'offsite_status' => Backup::OFFSITE_OFF,
                'created_by' => $user?->id,
            ]);
        } finally {
            if ($temp !== null && is_file($temp)) {
                @unlink($temp);
            }
        }

        /*
         * Everything from here on is housekeeping around a backup that has
         * already been recorded, so each step swallows its own errors: none of
         * them is a reason to turn a good backup into a failed one.
         */
        try {
            $this->uploadPending(exclude: $backup->id);
        } catch (Throwable $e) {
            Log::warning('Catching up on offsite backups failed: ' . $e->getMessage());
        }

        try {
            $this->prune();
        } catch (Throwable $e) {
            Log::warning('Backup retention pass failed: ' . $e->getMessage());
        }

        $backup = $backup->fresh() ?? $backup;

        try {
            $this->sendReport($backup, $artifact);
        } catch (Throwable $e) {
            Log::warning('Backup report email failed: ' . $e->getMessage());
        }

        // The packaged copy is a working file, not a kept one: the bucket has
        // the upload and the email has been sent by now.
        if ($artifact !== null && is_file($artifact['path'])) {
            @unlink($artifact['path']);
        }

        return $backup;
    }

    /**
     * Dump the live database to a temporary file and return its path.
     *
     * The caller owns the file and must delete it.
     *
     * @throws RuntimeException when the driver is unsupported or the dump fails
     */
    public function dump(?string $filename = null): string
    {
        $db = $this->connection();
        $driver = $db['driver'] ?? null;
        $filename ??= $this->filename();

        $temp = $this->tempDir() . DIRECTORY_SEPARATOR . $filename;

        if ($driver === 'sqlite') {
            $source = $db['database'] ?? '';

            // An in-memory database has no file to copy. Worth saying plainly:
            // the alternative is a copy() warning and an empty backup.
            if ($source === ':memory:' || !is_file($source)) {
                throw new RuntimeException('The SQLite database file could not be found, so there is nothing to copy.');
            }

            if (!@copy($source, $temp)) {
                throw new RuntimeException('Failed to copy the SQLite database. Check server permissions.');
            }

            return $temp;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $process = new Process(
                [
                    'mysqldump',
                    '--host=' . $db['host'],
                    '--port=' . $db['port'],
                    '--user=' . $db['username'],
                    '--result-file=' . $temp,
                    $db['database'],
                ],
                null,
                ['MYSQL_PWD' => (string) ($db['password'] ?? '')]
            );
            $process->setTimeout(600);
            $process->run();

            if (!$process->isSuccessful()) {
                @unlink($temp);

                throw new RuntimeException(
                    'mysqldump failed. Check that it is installed and on the PATH. ' . trim($process->getErrorOutput())
                );
            }

            return $temp;
        }

        throw new RuntimeException('Backups are not supported for the "' . ($driver ?? 'unknown') . '" database driver.');
    }

    /**
     * Delete backups the shop no longer wants to keep.
     *
     * Two passes, because the two copies have their own lifetimes: a shop keeps
     * a week of local copies and six months of offsite ones.
     *
     *  - the local pass applies `backup_retention_days` / `_count` to the file
     *    on this machine;
     *  - the offsite pass applies `backup_offsite_retention_days` to the object
     *    in the bucket.
     *
     * The row survives until *both* copies have gone, because the row is the
     * only record of where the remote object is. Delete it at local-retention
     * time and nothing would ever tidy the bucket again.
     *
     * The most recent successful backup is always kept, whatever the settings
     * say: a retention of "0 days" typed into the wrong box must not be able to
     * leave the shop with no backup at all. Same for the most recent offsite
     * copy.
     *
     * @return int how many rows were removed
     */
    public function prune(): int
    {
        return $this->pruneLocal() + $this->pruneOffsite();
    }

    /**
     * Hand a stored backup to the browser.
     *
     * Falls back to the offsite copy when the local file has gone - which is
     * the normal state of an older backup once local retention has been past
     * it. Returns null when there is nothing left anywhere, so the caller can
     * say so rather than 404.
     */
    public function download(Backup $backup)
    {
        if (!$backup->succeeded()) {
            return null;
        }

        if ($backup->disk === self::LOCAL) {
            if (is_file($backup->path)) {
                return response()->download($backup->path, $backup->filename);
            }
        } elseif ($response = $this->streamFromDisk($backup->disk, $backup->path, $backup->filename)) {
            return $response;
        }

        return $this->downloadOffsite($backup);
    }

    /** Where backups are being written, in words, for the settings screen. */
    public function destinationLabel(): string
    {
        $disk = $this->diskName();

        return $disk === self::LOCAL
            ? $this->localDirectory()
            : 'disk "' . $disk . '" (backups/)';
    }

    /** `backup_time` as [hour, minute], falling back to 02:00. */
    public function scheduleTime(): string
    {
        $time = (string) setting('backup_time', '02:00');

        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) ? $time : '02:00';
    }

    public function isScheduled(): bool
    {
        return setting('backup_enabled', '0') === '1';
    }

    /** daily|weekly */
    public function frequency(): string
    {
        return setting('backup_frequency', 'daily') === 'weekly' ? 'weekly' : 'daily';
    }

    /**
     * Should tonight's scheduled run happen? The scheduler fires the command
     * daily at the configured time; a weekly shop only wants Mondays.
     */
    public function dueNow(): bool
    {
        if (!$this->isScheduled()) {
            return false;
        }

        return $this->frequency() === 'daily' || now()->isMonday();
    }

    // ------------------------------------------------------------ offsite: settings

    public function offsiteEnabled(): bool
    {
        return setting('backup_offsite_enabled', '0') === '1';
    }

    /**
     * The disk the offsite copy goes to.
     *
     * Returned as the shop typed it, even when it is not a configured disk:
     * preflight() is what turns that into a sentence a shopkeeper can act on,
     * and silently falling back to somewhere else would be worse than saying
     * the destination is wrong.
     */
    public function offsiteDisk(): string
    {
        $disk = trim((string) setting('backup_offsite_disk', 's3'));

        return $disk !== '' ? $disk : 's3';
    }

    /** The folder inside the bucket. Relative, never absolute. */
    public function offsitePath(): string
    {
        $path = trim((string) setting('backup_offsite_path', ''), " \t\n\r/\\");

        return $path !== '' ? $path : 'salespoint-backups';
    }

    public function offsiteDestinationLabel(): string
    {
        return 'disk "' . $this->offsiteDisk() . '" (' . $this->offsitePath() . '/)';
    }

    public function offsiteRetentionDays(): int
    {
        return max(0, (int) setting('backup_offsite_retention_days', 90));
    }

    /** Encryption is on unless the shop has explicitly turned it off. */
    public function encryptionEnabled(): bool
    {
        return setting('backup_encrypt', '1') === '1';
    }

    /** Is a key stored? Said on the settings screen without showing the key. */
    public function hasEncryptionKey(): bool
    {
        return trim((string) setting('backup_encryption_key', '')) !== '';
    }

    /**
     * The encryption key, in the clear, for the encrypter.
     *
     * Stored through Laravel's Crypt, the same way User::two_factor_secret is,
     * so the key is not sitting in plaintext in the settings table - which
     * matters because the settings table is *inside the dump*. Encrypted at
     * rest, the copy of the key inside the backup is useless without APP_KEY
     * from .env, which is not in the database at all.
     *
     * Generated on first use when encryption is on and no key is set: a shop
     * that ticked "encrypt" and typed nothing must not end up with plaintext
     * backups because of it.
     *
     * @throws RuntimeException when a stored key cannot be read back
     */
    public function encryptionKey(): string
    {
        $stored = trim((string) setting('backup_encryption_key', ''));

        if ($stored === '') {
            $generated = BackupEncrypter::newKey();
            $this->storeEncryptionKey($generated);

            Log::warning(
                'No backup encryption key was set, so one was generated. Write it down: run '
                . '"php artisan salespoint:backup:key --show" on the server. Without it an offsite backup '
                . 'cannot be restored.'
            );

            return $generated;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException $e) {
            /*
             * Two different situations, and they must not be confused:
             *
             *  - somebody wrote a key straight into the settings table rather
             *    than through the screen. Take it at face value; it works.
             *  - it *is* our ciphertext but APP_KEY has changed since. Silently
             *    treating the ciphertext as the key would start encrypting
             *    tonight's backup with a different key and leave every older
             *    one unopenable, with nothing said. Refuse instead.
             */
            if ($this->looksLikeCiphertext($stored)) {
                throw new RuntimeException(
                    'The stored backup encryption key cannot be read. This normally means APP_KEY in .env has '
                    . 'changed since the key was saved. Restore the old APP_KEY, or set the key again in '
                    . 'Settings > Backup - but keep the old key safe, because the backups already taken need it.'
                );
            }

            return $stored;
        }
    }

    /** Save a key, encrypted at rest. */
    public function storeEncryptionKey(string $key): void
    {
        Setting::set('backup_encryption_key', Crypt::encryptString(trim($key)));
    }

    public function emailReportEnabled(): bool
    {
        return setting('backup_email_report', '0') === '1';
    }

    public function emailAttachEnabled(): bool
    {
        return setting('backup_email_attach', '0') === '1';
    }

    /** The attachment ceiling, in bytes. */
    public function emailMaxBytes(): int
    {
        $mb = (int) setting('backup_email_max_mb', 15);

        return max(1, $mb) * 1024 * 1024;
    }

    /**
     * Who the report goes to: the addresses in Settings, or the shop's own
     * email address when that box was left empty.
     *
     * @return array<int, string>
     */
    public function emailRecipients(): array
    {
        $raw = (string) setting('backup_email_to', '');

        if (trim($raw) === '') {
            $raw = (string) setting('store_email', '');
        }

        return collect(preg_split('/[,;\s]+/', $raw) ?: [])
            ->map(fn ($address) => trim($address))
            ->filter(fn ($address) => $address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Offsite backup is on but nothing runs it on its own.
     *
     * This is the "auto backup is set up" half of the feature: a shop that
     * types in bucket credentials and saves has said what it wants, but with
     * the schedule off the only backups that ever go offsite are the ones
     * somebody remembers to press the button for. The screen says so and
     * offers to turn the schedule on - it is not turned on behind their back,
     * because a nightly job appearing out of nowhere on a shop's server is not
     * ours to decide.
     */
    public function needsSchedule(): bool
    {
        return $this->offsiteEnabled() && !$this->isScheduled();
    }

    /** How many backups are still waiting to go offsite. */
    public function pendingOffsiteCount(): int
    {
        return Backup::awaitingOffsite()->count();
    }

    // ------------------------------------------------------------- offsite: sending

    /**
     * Compress, encrypt and upload one backup's second copy.
     *
     * Never throws: the outcome is recorded on the row, including which kind of
     * failure it was, and the local copy is left exactly as it was. A dump that
     * was written to disk and then could not be uploaded is a *successful*
     * backup with an offsite problem - saying the whole run failed would hide
     * the copy the shop actually has.
     *
     * @param  array{path: string, name: string, encrypted: bool}|null  $artifact
     *                                                                            an already packaged file, when the caller has one
     */
    public function sendOffsite(Backup $backup, ?array $artifact = null): Backup
    {
        if (!$backup->succeeded()) {
            return $backup;
        }

        // A file we packaged ourselves here, and must therefore clean up.
        $own = null;

        try {
            if ($problem = $this->preflight()) {
                return $this->recordOffsiteFailure($backup, $problem['message'], $problem['kind']);
            }

            if ($artifact === null) {
                $own = $artifact = $this->packageFor($backup);
            }

            $this->upload($backup, $artifact);
        } catch (Throwable $e) {
            Log::error('Offsite backup failed for ' . $backup->filename . ': ' . $e->getMessage());

            $this->recordOffsiteFailure($backup, $e->getMessage(), $this->failureKind($e));
        } finally {
            if ($own !== null && is_file($own['path'])) {
                @unlink($own['path']);
            }
        }

        return $backup;
    }

    /**
     * Try again for everything still owed.
     *
     * Backoff is per row, so one unreachable destination does not mean every
     * run spends its night retrying twenty uploads against a dead endpoint.
     *
     * @param  int|null  $exclude  a row the caller has just dealt with
     * @param  bool  $force  ignore the backoff - the "upload now" button
     * @return array{uploaded: int, failed: int}
     */
    public function uploadPending(?int $exclude = null, bool $force = false, int $limit = self::CATCHUP_BATCH): array
    {
        $result = ['uploaded' => 0, 'failed' => 0];

        if (!$this->offsiteEnabled()) {
            return $result;
        }

        $query = Backup::query()->awaitingOffsite();

        if ($exclude !== null) {
            $query->where('id', '!=', $exclude);
        }

        if (!$force) {
            $query->where(function ($q) {
                $q->whereNull('offsite_next_attempt_at')
                    ->orWhere('offsite_next_attempt_at', '<=', now());
            });
        }

        foreach ($query->limit($limit)->get() as $backup) {
            $this->sendOffsite($backup);

            ($backup->fresh()?->isOffsite() ?? false)
                ? $result['uploaded']++
                : $result['failed']++;
        }

        return $result;
    }

    /**
     * Write a small object to the destination and delete it again.
     *
     * The point is to find out *now*, while somebody is sitting in front of the
     * screen, rather than at 2am in three weeks' time. Write-then-delete rather
     * than a bare listing because write and delete are the two permissions the
     * feature actually needs: a key that can list a bucket but not write to it
     * passes a read-only check and fails every backup.
     *
     * @return array{ok: bool, kind: string|null, message: string, hint: string|null}
     */
    public function testConnection(?string $disk = null, ?string $path = null): array
    {
        $diskName = trim((string) ($disk ?? $this->offsiteDisk()));
        $folder = trim((string) ($path ?? $this->offsitePath()), " \t\n\r/\\") ?: 'salespoint-backups';

        if ($problem = $this->preflight($diskName)) {
            return [
                'ok' => false,
                'kind' => $problem['kind'],
                'message' => $problem['message'],
                'hint' => $this->hintFor($problem['kind']),
            ];
        }

        $probe = $folder . '/.salespoint-connection-test-' . bin2hex(random_bytes(4));

        try {
            $target = Storage::disk($diskName);

            if (!$target->put($probe, 'SalesPoint connection test, ' . now()->toIso8601String() . "\n")) {
                throw new RuntimeException('The destination refused the test file without saying why.');
            }

            if (!$target->exists($probe)) {
                throw new RuntimeException(
                    'The test file was accepted but is not there afterwards, so uploads would appear to '
                    . 'work and keep nothing. Check the bucket name and the path.'
                );
            }

            $target->delete($probe);

            if ($target->exists($probe)) {
                // Not fatal - backups would still upload - but retention would
                // never be able to tidy up, so say it now.
                return [
                    'ok' => true,
                    'kind' => null,
                    'message' => 'Backups can be uploaded to ' . $this->labelFor($diskName, $folder)
                        . ', but the test file could not be deleted again, so old backups will pile up there. '
                        . 'The access key needs delete permission as well as write.',
                    'hint' => null,
                ];
            }

            return [
                'ok' => true,
                'kind' => null,
                'message' => 'Connected. A test file was written to ' . $this->labelFor($diskName, $folder)
                    . ' and deleted again, so backups can be uploaded and old ones removed.',
                'hint' => null,
            ];
        } catch (Throwable $e) {
            // Best effort: if the write got through and the failure was later,
            // the probe should not be left behind.
            try {
                Storage::disk($diskName)->delete($probe);
            } catch (Throwable) {
                // Nothing to do; it is a 40-byte file and the real error follows.
            }

            $kind = $this->failureKind($e);

            Log::warning('Offsite backup connection test failed: ' . $e->getMessage());

            return [
                'ok' => false,
                'kind' => $kind,
                'message' => $this->plainly($e),
                'hint' => $this->hintFor($kind),
            ];
        }
    }

    /**
     * Is this failure worth retrying, or does it need a person?
     *
     * The distinction matters more than it looks. A shop's internet drops for
     * an hour most weeks, and that must heal by itself. A mistyped secret key
     * never heals, and quietly retrying it for ever is how a shop ends up with
     * six months of backups that never left the building.
     *
     * Anything unrecognised counts as transient, because retrying is the safe
     * guess - but only MAX_TRANSIENT_ATTEMPTS times, after which it is reported
     * as needing attention anyway. See recordOffsiteFailure().
     *
     * @param  Throwable|string  $error
     */
    public function failureKind($error): string
    {
        $text = is_string($error) ? $error : $this->wholeChain($error);

        // Refused, rather than unreachable: credentials, permissions, or a
        // bucket that is not there. None of these fix themselves. Nor does a
        // key we cannot read, or a dump we cannot compress.
        $permanent = '/invalid ?access ?key|signature ?does ?not ?match|access ?denied|accessdenied|forbidden'
            . '|\b40[13]\b|no ?such ?bucket|nosuchbucket|invalid ?bucket|all ?access ?disabled|expired ?token'
            . '|invalid ?token|unauthori[sz]ed|not authori[sz]ed|permission denied|credential|unable to locate'
            . '|entity ?too ?large|quota ?exceeded|is not configured|are not filled in|no offsite destination'
            . '|app_key|cannot be read|no backup encryption key/i';

        if (preg_match($permanent, $text)) {
            return self::FAILURE_PERMANENT;
        }

        return self::FAILURE_TRANSIENT;
    }

    // ---------------------------------------------------------------- internals

    private function connection(): array
    {
        $connection = config('database.default');

        return config("database.connections.{$connection}", []);
    }

    private function driver(): string
    {
        return (string) ($this->connection()['driver'] ?? 'unknown');
    }

    private function filename(): string
    {
        $extension = $this->driver() === 'sqlite' ? 'sqlite' : 'sql';

        return 'backup-' . now()->format('Y-m-d_H-i-s') . '-' . bin2hex(random_bytes(3)) . '.' . $extension;
    }

    /**
     * The configured destination: a Laravel disk name, or self::LOCAL for a
     * plain directory. An unknown disk name falls back to the local directory
     * rather than throwing - a typo in Settings should not stop backups.
     */
    private function diskName(): string
    {
        $disk = trim((string) setting('backup_disk', ''));

        if ($disk === '' || $disk === self::LOCAL) {
            return self::LOCAL;
        }

        return array_key_exists($disk, config('filesystems.disks', [])) ? $disk : self::LOCAL;
    }

    /**
     * Where plain-directory backups go. `backup_path` is how a shop points
     * this at a second location: another drive, or a folder their cloud client
     * syncs.
     */
    private function localDirectory(): string
    {
        $path = trim((string) setting('backup_path', ''));

        return $path !== '' ? rtrim($path, '\\/') : storage_path('app/backups');
    }

    private function tempDir(): string
    {
        $dir = storage_path('app/backup-temp');
        $this->ensureDirectory($dir);

        return $dir;
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the backup folder "' . $dir . '". Check the path and permissions.');
        }
    }

    /**
     * Move the finished dump to its destination.
     *
     * @return array{0: string, 1: string, 2: int} [disk, path, size in bytes]
     */
    private function store(string $temp, string $filename): array
    {
        $size = (int) (@filesize($temp) ?: 0);
        $disk = $this->diskName();

        if ($disk === self::LOCAL) {
            $dir = $this->localDirectory();
            $this->ensureDirectory($dir);

            $target = $dir . DIRECTORY_SEPARATOR . $filename;

            // copy + unlink rather than rename: the destination is often on a
            // different volume (that being the point of a second location), and
            // rename() cannot cross one.
            if (!@copy($temp, $target)) {
                throw new RuntimeException('Could not write the backup to "' . $dir . '". Check the path and permissions.');
            }

            return [self::LOCAL, $target, $size];
        }

        $key = 'backups/' . $filename;
        $stream = fopen($temp, 'rb');

        try {
            // writeStream, not put: a dump is as big as the shop's whole history
            // and does not belong in memory.
            if (!Storage::disk($disk)->writeStream($key, $stream)) {
                throw new RuntimeException('The disk "' . $disk . '" rejected the backup file.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [$disk, $key, $size];
    }

    private function deleteFile(Backup $backup): void
    {
        try {
            if ($backup->disk === self::LOCAL) {
                if ($backup->path !== '' && is_file($backup->path)) {
                    @unlink($backup->path);
                }

                return;
            }

            if ($backup->path !== '') {
                Storage::disk($backup->disk)->delete($backup->path);
            }
        } catch (Throwable $e) {
            // A file we cannot delete is not a reason to keep the row forever.
            Log::warning('Could not delete backup file: ' . $e->getMessage());
        }
    }

    /** Shared by the controller and the command so both log the same thing. */
    public function recordAudit(Backup $backup): void
    {
        AuditLog::record(
            $backup->succeeded() ? 'Ran Database Backup' : 'Database Backup Failed',
            'backups',
            $backup->id
        );
    }

    // ------------------------------------------------------- internals: packaging

    /**
     * gzip and (unless the shop turned it off) encrypt a dump, ready to upload.
     *
     * Compression first, then encryption, which is the only order that works:
     * ciphertext does not compress. A SQL dump shrinks by roughly 80%, which on
     * a shop's upstream connection is the difference between a nightly backup
     * that finishes and one that is still going when the shop opens.
     *
     * @return array{path: string, name: string, encrypted: bool}
     */
    private function package(string $source, string $filename): array
    {
        $gz = $this->tempDir() . DIRECTORY_SEPARATOR . $filename . '.gz';
        $this->encrypter->gzip($source, $gz);

        if (!$this->encryptionEnabled()) {
            return ['path' => $gz, 'name' => $filename . '.gz', 'encrypted' => false];
        }

        $enc = $gz . '.enc';

        try {
            $this->encrypter->encryptFile($gz, $enc, $this->encryptionKey());
        } finally {
            // The compressed plaintext is the one copy we must not leave lying
            // around in a temp folder.
            @unlink($gz);
        }

        return ['path' => $enc, 'name' => $filename . '.gz.enc', 'encrypted' => true];
    }

    /**
     * package(), but recording a failure on the row instead of throwing.
     *
     * A packaging failure is local - no disk space, no openssl, an unreadable
     * key - so it is permanent by nature: retrying tonight will do the same
     * thing. It is reported as needing attention.
     *
     * @return array{path: string, name: string, encrypted: bool}|null
     */
    private function packageSafely(Backup $backup, string $source): ?array
    {
        try {
            return $this->package($source, $backup->filename);
        } catch (Throwable $e) {
            Log::error('Could not prepare the offsite copy of ' . $backup->filename . ': ' . $e->getMessage());

            $this->recordOffsiteFailure($backup, $e->getMessage(), self::FAILURE_PERMANENT);

            return null;
        }
    }

    /**
     * Package a backup from whatever copy of it still exists.
     *
     * Used by the retry pass, where the temporary dump is long gone and the
     * kept local copy is the source.
     *
     * @return array{path: string, name: string, encrypted: bool}
     */
    private function packageFor(Backup $backup): array
    {
        [$source, $isTemp] = $this->materialise($backup);

        try {
            return $this->package($source, $backup->filename);
        } finally {
            if ($isTemp && is_file($source)) {
                @unlink($source);
            }
        }
    }

    /**
     * A real path to a backup's bytes, fetching it off a disk if that is where
     * the kept copy lives.
     *
     * @return array{0: string, 1: bool} [path, is a temporary copy to delete]
     */
    private function materialise(Backup $backup): array
    {
        if ($backup->disk === self::LOCAL) {
            if ($backup->path === '' || !is_file($backup->path)) {
                throw new RuntimeException(
                    'The local copy of ' . $backup->filename . ' is no longer at "' . $backup->path
                    . '", so there is nothing left to upload.'
                );
            }

            return [$backup->path, false];
        }

        $temp = $this->tempDir() . DIRECTORY_SEPARATOR . 'offsite-' . $backup->id . '-' . $backup->filename;
        $in = Storage::disk($backup->disk)->readStream($backup->path);

        if ($in === null || $in === false) {
            throw new RuntimeException(
                'The kept copy of ' . $backup->filename . ' could not be read from disk "' . $backup->disk . '".'
            );
        }

        $out = @fopen($temp, 'wb');

        if ($out === false) {
            fclose($in);

            throw new RuntimeException('Could not make a working copy of ' . $backup->filename . '.');
        }

        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        return [$temp, true];
    }

    // -------------------------------------------------------- internals: uploading

    /**
     * Check what can be checked without touching the network.
     *
     * A missing bucket name or an empty access key is not a connection problem
     * and should not be reported as one, retried for a week, or left to time
     * out: it is a sentence telling somebody which box to fill in.
     *
     * @return array{kind: string, message: string}|null  null when all is well
     */
    private function preflight(?string $diskName = null): ?array
    {
        $diskName = trim((string) ($diskName ?? $this->offsiteDisk()));

        if ($diskName === '') {
            return [
                'kind' => self::FAILURE_PERMANENT,
                'message' => 'No offsite destination is chosen, so there is nowhere to send the backup.',
            ];
        }

        $config = config('filesystems.disks.' . $diskName);

        if (!is_array($config)) {
            return [
                'kind' => self::FAILURE_PERMANENT,
                'message' => 'The disk "' . $diskName . '" is not configured in config/filesystems.php, so backups '
                    . 'cannot be sent to it.',
            ];
        }

        if (($config['driver'] ?? null) === 's3') {
            $missing = collect([
                'AWS_ACCESS_KEY_ID' => $config['key'] ?? null,
                'AWS_SECRET_ACCESS_KEY' => $config['secret'] ?? null,
                'AWS_BUCKET' => $config['bucket'] ?? null,
            ])->filter(fn ($value) => trim((string) $value) === '')->keys();

            if ($missing->isNotEmpty()) {
                return [
                    'kind' => self::FAILURE_PERMANENT,
                    'message' => 'The storage credentials are not filled in: ' . $missing->implode(', ')
                        . ' ' . ($missing->count() === 1 ? 'is' : 'are') . ' empty in the .env file on the server. '
                        . 'Offsite backup cannot work until that is done.',
                ];
            }
        }

        return null;
    }

    /**
     * Upload a packaged backup and prove it arrived.
     *
     * "The call returned without an error" is not proof. A half-finished
     * multipart upload, a proxy that ate the body, a bucket policy that accepts
     * and discards - all of those look like success from here. So the object is
     * asked for again: it must exist, and it must be exactly as long as what we
     * sent. A short object is deleted rather than left to look like a backup.
     *
     * @param  array{path: string, name: string, encrypted: bool}  $artifact
     */
    private function upload(Backup $backup, array $artifact): void
    {
        $diskName = $this->offsiteDisk();
        $disk = Storage::disk($diskName);
        $key = $this->offsitePath() . '/' . $artifact['name'];

        $size = (int) (@filesize($artifact['path']) ?: 0);
        $checksum = (string) hash_file('sha256', $artifact['path']);

        $stream = fopen($artifact['path'], 'rb');

        if ($stream === false) {
            throw new RuntimeException('Could not read the prepared backup to upload it.');
        }

        try {
            // writeStream, not put: the whole point is never to hold the shop's
            // history in memory.
            if (!$disk->writeStream($key, $stream)) {
                throw new RuntimeException(
                    'The destination "' . $diskName . '" refused the backup without saying why. Check the bucket '
                    . 'name, the region and the endpoint.'
                );
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->verifyUpload($disk, $diskName, $key, $size);

        $backup->forceFill([
            'offsite_status' => Backup::OFFSITE_UPLOADED,
            'offsite_disk' => $diskName,
            'offsite_path' => $key,
            'offsite_size_bytes' => $size,
            'offsite_checksum' => $checksum,
            'offsite_encrypted' => $artifact['encrypted'],
            'offsite_error' => null,
            'offsite_attempts' => $backup->offsite_attempts + 1,
            'offsite_next_attempt_at' => null,
            'offsite_uploaded_at' => now(),
        ])->save();
    }

    private function verifyUpload($disk, string $diskName, string $key, int $size): void
    {
        try {
            $there = $disk->exists($key);
            $remoteSize = $there ? (int) $disk->size($key) : 0;
        } catch (Throwable $e) {
            throw new RuntimeException(
                'The backup was uploaded to "' . $diskName . '" but could not be read back to check it: '
                . $e->getMessage()
            );
        }

        if (!$there) {
            throw new RuntimeException(
                'The upload to "' . $diskName . '" reported success but the file is not in the bucket afterwards. '
                . 'Check the bucket name and that the access key may write to it.'
            );
        }

        if ($remoteSize !== $size) {
            // A short object is worse than no object: it looks like a backup.
            try {
                $disk->delete($key);
            } catch (Throwable) {
                // Reported below either way.
            }

            throw new RuntimeException(
                'Only ' . Backup::bytesForHumans($remoteSize) . ' of ' . Backup::bytesForHumans($size)
                . ' arrived at "' . $diskName . '", so the upload was incomplete and has been removed. '
                . 'It will be tried again.'
            );
        }
    }

    /**
     * Write down that the second copy did not happen, and when to try again.
     *
     * Backoff doubles from five minutes up to six hours for transient trouble,
     * and sits at a day for something that needs a person: long enough not to
     * hammer a 403 all night, short enough that fixing the key means the next
     * night's run catches up on its own.
     */
    private function recordOffsiteFailure(Backup $backup, string $message, string $kind): Backup
    {
        $attempts = (int) $backup->offsite_attempts + 1;
        $permanent = $kind === self::FAILURE_PERMANENT;

        // Trouble we did not recognise, over and over, stops being transient.
        if (!$permanent && $attempts >= self::MAX_TRANSIENT_ATTEMPTS) {
            $permanent = true;
            $message = 'After ' . $attempts . ' attempts this backup still has not gone offsite. ' . $message;
        }

        $backup->forceFill([
            'offsite_status' => $permanent ? Backup::OFFSITE_FAILED : Backup::OFFSITE_PENDING,
            'offsite_disk' => $backup->offsite_disk ?: $this->offsiteDisk(),
            'offsite_error' => mb_substr($message, 0, 2000),
            'offsite_attempts' => $attempts,
            'offsite_next_attempt_at' => $permanent
                ? now()->addDay()
                : now()->addMinutes(min(360, 5 * (2 ** ($attempts - 1)))),
        ])->save();

        return $backup;
    }

    private function downloadOffsite(Backup $backup)
    {
        if (!$backup->isOffsite() || !$backup->offsite_disk || !$backup->offsite_path) {
            return null;
        }

        return $this->streamFromDisk(
            $backup->offsite_disk,
            $backup->offsite_path,
            basename($backup->offsite_path)
        );
    }

    /**
     * Stream a file off a disk to the browser.
     *
     * Written out by hand rather than using the disk's own download(), for two
     * reasons. The small one is that a backup is as big as the shop's history
     * and should go out in chunks. The real one is that download() asks the
     * adapter for the file's mime type, and a `.gz.enc` is a type no detector
     * knows - on a disk configured to throw (which the offsite disk is, because
     * the upload needs the reason a write failed) that turns "here is your
     * backup" into a silent failure.
     *
     * Returns null when the file is not there, so the caller can say so.
     */
    private function streamFromDisk(string $diskName, string $path, string $filename)
    {
        if ($path === '') {
            return null;
        }

        try {
            $disk = Storage::disk($diskName);

            if (!$disk->exists($path)) {
                return null;
            }

            $stream = $disk->readStream($path);

            if ($stream === null || $stream === false) {
                return null;
            }
        } catch (Throwable $e) {
            Log::warning('Could not read a backup from disk "' . $diskName . '": ' . $e->getMessage());

            return null;
        }

        return response()->streamDownload(function () use ($stream) {
            while (!feof($stream)) {
                echo fread($stream, 1048576);
            }

            fclose($stream);
        }, $filename, [
            // Deliberately vague: it is compressed, probably encrypted, and
            // nothing good comes of a browser trying to open it.
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    // -------------------------------------------------------- internals: retention

    private function pruneLocal(): int
    {
        $days = (int) setting('backup_retention_days', 30);
        $keep = (int) setting('backup_retention_count', 0);

        $newest = Backup::where('status', Backup::STATUS_SUCCESS)->latest('id')->value('id');

        $doomed = collect();

        if ($days > 0) {
            $doomed = $doomed->merge(
                Backup::where('created_at', '<', now()->subDays($days))->get()
            );
        }

        if ($keep > 0) {
            // Everything past the newest $keep rows. Sliced in PHP rather than
            // with OFFSET so there is no "limit" to invent for an open-ended tail.
            $extra = Backup::latest('id')->get()->slice($keep);
            $doomed = $doomed->merge($extra);
        }

        $doomed = $doomed->unique('id')
            ->reject(fn (Backup $b) => $b->id === $newest)
            /*
             * The one rule that outranks every retention setting: while an
             * offsite copy is still owed, the local file is the only copy of
             * the shop's history that exists. Deleting it here - because it is
             * eight days old, or because it is the fourth of three - would be
             * this feature deleting the very thing it was added to protect.
             */
            ->reject(fn (Backup $b) => $b->offsiteOwed());

        if ($doomed->isEmpty()) {
            return 0;
        }

        $removeRows = [];

        // The files go first: a row deleted before its file is a file nothing
        // knows about any more.
        foreach ($doomed as $backup) {
            $this->deleteFile($backup);

            if ($backup->isOffsite()) {
                // Keep the row: it is the only record of the remote object, and
                // offsite retention still has to come back for it.
                if ($backup->local_pruned_at === null) {
                    $backup->forceFill(['local_pruned_at' => now()])->save();
                }

                continue;
            }

            $removeRows[] = $backup->id;
        }

        if ($removeRows === []) {
            return 0;
        }

        return (int) DB::transaction(fn () => Backup::whereIn('id', $removeRows)->delete());
    }

    private function pruneOffsite(): int
    {
        $days = $this->offsiteRetentionDays();

        if ($days <= 0) {
            return 0;   // keep offsite copies for ever
        }

        $newest = Backup::where('offsite_status', Backup::OFFSITE_UPLOADED)->latest('id')->value('id');

        $expired = Backup::where('offsite_status', Backup::OFFSITE_UPLOADED)
            ->where('offsite_uploaded_at', '<', now()->subDays($days))
            ->when($newest !== null, fn ($q) => $q->where('id', '!=', $newest))
            ->get();

        if ($expired->isEmpty()) {
            return 0;
        }

        $removeRows = [];

        foreach ($expired as $backup) {
            try {
                if ($backup->offsite_disk && $backup->offsite_path) {
                    Storage::disk($backup->offsite_disk)->delete($backup->offsite_path);
                }
            } catch (Throwable $e) {
                // Leave the row alone so the next pass tries again rather than
                // forgetting about an object we are still paying to store.
                Log::warning('Could not delete an expired offsite backup: ' . $e->getMessage());

                continue;
            }

            if ($backup->hasLocalCopy()) {
                $backup->forceFill([
                    'offsite_status' => Backup::OFFSITE_EXPIRED,
                    'offsite_path' => null,
                ])->save();

                continue;
            }

            // Neither copy left: now the row can go.
            $removeRows[] = $backup->id;
        }

        if ($removeRows === []) {
            return 0;
        }

        return (int) DB::transaction(fn () => Backup::whereIn('id', $removeRows)->delete());
    }

    // ------------------------------------------------------------ internals: email

    /**
     * Email a short report of the run.
     *
     * Off by default. The attachment rules are strict on purpose - see
     * decideAttachment().
     *
     * @param  array{path: string, name: string, encrypted: bool}|null  $artifact
     */
    private function sendReport(Backup $backup, ?array $artifact): void
    {
        if (!$this->emailReportEnabled()) {
            return;
        }

        $to = $this->emailRecipients();

        if ($to === []) {
            Log::warning('Backup reports are switched on but no email address is set, so none was sent.');

            return;
        }

        [$attachment, $attachNote] = $this->decideAttachment($artifact);

        $mailable = new BackupReportMail(
            $backup,
            $this->reportFacts($backup),
            $attachment,
            $attachNote
        );

        /*
         * An attachment is sent inline, never queued: the packaged file is a
         * temporary one that this run deletes on its way out, and a worker
         * picking the job up a minute later would find nothing there. Without
         * an attachment the mail is queued where a worker exists, like receipts.
         */
        if ($attachment !== null || config('queue.default') === 'sync') {
            Mail::to($to)->send($mailable);
        } else {
            Mail::to($to)->queue($mailable);
        }
    }

    /**
     * Whether the backup itself goes with the report, and what to say if not.
     *
     * Two hard rules:
     *
     *  - **never attach an unencrypted dump.** The whole customer list, every
     *     sale, the password hashes and the two-factor secrets, sitting in an
     *     inbox and in whatever mail server it passed through on the way. If
     *     encryption is off, the attachment is refused and the report says so.
     *  - **never attach above the configured size.** Providers bounce or
     *     silently truncate somewhere between 10 and 25 MB, and a backup that
     *     stops arriving without saying so is the worst failure a backup
     *     system can have.
     *
     * @param  array{path: string, name: string, encrypted: bool}|null  $artifact
     * @return array{0: string|null, 1: string|null} [path to attach, why not]
     */
    private function decideAttachment(?array $artifact): array
    {
        if (!$this->emailAttachEnabled()) {
            return [null, null];
        }

        if ($artifact === null || !is_file($artifact['path'])) {
            return [null, 'The backup could not be attached because the prepared copy was not available.'];
        }

        if (!$artifact['encrypted']) {
            return [
                null,
                'The backup was not attached because encryption is switched off. An unencrypted dump contains '
                . 'every customer, every sale, the password hashes and the two-factor secrets, so it is not sent '
                . 'by email. Switch on encryption in Settings > Backup to allow the attachment.',
            ];
        }

        $size = (int) (@filesize($artifact['path']) ?: 0);
        $limit = $this->emailMaxBytes();

        if ($size > $limit) {
            return [
                null,
                'The backup was not attached because it is ' . Backup::bytesForHumans($size) . ', over the '
                . Backup::bytesForHumans($limit) . ' limit for attachments. Email is not a safe way to move a file '
                . 'this size - most providers reject or truncate it. The offsite copy is the one to rely on.',
            ];
        }

        return [$artifact['path'], null];
    }

    /**
     * The plain facts the report email prints.
     *
     * @return array<string, mixed>
     */
    private function reportFacts(Backup $backup): array
    {
        $localKept = Backup::whereNull('local_pruned_at')->where('status', Backup::STATUS_SUCCESS)->count();
        $offsiteKept = Backup::where('offsite_status', Backup::OFFSITE_UPLOADED)->count();

        return [
            'shop' => setting('store_name') ?: config('app.name'),
            'when' => ($backup->created_at ?? now())->format('d M Y, H:i'),
            'localDestination' => $this->destinationLabel(),
            'offsiteEnabled' => $this->offsiteEnabled(),
            'offsiteDestination' => $this->offsiteDestinationLabel(),
            'encrypted' => $this->encryptionEnabled(),
            'localKept' => $localKept,
            'offsiteKept' => $offsiteKept,
            'localRetention' => (int) setting('backup_retention_days', 30),
            'localRetentionCount' => (int) setting('backup_retention_count', 0),
            'offsiteRetention' => $this->offsiteRetentionDays(),
            'pending' => $this->pendingOffsiteCount(),
            'needsSchedule' => $this->needsSchedule(),
        ];
    }

    // ----------------------------------------------------- internals: small helpers

    private function labelFor(string $disk, string $folder): string
    {
        return 'disk "' . $disk . '" (' . $folder . '/)';
    }

    /** Advice a shopkeeper can act on, rather than a stack trace. */
    private function hintFor(?string $kind): ?string
    {
        return match ($kind) {
            self::FAILURE_PERMANENT => 'The destination answered but would not accept the file, so this needs '
                . 'somebody to look at it: check the access key, the secret, the bucket name and the region in the '
                . '.env file on the server, and that the key is allowed to write to and delete from that bucket. '
                . 'Backups will keep being taken locally in the meantime.',
            self::FAILURE_TRANSIENT => 'The destination could not be reached. Check the shop\'s internet '
                . 'connection and the endpoint address. Nothing is lost - the backup is kept here and will be '
                . 'uploaded automatically when the connection is back.',
            default => null,
        };
    }

    /** The first line of an exception, which is the part that says what to fix. */
    private function plainly(Throwable $e): string
    {
        $message = trim(strtok($e->getMessage(), "\n") ?: $e->getMessage());

        return mb_substr($message, 0, 500);
    }

    /** Every message in the chain, for pattern matching. */
    private function wholeChain(Throwable $e): string
    {
        $messages = [];
        $current = $e;

        // Flysystem wraps the adapter's exception, which wraps the SDK's, and
        // it is the innermost one that says "AccessDenied".
        while ($current !== null && count($messages) < 6) {
            $messages[] = $current->getMessage();
            $current = $current->getPrevious();
        }

        return implode(' | ', $messages);
    }

    /** Laravel's Crypt payloads are base64 JSON with iv/value/mac keys. */
    private function looksLikeCiphertext(string $value): bool
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $json = json_decode($decoded, true);

        return is_array($json) && isset($json['iv'], $json['value'], $json['mac']);
    }
}
