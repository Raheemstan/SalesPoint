<?php

namespace App\Console\Commands;

use App\Services\BackupEncrypter;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Turn an encrypted backup file back into a database dump.
 *
 * This command is the reason the encryption is safe to switch on. A backup that
 * can only be opened by the app that made it is not a backup: the day you need
 * it is the day the app is gone. So:
 *
 *  - it works on a *file*, not a database row, so a file fished out of a bucket
 *    by hand is enough;
 *  - the key can be given on the command line, so it works on a machine with no
 *    SalesPoint database to read the setting from;
 *  - and the file format is documented in BackupEncrypter, with the salt and IV
 *    inside the file, so even this command is not strictly needed. The openssl
 *    commands that do the same thing by hand are in the project notes.
 */
class DecryptBackup extends Command
{
    protected $signature = 'salespoint:backup:decrypt
                            {file : the .gz.enc file to open}
                            {--out= : where to write the result (default: alongside the input, without .enc)}
                            {--key= : the encryption key (default: the one in Settings)}
                            {--keep-gzip : stop after decrypting, leaving the .gz compressed}';

    protected $description = 'Decrypt (and decompress) an encrypted SalesPoint backup file';

    public function handle(BackupService $backups, BackupEncrypter $encrypter): int
    {
        $file = (string) $this->argument('file');

        if (!is_file($file)) {
            $this->components->error('There is no file at "' . $file . '".');

            return self::FAILURE;
        }

        if (!$encrypter->isEncrypted($file)) {
            $this->components->error(
                '"' . basename($file) . '" is not an encrypted SalesPoint backup. A plain .sql or .sqlite file '
                . 'can be restored as it is, and a .gz needs only gunzip.'
            );

            return self::FAILURE;
        }

        try {
            $key = (string) ($this->option('key') ?: $backups->encryptionKey());
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        // Dropping ".enc" gives the compressed dump; dropping ".gz" too gives
        // the dump itself.
        $gz = (string) ($this->option('out') ?: preg_replace('/\.enc$/', '', $file));

        if ($gz === $file) {
            $gz = $file . '.decrypted';
        }

        try {
            $encrypter->decryptFile($file, $gz, $key);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Decrypted to ' . $gz);

        if ($this->option('keep-gzip') || !str_ends_with($gz, '.gz')) {
            return self::SUCCESS;
        }

        $dump = substr($gz, 0, -3);

        try {
            $encrypter->gunzip($gz, $dump);
        } catch (Throwable $e) {
            $this->components->error('Decrypted, but could not decompress: ' . $e->getMessage());

            return self::FAILURE;
        }

        @unlink($gz);

        $this->components->info('Decompressed to ' . $dump);
        $this->components->info('That file is the database dump. See the restore notes before loading it.');

        return self::SUCCESS;
    }
}
