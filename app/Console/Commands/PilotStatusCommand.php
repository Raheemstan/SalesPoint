<?php

namespace App\Console\Commands;

use App\Services\PilotStatus;
use Illuminate\Console\Command;

class PilotStatusCommand extends Command
{
    protected $signature = 'pilot:status';
    protected $description = 'Show whether pilot mode is on, and if so how many days are left';

    public function handle(): int
    {
        $status = new PilotStatus();

        if (!$status->enabled) {
            $this->info('Pilot mode is off (PILOT_MODE is not true). This is a normal, unrestricted install.');

            return self::SUCCESS;
        }

        $this->line('Pilot mode: <fg=yellow>ON</>');
        $this->line('Licensed (exempt from the pilot clock): '.($status->licensed ? '<fg=green>yes</>' : 'no'));

        if ($status->startedAt) {
            $this->line('Start date in use: '.$status->startedAt->toDateString());
        }

        if ($status->expiresAt) {
            $this->line('Expires: '.$status->expiresAt->toDateString());
        }

        if ($status->datesDisagree) {
            $this->warn('.env and the stored start date DISAGREE - this alone has locked the app.');
        }

        $this->line('Currently locked: '.($status->locked ? '<fg=red>YES</>' : '<fg=green>no</>'));

        if (!$status->locked && $status->daysLeft !== null) {
            $this->line("Days left: {$status->daysLeft}");
        }

        $this->line('Reason: '.$status->reason());

        return self::SUCCESS;
    }
}
