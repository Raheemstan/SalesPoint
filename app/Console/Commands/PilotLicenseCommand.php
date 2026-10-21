<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\PilotStatus;
use Illuminate\Console\Command;

/**
 * The normal, intended way to end a pilot: the customer converts to paid and you
 * (or they, over the phone with you) run this on the server. It never touches
 * dates or data - it just stores a key that makes PilotStatus::licensed true.
 */
class PilotLicenseCommand extends Command
{
    protected $signature = 'pilot:license {key? : The licence key to store. Omit to clear it.}';
    protected $description = 'Set or clear the licence key that exempts this install from the pilot clock';

    public function handle(): int
    {
        $key = $this->argument('key');

        Setting::set('pilot_license_key', $key ?? '');

        if ($key) {
            $this->info('Licence key stored. This install is no longer subject to the pilot clock.');
        } else {
            $this->info('Licence key cleared. This install is subject to the pilot clock again (if PILOT_MODE=true).');
        }

        $this->call('pilot:status');

        return self::SUCCESS;
    }
}
