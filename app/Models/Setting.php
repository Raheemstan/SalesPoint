<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value'];
    public $timestamps = false;

    protected static function booted(): void
    {
        // Keep the cached settings array (used by the setting() helper) in sync
        // no matter how a setting is written.
        $flush = fn () => Cache::forget('settings_cache');
        static::saved($flush);
        static::deleted($flush);
    }

    public static function get($key, $default = null)
    {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    public static function set($key, $value)
    {
        return static::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
    }
}
