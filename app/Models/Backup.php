<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One backup run - the manual button, or the scheduler at night.
 *
 * A failed run is recorded too, with its error. That is the whole point of the
 * table: a backup system that only records its successes tells you nothing
 * about the night it stopped working, which is the night you needed it.
 */
class Backup extends Model
{
    use HasFactory;

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'filename', 'path', 'disk', 'driver', 'size_bytes', 'status', 'error', 'created_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    /** Null when the scheduler ran it. */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /** "1.4 MB" - the history list only needs to show roughly how big it is. */
    public function getSizeForHumansAttribute(): string
    {
        $bytes = (int) $this->size_bytes;

        if ($bytes <= 0) {
            return '-';
        }

        foreach (['B', 'KB', 'MB', 'GB'] as $i => $unit) {
            if ($bytes < 1024 ** ($i + 1) || $unit === 'GB') {
                return round($bytes / (1024 ** $i), $i === 0 ? 0 : 1) . ' ' . $unit;
            }
        }

        return $bytes . ' B';
    }
}
