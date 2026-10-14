<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One counting session. It holds a frozen picture of what the system believed
 * ("system_quantity" on each line) and what staff actually found, and it only
 * moves real stock when it is approved.
 */
class StockTake extends Model
{
    /** @use HasFactory<\Database\Factories\StockTakeFactory> */
    use BelongsToBranch, HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_CANCELLED = 'cancelled';

    /** The only values `status` may hold; used by validation and the filter. */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'reference',
        'user_id',
        'approved_by',
        'status',
        'note',
        'counted_at',
        'approved_at',
    ];

    protected $casts = [
        'counted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(StockTakeItem::class);
    }

    public function adjustments()
    {
        return $this->hasMany(StockAdjustment::class);
    }

    /** Who counted. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Who approved, once somebody has. */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** Can counts still be typed in? Approved and cancelled sheets are history. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SUBMITTED], true);
    }

    /** Lines where somebody actually wrote a figure (0 counts; blank does not). */
    public function countedItems()
    {
        return $this->items()->whereNotNull('counted_quantity');
    }

    /**
     * What the variance is worth at the snapshot cost: negative means stock we
     * believed we had and do not - the money the shrinkage cost us.
     */
    public function varianceValue(): float
    {
        return round((float) $this->items()->whereNotNull('counted_quantity')
            ->get()
            ->sum(fn(StockTakeItem $item) => $item->variance_value), 2);
    }

    public function scopeStatus($query, ?string $status)
    {
        return $status ? $query->where('status', $status) : $query;
    }

    public function scopeAwaitingApproval($query)
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }
}
