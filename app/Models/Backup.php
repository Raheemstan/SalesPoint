<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One backup run - the manual button, or the scheduler at night.
 *
 * A failed run is recorded too, with its error. That is the whole point of the
 * table: a backup system that only records its successes tells you nothing
 * about the night it stopped working, which is the night you needed it.
 *
 * `status` is about the local copy and `offsite_status` about the second copy
 * somewhere else. They are deliberately separate: a dump that was written to
 * disk and then failed to upload is a *successful* backup with an offsite
 * problem, and calling the whole run failed would hide the copy the shop has.
 */
class Backup extends Model
{
    use HasFactory;

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    /** Offsite backup is switched off, or this run never got as far as trying. */
    public const OFFSITE_OFF = 'off';

    /** Owed: the upload has not happened yet, and will be retried. */
    public const OFFSITE_PENDING = 'pending';

    /** There, verified, with a checksum recorded. */
    public const OFFSITE_UPLOADED = 'uploaded';

    /**
     * Refused for a reason that will not fix itself - wrong keys, no
     * permission, no bucket. Still retried, but slowly, and said loudly.
     */
    public const OFFSITE_FAILED = 'failed';

    /** The offsite copy was there and offsite retention has since removed it. */
    public const OFFSITE_EXPIRED = 'expired';

    protected $fillable = [
        'filename', 'path', 'disk', 'driver', 'size_bytes', 'status', 'error', 'created_by',
        'offsite_status', 'offsite_disk', 'offsite_path', 'offsite_size_bytes', 'offsite_checksum',
        'offsite_encrypted', 'offsite_error', 'offsite_attempts', 'offsite_next_attempt_at',
        'offsite_uploaded_at', 'local_pruned_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'offsite_size_bytes' => 'integer',
        'offsite_encrypted' => 'boolean',
        'offsite_attempts' => 'integer',
        'offsite_next_attempt_at' => 'datetime',
        'offsite_uploaded_at' => 'datetime',
        'local_pruned_at' => 'datetime',
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

    /** Is there a verified copy of this backup somewhere other than this machine? */
    public function isOffsite(): bool
    {
        return $this->offsite_status === self::OFFSITE_UPLOADED;
    }

    /**
     * Is an offsite copy still owed?
     *
     * Retention asks this before deleting anything: while the answer is yes,
     * the local file is the only copy in existence and must survive whatever
     * the retention rules say.
     */
    public function offsiteOwed(): bool
    {
        return in_array($this->offsite_status, [self::OFFSITE_PENDING, self::OFFSITE_FAILED], true);
    }

    /** Is the local file still supposed to be there? */
    public function hasLocalCopy(): bool
    {
        return $this->local_pruned_at === null && $this->path !== '' && $this->path !== null;
    }

    /** Backups still waiting to go offsite, oldest first. */
    public function scopeAwaitingOffsite(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUCCESS)
            ->whereIn('offsite_status', [self::OFFSITE_PENDING, self::OFFSITE_FAILED])
            ->orderBy('id');
    }

    /** "1.4 MB" - the history list only needs to show roughly how big it is. */
    public function getSizeForHumansAttribute(): string
    {
        return self::bytesForHumans((int) $this->size_bytes);
    }

    /** The same, for the compressed and encrypted copy that went offsite. */
    public function getOffsiteSizeForHumansAttribute(): string
    {
        return self::bytesForHumans((int) $this->offsite_size_bytes);
    }

    /** A short phrase for the screen: "Offsite", "Waiting to upload", and so on. */
    public function getOffsiteLabelAttribute(): string
    {
        return match ($this->offsite_status) {
            self::OFFSITE_UPLOADED => 'Offsite',
            self::OFFSITE_PENDING => 'Waiting to upload',
            self::OFFSITE_FAILED => 'Upload refused',
            self::OFFSITE_EXPIRED => 'Offsite copy expired',
            default => 'Local only',
        };
    }

    public static function bytesForHumans(int $bytes): string
    {
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
