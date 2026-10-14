<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\BackupEncrypter;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fetch a backup, prove it is intact, and turn it back into a dump.
 *
 * The easy path for the day something has gone wrong: pick a backup by id (or
 * take the latest), and this works out where its bytes are - the local folder,
 * a configured disk, or the offsite bucket - downloads them, checks the
 * checksum recorded when it was uploaded, decrypts, decompresses, and leaves a
 * plain dump on disk.
 *
 * What it deliberately does *not* do is write over the live database. Restoring
 * is a decision with consequences - it discards every sale since the backup was
 * taken - and it is not one a command should make on somebody's behalf while
 * the shop might be mid-sale. It prints the exact command to finish the job.
 */
class RestoreBackup extends Command
{
    protected $signature = 'salespoint:backup:restore
                            {backup? : the id from the backup history (default: the most recent successful one)}
                            {--out= : the folder to restore into (default: storage/app/backup-restore)}
                            {--key= : the encryption key (default: the one in Settings)}';

    protected $description = 'Fetch a backup (offsite if need be), verify it, and decrypt it to a plain dump';

    public function handle(BackupService $backups, BackupEncrypter $encrypter): int
    {
        $backup = $this->findBackup();

        if ($backup === null) {
            return self::FAILURE;
        }

        $folder = (string) ($this->option('out') ?: storage_path('app/backup-restore'));

        if (!is_dir($folder) && !@mkdir($folder, 0755, true) && !is_dir($folder)) {
            $this->components->error('Could not create "' . $folder . '".');

            return self::FAILURE;
        }

        try {
            $file = $this->fetch($backup, $folder);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Fetched ' . $file);

        if (!$encrypter->isEncrypted($file)) {
            $this->components->info('This backup is not encrypted.');

            return $this->finish($encrypter, $file);
        }

        try {
            $key = (string) ($this->option('key') ?: $backups->encryptionKey());
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $plain = preg_replace('/\.enc$/', '', $file) ?: $file . '.decrypted';

        try {
            $encrypter->decryptFile($file, $plain, $key);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        @unlink($file);
        $this->components->info('Decrypted.');

        return $this->finish($encrypter, $plain);
    }

    private function findBackup(): ?Backup
    {
        $id = $this->argument('backup');

        if ($id !== null) {
            $backup = Backup::find($id);

            if ($backup === null) {
                $this->components->error('There is no backup with id ' . $id . ' in the history.');

                return null;
            }
        } else {
            $backup = Backup::where('status', Backup::STATUS_SUCCESS)->latest('id')->first();

            if ($backup === null) {
                $this->components->error('There are no successful backups in the history to restore from.');

                return null;
            }

            $this->components->info('Restoring the most recent backup: #' . $backup->id . ' ' . $backup->filename);
        }

        return $backup;
    }

    /**
     * Get the bytes, from wherever they still are.
     *
     * Offsite first when the local copy has been pruned, which is the normal
     * state of anything older than local retention.
     */
    private function fetch(Backup $backup, string $folder): string
    {
        if ($backup->disk === BackupService::LOCAL && $backup->hasLocalCopy() && is_file($backup->path)) {
            $target = $folder . DIRECTORY_SEPARATOR . $backup->filename;

            if (!@copy($backup->path, $target)) {
                throw new \RuntimeException('Could not copy the local backup into "' . $folder . '".');
            }

            return $target;
        }

        if ($backup->disk !== BackupService::LOCAL && $backup->hasLocalCopy()) {
            return $this->pull($backup->disk, $backup->path, $folder . DIRECTORY_SEPARATOR . $backup->filename, null);
        }

        if (!$backup->isOffsite() || !$backup->offsite_path) {
            throw new \RuntimeException(
                'Backup #' . $backup->id . ' has no copy left: the local file has gone and there is no offsite copy. '
                . 'Nothing can be restored from this row.'
            );
        }

        $this->components->info('The local copy has gone; fetching the offsite copy.');

        return $this->pull(
            (string) $backup->offsite_disk,
            (string) $backup->offsite_path,
            $folder . DIRECTORY_SEPARATOR . basename((string) $backup->offsite_path),
            $backup->offsite_checksum
        );
    }

    /** Stream an object down and check it against the checksum we recorded. */
    private function pull(string $disk, string $path, string $target, ?string $checksum): string
    {
        $in = Storage::disk($disk)->readStream($path);

        if ($in === null || $in === false) {
            throw new \RuntimeException('"' . $path . '" could not be read from disk "' . $disk . '".');
        }

        $out = @fopen($target, 'wb');

        if ($out === false) {
            fclose($in);

            throw new \RuntimeException('Could not write to "' . $target . '".');
        }

        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        if ($checksum !== null && $checksum !== '') {
            $actual = hash_file('sha256', $target);

            if (!hash_equals($checksum, (string) $actual)) {
                @unlink($target);

                throw new \RuntimeException(
                    'The downloaded file does not match the checksum recorded when it was uploaded, so it was '
                    . 'damaged in storage or in transit. Nothing was written. Try an older backup.'
                );
            }

            $this->components->info('Checksum matches the one recorded at upload.');
        }

        return $target;
    }

    /** gunzip if need be, then say what to do with the result. */
    private function finish(BackupEncrypter $encrypter, string $file): int
    {
        if (str_ends_with($file, '.gz')) {
            $dump = substr($file, 0, -3);

            try {
                $encrypter->gunzip($file, $dump);
            } catch (Throwable $e) {
                $this->components->error('Could not decompress: ' . $e->getMessage());

                return self::FAILURE;
            }

            @unlink($file);
            $file = $dump;
        }

        $this->components->info('Restored to ' . $file);
        $this->newLine();

        /*
         * Said rather than done, on purpose. Overwriting a live database from a
         * command is a one-keystroke way to lose a day's trading, and whoever
         * is restoring needs to stop the app first anyway.
         */
        if (str_ends_with($file, '.sqlite')) {
            $this->components->twoColumnDetail('To finish', 'stop the app, then replace the database file:');
            $this->line('    cp "' . $file . '" "' . config('database.connections.sqlite.database') . '"');
        } else {
            $this->components->twoColumnDetail('To finish', 'stop the app, then load the dump:');
            $this->line('    mysql -u <user> -p <database> < "' . $file . '"');
        }

        $this->newLine();
        $this->components->warn(
            'This replaces everything recorded since the backup was taken. Take a backup of the current '
            . 'database first, even a broken one.'
        );

        return self::SUCCESS;
    }
}
