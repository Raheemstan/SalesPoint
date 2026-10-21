<?php

use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Services\PilotStatus;
use Carbon\Carbon;

/*
| Covers exactly the rule that was asked for: read the start date from .env AND a
| settings row; if they disagree, or the date has passed, block; warn for the 10
| days before that; and when locked, block the WHOLE app - but never touch,
| export or delete a single row of data.
*/

function setPilot(?string $envDate, int $length = 90, int $warn = 10, ?string $license = null): void
{
    config([
        'pilot.enabled' => true,
        'pilot.started_at' => $envDate,
        'pilot.length_days' => $length,
        'pilot.warning_days' => $warn,
        'pilot.license_key' => $license,
    ]);
}

function disablePilot(): void
{
    config(['pilot.enabled' => false]);
}

beforeEach(function () {
    disablePilot(); // every other test suite in the app must be unaffected by default
});

test('pilot mode off (the default) never locks anything, and touches no settings row', function () {
    $user = User::factory()->admin()->create();
    $this->actingAs($user)->get('/dashboard')->assertOk();

    expect(Setting::where('key', PilotStatus::SETTING_KEY)->exists())->toBeFalse();
});

test('first boot seeds the stored date from .env and does not lock (day 1 of 90)', function () {
    setPilot('2026-10-01');
    Carbon::setTestNow('2026-10-01 09:00:00');

    $this->get('/login')->assertOk(); // not 402

    expect(Setting::get(PilotStatus::SETTING_KEY))->toBe('2026-10-01');
});

test('matching dates, well inside the window: fully usable, no warning', function () {
    setPilot('2026-07-01');
    Setting::set(PilotStatus::SETTING_KEY, '2026-07-01');
    Carbon::setTestNow('2026-08-01'); // 31 days in, 59 left

    $status = new PilotStatus();

    expect($status->locked)->toBeFalse()
        ->and($status->warning)->toBeFalse()
        ->and($status->daysLeft)->toBeGreaterThan(10);
});

test('a mismatch between .env and the stored date locks immediately, however far from 90 days', function () {
    setPilot('2026-10-15'); // someone rolled .env back, hoping to reset the clock
    Setting::set(PilotStatus::SETTING_KEY, '2026-07-01'); // real original start
    Carbon::setTestNow('2026-07-05'); // nowhere near 90 days from EITHER date

    $status = new PilotStatus();

    expect($status->datesDisagree)->toBeTrue()
        ->and($status->locked)->toBeTrue()
        ->and($status->reason())->toContain('disagree');
});

test('the mismatch lock uses the EARLIEST of the two dates, so editing .env can only shorten, never extend, the pilot', function () {
    setPilot('2026-01-01'); // an attempt to push the date far into the past, hoping expiry already "happened" differently
    Setting::set(PilotStatus::SETTING_KEY, '2026-07-01');
    Carbon::setTestNow('2026-07-05');

    $status = new PilotStatus();

    // Still locked for disagreeing - the earliest-date rule matters for anyone
    // reading $status->expiresAt while debugging, not for whether it locks here.
    expect($status->locked)->toBeTrue();
    expect($status->startedAt->toDateString())->toBe('2026-01-01');
});

test('exactly on the 90th day it is still open; the day after, it is locked', function () {
    setPilot('2026-01-01');
    Setting::set(PilotStatus::SETTING_KEY, '2026-01-01');

    Carbon::setTestNow(Carbon::parse('2026-01-01')->addDays(90)->endOfDay());
    expect((new PilotStatus())->locked)->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-01-01')->addDays(90)->endOfDay()->addSecond());
    expect((new PilotStatus())->locked)->toBeTrue();
});

test('the warning appears for exactly the last 10 days, and not before', function () {
    setPilot('2026-01-01', length: 90, warn: 10);
    Setting::set(PilotStatus::SETTING_KEY, '2026-01-01');
    $expiry = Carbon::parse('2026-01-01')->addDays(90)->endOfDay();

    Carbon::setTestNow($expiry->copy()->subDays(11));
    expect((new PilotStatus())->warning)->toBeFalse();

    Carbon::setTestNow($expiry->copy()->subDays(10)->startOfDay());
    $status = new PilotStatus();
    expect($status->warning)->toBeTrue()->and($status->locked)->toBeFalse()
        ->and($status->daysLeft)->toBe(10);

    Carbon::setTestNow($expiry->copy()->subDay());
    expect((new PilotStatus())->daysLeft)->toBe(1);
});

test('when locked, EVERY route is blocked, including login and the API, with no session required', function () {
    setPilot('2026-01-01');
    Setting::set(PilotStatus::SETTING_KEY, '2026-01-01');
    Carbon::setTestNow('2026-06-01'); // well past 90 days

    $this->get('/login')->assertStatus(402);
    $this->get('/pos')->assertStatus(402);
    $this->get('/dashboard')->assertStatus(402);
    $this->getJson('/pos')->assertStatus(402)->assertJsonStructure(['message']);

    $this->get('/login')->assertSee('Pilot ended')->assertSee('09071140264');
});

