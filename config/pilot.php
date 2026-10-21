<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Pilot mode
    |--------------------------------------------------------------------------
    |
    | Off by default. A paid/production install simply omits PILOT_MODE (or sets
    | it to false) and none of this code path ever runs. Set PILOT_MODE=true and
    | PILOT_START_DATE=YYYY-MM-DD in .env to run a timed pilot.
    */
    'enabled' => (bool) env('PILOT_MODE', false),

    // The date YOU told the client the pilot started. Required when enabled=true.
    'started_at' => env('PILOT_START_DATE'),

    'length_days' => (int) env('PILOT_LENGTH_DAYS', 90),
    'warning_days' => (int) env('PILOT_WARNING_DAYS', 10),

    // Set this (via .env or `php artisan pilot:license <key>`) to convert a pilot
    // to paid without touching dates at all. Checked before any date logic.
    'license_key' => env('PILOT_LICENSE_KEY'),
];
