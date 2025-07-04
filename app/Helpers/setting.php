<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

if (!function_exists('setting')) {
    function setting($key, $default = null)
    {
        $settingsCache = Cache::rememberForever('settings_cache', function () {
            return Setting::all()->pluck('value', 'key')->toArray();
        });

        return $settingsCache[$key] ?? $default;
    }
}

if (!function_exists('userId')) {
    function userId()
    {
        return auth()->check() ? auth()->id() : null;
    }
}
