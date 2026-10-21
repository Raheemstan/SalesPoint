<?php

namespace App\Console\Commands;

use App\Services\PilotStatus;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * A deliberate, human-run override for support cases - e.g. the pilot genuinely
 * needs another two weeks, or .env was edited by mistake and tripped the
 * mismatch lock. This is the ONE command that rewrites the stored start date; it
 * requires an explicit --yes-i-am-sure flag so it can never run by accident or
 * be scripted into something that fires unattended. It also re-aligns .env's
 * value in the message it prints, so you don't recreate the mismatch five
 * minutes later.
 */
class PilotExtendCommand extends Command
{
    protected $signature = 'pilot:extend
        {days : How many days from TODAY the pilot should now expire in}
        {--yes-i-am-sure : Required. This is a deliberate override, not something to script.}';

    protected $description = 'Manually reset the pilot clock so it expires N days from today';

    public function handle(): int
    {
        if (!$this->option('yes-i-am-sure')) {
            $this->error('Refusing to run without --yes-i-am-sure. This rewrites the pilot start date on purpose - '
                .'make sure that is really what you want before adding the flag.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->argument('days'));
        $length = (int) config('pilot.length_days', 90);
        $newStart = Carbon::now()->subDays($length - $days)->startOfDay();

        \App\Models\Setting::set(PilotStatus::SETTING_KEY, $newStart->toDateString());

        $this->info("Stored start date updated to {$newStart->toDateString()}, which expires in {$days} day(s).");
        $this->warn("IMPORTANT: update PILOT_START_DATE={$newStart->toDateString()} in .env too, "
            ."or the mismatch check will lock the app again on its next request.");

        $this->call('pilot:status');

        return self::SUCCESS;
    }
}