test('a logged-in user is ALSO blocked once the pilot expires mid-session', function () {
    $user = User::factory()->admin()->create();
    setPilot('2026-01-01');
    Setting::set(PilotStatus::SETTING_KEY, '2026-01-01');
    Carbon::setTestNow('2026-06-01');

    $this->actingAs($user)->get('/dashboard')->assertStatus(402);
});

test('a valid licence key exempts the install from the lock entirely, even past 90 days', function () {
    setPilot('2026-01-01');
    Setting::set(PilotStatus::SETTING_KEY, '2026-01-01');
    Setting::set('pilot_license_key', 'PAID-ABC123');
    Carbon::setTestNow('2026-12-01');

    $this->get('/login')->assertOk();
    expect((new PilotStatus())->licensed)->toBeTrue();
});

test('a mismatch is irrelevant once licensed: the licence check runs first', function () {
    setPilot('1999-01-01'); // garbage / conflicting .env value
    Setting::set(PilotStatus::SETTING_KEY, '2026-01-01');
    Setting::set('pilot_license_key', 'PAID-ABC123');
    Carbon::setTestNow('2026-06-01');

    expect((new PilotStatus())->locked)->toBeFalse();
});

test('pilot:license stores and clears the key via the console, matching the service', function () {
    setPilot('2026-01-01');
    Setting::set(PilotStatus::SETTING_KEY, '2026-01-01');
    Carbon::setTestNow('2026-06-01');

    $this->artisan('pilot:license PAID-XYZ')->assertSuccessful();
    expect((new PilotStatus())->licensed)->toBeTrue();

    $this->artisan('pilot:license')->assertSuccessful(); // no arg = clear
    expect((new PilotStatus())->licensed)->toBeFalse();
});

test('pilot:extend refuses to run without the confirmation flag, and does nothing', function () {
    setPilot('2026-01-01');
    Setting::set(PilotStatus::SETTING_KEY, '2026-01-01');

    $this->artisan('pilot:extend 14')->assertFailed();

    expect(Setting::get(PilotStatus::SETTING_KEY))->toBe('2026-01-01');
});

test('pilot:extend with the flag rewrites only the stored date, and nothing else in the database', function () {
    setPilot('2026-01-01');
    Setting::set(PilotStatus::SETTING_KEY, '2026-01-01');
    Carbon::setTestNow('2026-06-01');

    $before = Setting::count();

    $this->artisan('pilot:extend 14 --yes-i-am-sure')->assertSuccessful();

    expect(Setting::count())->toBe($before) // same number of rows: nothing added or removed
        ->and(Setting::get(PilotStatus::SETTING_KEY))->not->toBe('2026-01-01');
});

test('pilot:status reports cleanly whether on or off', function () {
    disablePilot();
    $this->artisan('pilot:status')->assertSuccessful()->expectsOutputToContain('off');

    setPilot('2026-01-01');
    Setting::set(PilotStatus::SETTING_KEY, '2026-01-01');
    $this->artisan('pilot:status')->assertSuccessful()->expectsOutputToContain('ON');
});

test('CRITICAL: nothing in the pilot system ever deletes or modifies a business data row', function () {
    $user = User::factory()->admin()->create();
    $product = \App\Models\Product::factory()->create(['stock_quantity' => 50, 'sale_price' => 10]);
    $this->actingAs($user);
    $this->withSession(['cart' => cartOf([[$product, 2]])])->post(route('pos.checkout'), [
        'payment_method' => 'cash', 'paid_amount' => 100,
    ]);
    $saleCountBefore = Sale::count();
    $stockBefore = $product->fresh()->stock_quantity;
    expect($saleCountBefore)->toBeGreaterThan(0);

    // Run through every pilot code path: seed, mismatch-lock, expiry-lock, license, extend.
    setPilot('2026-01-01');
    Setting::set(PilotStatus::SETTING_KEY, '2026-01-01');
    Carbon::setTestNow('2026-06-01');
    $this->get('/dashboard'); // locked
    $this->artisan('pilot:extend 5 --yes-i-am-sure');
    $this->artisan('pilot:license KEY');
    $this->get('/dashboard'); // licensed, unlocked

    expect(Sale::count())->toBe($saleCountBefore)
        ->and($product->fresh()->stock_quantity)->toBe($stockBefore)
        ->and(\App\Models\AuditLog::where('action', 'like', '%export%')->count())->toBe(0);
});

test('the warning banner appears on a normal page during the warning window, and never when pilot mode is off', function () {
    $user = User::factory()->admin()->create();

    setPilot('2026-01-01', warn: 10);
    Setting::set(PilotStatus::SETTING_KEY, '2026-01-01');
    Carbon::setTestNow(Carbon::parse('2026-01-01')->addDays(85)); // 5 days left, inside the 10-day window

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Pilot ends in');

    disablePilot();
    $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee('Pilot ends in');
});
