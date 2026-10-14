<?php

namespace App\Console\Commands;

use App\Services\BackupEncrypter;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Show or replace the backup encryption key.
 *
 * The settings screen never shows the key - a secret rendered into a web page
 * is a secret in a browser cache, a screenshot and a support ticket. But a key
 * nobody can read is a key nobody can write down, and an encrypted backup
 * without its key is a file of noise. So the key comes out here, on the server
 * console, which is the same place the .env file already is.
 */
class BackupKey extends Command
{
    protected $signature = 'salespoint:backup:key
                            {--show : print the current key instead of making a new one}';

    protected $description = 'Show the backup encryption key, or generate a new one';

    public function handle(BackupService $backups): int
    {
        if ($this->option('show')) {
            if (!$backups->hasEncryptionKey()) {
                $this->components->warn('No backup encryption key is set yet. One is generated the first time a backup is encrypted, or run this command without --show.');

                return self::FAILURE;
            }

            try {
                $this->line($backups->encryptionKey());
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        if ($backups->hasEncryptionKey()) {
            /*
             * Replacing the key does not re-encrypt anything. Every backup
             * already in the bucket still needs the old one, which is why this
             * asks rather than just doing it.
             */
            $this->components->warn(
                'A key is already set. Replacing it means every backup taken so far can only be opened with the '
                . 'OLD key - write that one down first (run this command with --show).'
            );

            if (!$this->confirm('Replace the backup encryption key?', false)) {
                return self::SUCCESS;
            }
        }

        $key = BackupEncrypter::newKey();
        $backups->storeEncryptionKey($key);

        $this->newLine();
        $this->components->info('This is the new backup encryption key. It is shown once:');
        $this->line('    ' . $key);
        $this->newLine();
        $this->components->warn(
            'Write it down somewhere that is not this machine and not the backup itself. Without it an '
            . 'encrypted backup cannot be opened by anyone, including us.'
        );

        return self::SUCCESS;
    }
}
