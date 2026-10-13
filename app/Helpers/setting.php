<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

if (!function_exists('setting')) {
    /**
     * Read a setting. Pass null as the key to get every setting as an array.
     */
    function setting($key = null, $default = null)
    {
        $settings = Cache::rememberForever('settings_cache', function () {
            return Setting::all()->pluck('value', 'key')->toArray();
        });

        if ($key === null) {
            return $settings;
        }

        return $settings[$key] ?? $default;
    }
}

if (!function_exists('userId')) {
    function userId()
    {
        return auth()->check() ? auth()->id() : null;
    }
}

if (!function_exists('currency')) {
    /**
     * The configured currency symbol. Views must never hardcode one: the same
     * install is used in different countries.
     */
    function currency(): string
    {
        return (string) setting('currency_symbol', config('sectors.currency.symbol'));
    }
}

if (!function_exists('money')) {
    /**
     * Format an amount for display: symbol + grouped digits.
     *
     * @param  float|int|string|null  $amount
     * @param  bool  $symbol  false for a bare number (CSV, input values, chart data)
     */
    function money($amount, bool $symbol = true): string
    {
        $decimals = (int) setting('currency_decimals', config('sectors.currency.decimals'));
        $formatted = number_format((float) $amount, $decimals);

        return $symbol ? currency() . $formatted : $formatted;
    }
}

if (!function_exists('sector')) {
    /**
     * The active business type's preset, or one value from it by dot path.
     * Configured in Settings > Business; presets live in config/sectors.php.
     *
     *   sector()                  // the whole preset
     *   sector('features.expiry') // is batch/expiry tracking on for this trade?
     */
    function sector(?string $path = null, $default = null)
    {
        $types = config('sectors.types', []);
        $key = (string) setting('business_type', config('sectors.default', 'retail'));

        // An unknown saved type (config edited, setting left behind) must not blank the app.
        $preset = $types[$key] ?? $types[config('sectors.default', 'retail')] ?? [];
        $preset['key'] = $key;

        if ($path === null) {
            return $preset;
        }

        return data_get($preset, $path, $default);
    }
}

if (!function_exists('feature')) {
    /**
     * Is a sector feature switched on? A per-install setting ("feature_expiry")
     * overrides the business type's default, so a shop can always opt in or out.
     */
    function feature(string $name): bool
    {
        $override = setting('feature_' . $name);

        if ($override !== null && $override !== '') {
            return (string) $override === '1';
        }

        return (bool) sector('features.' . $name, false);
    }
}

if (!function_exists('term')) {
    /**
     * A user-facing noun for the active trade: a pharmacy sells "drugs",
     * a restaurant sells "menu items", a salon sells "services".
     *
     *   term('product')         // "Drug"
     *   term('product', true)   // "Drugs"  (plural)
     */
    function term(string $key, bool $plural = false, ?string $default = null): string
    {
        $value = sector('terms.' . $key . ($plural ? '_plural' : ''));

        if ($value === null && $plural) {
            // Fall back to a naive plural of the singular rather than showing nothing.
            $singular = sector('terms.' . $key, $default ?? ucfirst($key));

            return Illuminate\Support\Str::plural((string) $singular);
        }

        return (string) ($value ?? $default ?? ucfirst(str_replace('_', ' ', $key)));
    }
}
