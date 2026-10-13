<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Taking a copy of the database, by hand or on a schedule.
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
 * screen the shop already looks at.
 */
class BackupService
{
    /**
     * The disk name recorded for "a plain directory on this machine" - the
     * default, and what an absolute `backup_path` means. Anything else in the
     * `disk` column is a configured Laravel filesystem disk.
     */
    public const LOCAL = 'filesystem';

    /**
     * Run a backup end to end: dump, copy to the destination, record it, prune.
     *
     * Always returns a Backup row - check `status`. Never throws.
     *
     * @param  User|null  $user  null when the scheduler ran it
     */
    public function run(?User $user = null): Backup
    {
        $driver = $this->driver();
        $filename = $this->filename();
        $temp = null;

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
                'created_by' => $user?->id,
            ]);
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
                'created_by' => $user?->id,
            ]);
        } finally {
            if ($temp !== null && is_file($temp)) {
                @unlink($temp);
            }
        }

        // Pruning must not turn a good backup into a failed one, so it is
        // outside the try above and swallows its own errors.
        try {
            $this->prune();
        } catch (Throwable $e) {
            Log::warning('Backup retention pass failed: ' . $e->getMessage());
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
     * Delete backups the shop no longer wants to keep, by age and by count.
     *
     * The most recent successful backup is always kept, whatever the settings
     * say: a retention of "0 days" typed into the wrong box must not be able to
     * leave the shop with no backup at all.
     *
     * @return int how many rows were removed
     */
    public function prune(): int
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

        $doomed = $doomed->unique('id')->reject(fn (Backup $b) => $b->id === $newest);

        if ($doomed->isEmpty()) {
            return 0;
        }

        // The files go first: a row deleted before its file is a file nothing
        // knows about any more.
        foreach ($doomed as $backup) {
            $this->deleteFile($backup);
        }

        return (int) DB::transaction(fn () => Backup::whereIn('id', $doomed->pluck('id'))->delete());
    }

    /**
     * Hand a stored backup to the browser.
     *
     * Returns null when the file has gone (moved, pruned by hand, a synced
     * folder that was unsynced), so the caller can say so rather than 404.
     */
    public function download(Backup $backup)
    {
        if (!$backup->succeeded()) {
            return null;
        }

        if ($backup->disk === self::LOCAL) {
            return is_file($backup->path)
                ? response()->download($backup->path, $backup->filename)
                : null;
        }

        $disk = Storage::disk($backup->disk);

        return $disk->exists($backup->path)
            ? $disk->download($backup->path, $backup->filename)
            : null;
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
}
