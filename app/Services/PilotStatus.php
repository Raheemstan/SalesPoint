<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;

/**
 * Whether a timed pilot install is still allowed to run.
 *
 * This NEVER deletes anything, exports anything, or touches the database beyond
 * reading and writing one settings row. The only effect of an expired or tampered
 * pilot is that EnforcePilotLock (the middleware) refuses to serve requests until
 * someone fixes it: enters a licence key, or runs an artisan command on the server.
 * Every path here is read-only or writes a single harmless timestamp.
 *
 * Two sources of truth, on purpose:
 *   - config('pilot.started_at')   comes from .env, set once at install time.
 *   - the `pilot_started_at` row   is written to the database THE FIRST TIME this
 *                                   class ever runs, and never updated again.
 *
 * If someone edits .env afterwards to push the start date back (hoping to reset
 * the 90-day clock), the two no longer agree. That disagreement is itself treated
 * as the pilot having ended, rather than as an extension - editing one copy of the
 * date can only ever end the pilot sooner, never later. The *earliest* of the two
 * dates is used to compute the expiry, for the same reason: neither source can be
 * used to buy extra time.
 */
class PilotStatus
{
    public const SETTING_KEY = 'pilot_started_at';

    public readonly bool $enabled;
    public readonly bool $licensed;
    public readonly bool $datesDisagree;
    public readonly ?Carbon $startedAt;
    public readonly ?Carbon $expiresAt;
    public readonly ?Carbon $warningFrom;
    public readonly bool $locked;
    public readonly bool $warning;
    public readonly ?int $daysLeft;

    public function __construct(?Carbon $now = null)
    {
        $now ??= Carbon::now();

        $this->enabled = (bool) config('pilot.enabled');

        if (!$this->enabled) {
            $this->licensed = true; // moot; nothing checks this when disabled
            $this->datesDisagree = false;
            $this->startedAt = $this->expiresAt = $this->warningFrom = null;
            $this->locked = $this->warning = false;
            $this->daysLeft = null;

            return;
        }

        $this->licensed = $this->checkLicense();

        [$this->startedAt, $this->datesDisagree] = $this->resolveStartDate();

        if ($this->licensed || $this->startedAt === null) {
            // A licensed install never locks. A pilot with no resolvable start date
            // (misconfigured: PILOT_MODE=true but no PILOT_START_DATE, and this is
            // somehow not the first boot either) locks immediately and loudly rather
            // than running unlimited - the middleware's locked page explains why.
            $this->expiresAt = $this->warningFrom = null;
            $this->locked = !$this->licensed && $this->startedAt === null;
            $this->warning = false;
            $this->daysLeft = null;

            return;
        }

        $length = max(1, (int) config('pilot.length_days', 90));
        $warnDays = max(0, (int) config('pilot.warning_days', 10));

        // Date-only anchor for the last valid calendar day, kept separate from the
        // precise endOfDay() cutoff used for locking - this is what "how many days
        // are left" should count against, not the number of hours to the second.
        $lastValidDate = $this->startedAt->copy()->addDays($length)->startOfDay();

        $this->expiresAt = $lastValidDate->copy()->endOfDay();
        $this->warningFrom = $lastValidDate->copy()->subDays($warnDays)->startOfDay();

        $this->locked = $this->datesDisagree || $now->greaterThan($this->expiresAt);
        $this->warning = !$this->locked && $now->greaterThanOrEqualTo($this->warningFrom);
        $this->daysLeft = $this->locked ? 0 : (int) $now->copy()->startOfDay()->diffInDays($lastValidDate);
    }

    /** Why the install is locked, for logs and support - never shown to the end user verbatim. */
    public function reason(): string
    {
        if (!$this->enabled || $this->licensed) {
            return 'not locked';
        }

        if ($this->startedAt === null) {
            return 'pilot mode is on but no start date could be resolved (check PILOT_START_DATE)';
        }

        if ($this->datesDisagree) {
            return 'the .env start date and the stored start date disagree - '
                .'most likely .env was edited after the pilot began';
        }

        if ($this->locked) {
            return "pilot expired on {$this->expiresAt->toDateString()}";
        }

        return 'not locked';
    }

    private function checkLicense(): bool
    {
        $configured = trim((string) config('pilot.license_key', ''));
        $stored = trim((string) Setting::get('pilot_license_key', ''));

        if ($configured === '' && $stored === '') {
            return false;
        }

        // Either place having a non-empty value unlocks it: a key in .env survives a
        // database restore, a key set via the artisan command survives a redeploy that
        // resets .env. They do not need to match each other - only one needs to be set.
        return $configured !== '' || $stored !== '';
    }

    /** @return array{0: ?Carbon, 1: bool} [the date to use, whether the two sources disagreed] */
    private function resolveStartDate(): array
    {
        $envRaw = trim((string) config('pilot.started_at', ''));
        $envDate = $envRaw !== '' ? $this->parse($envRaw) : null;

        $stored = Setting::get(self::SETTING_KEY);

        if ($stored === null) {
            // First time the pilot has ever run. Seed the database copy from .env if
            // given, else from today. This write happens exactly once per install.
            $seed = $envDate ?? Carbon::now();
            Setting::set(self::SETTING_KEY, $seed->toDateString());

            return [$seed, false];
        }

        $dbDate = $this->parse($stored);

        if ($dbDate === null) {
            // The stored value is corrupt somehow; re-seed from .env or now rather
            // than silently treating the pilot as never-expiring.
            $seed = $envDate ?? Carbon::now();
            Setting::set(self::SETTING_KEY, $seed->toDateString());

            return [$seed, false];
        }

        if ($envDate === null) {
            // .env has no date (or PILOT_MODE was switched on after the fact): the
            // stored date is all we have, and that is fine - it is still one honest
            // source, just not a second one to cross-check against.
            return [$dbDate, false];
        }

        if (!$envDate->isSameDay($dbDate)) {
            return [$envDate->min($dbDate), true];
        }

        return [$envDate, false];
    }

    private function parse(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
