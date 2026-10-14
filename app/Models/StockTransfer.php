<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock moving from one shop to another.
 *
 * WHY THIS IS NOT BRANCH-SCOPED LIKE EVERYTHING ELSE
 *
 * It has two branches, not one, so App\Models\Concerns\BelongsToBranch would
 * have to pick a side and be wrong for the other. A transfer is visible to both
 * ends and to nobody else, which is what scopeVisibleTo() expresses.
 *
 * WHY THERE ARE TWO EVENTS AND NOT ONE
 *
 * Because the goods spend real time on a van. Stock leaves the source when it is
 * dispatched and arrives at the destination when somebody there confirms it, and
 * in between it is counted in *neither* place. A single "transfer" event would
 * have to either credit the destination with goods it has not got, or leave them
 * on the source's shelf after they have gone - and both of those are how a shop
 * ends up selling something that is not there.
 *
 * The group-wide total (`products.stock_quantity`) dips for the same window, for
 * the same reason: goods in transit belong to no shelf. What is on the van is
 * not lost, it is on this record.
 */
class StockTransfer extends Model
{
    /** @use HasFactory<\Database\Factories\StockTransferFactory> */
    use HasFactory;

    /** Sent, on its way, not yet confirmed at the far end. */
    public const STATUS_IN_TRANSIT = 'in_transit';
    /** Confirmed at the destination; the stock is on its shelf. */
    public const STATUS_RECEIVED = 'received';
    /** Recalled by the sender before it was received; stock went back. */
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_IN_TRANSIT,
        self::STATUS_RECEIVED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'reference',
        'from_branch_id',
        'to_branch_id',
        'status',
        'created_by',
        'dispatched_at',
        'received_by',
        'received_at',
        'cancelled_by',
        'cancelled_at',
        'note',
    ];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function fromBranch()
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch()
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    public function isInTransit(): bool
    {
        return $this->status === self::STATUS_IN_TRANSIT;
    }

    public function isReceived(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** Total units on the transfer, for a list column. */
    public function totalQuantity(): int
    {
        return (int) ($this->relationLoaded('items')
            ? $this->items->sum('quantity')
            : $this->items()->sum('quantity'));
    }

    /** What is on the van, at what it cost us. */
    public function totalValue(): float
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        return round((float) $items->sum(fn (StockTransferItem $item) => $item->value()), 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Transfers either end of which is this branch.
     *
     * Not a global scope: a transfer has two branches, and a global scope
     * filtering on one column would hide half of them from the shop that needs
     * to act on them. The controller applies this explicitly instead, which is
     * also what makes the group-wide admin view a matter of not calling it.
     */
    public function scopeVisibleTo(Builder $query, Branch|int|null $branch): Builder
    {
        $id = $branch instanceof Branch ? $branch->getKey() : $branch;

        if ($id === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('from_branch_id', $id)
            ->orWhere('to_branch_id', $id));
    }

    /** Coming to this branch and not yet confirmed: the list that needs action. */
    public function scopeIncomingTo(Builder $query, Branch|int|null $branch): Builder
    {
        $id = $branch instanceof Branch ? $branch->getKey() : $branch;

        return $query->where('status', self::STATUS_IN_TRANSIT)
            ->when($id !== null, fn (Builder $q) => $q->where('to_branch_id', $id));
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $query->when(
            $status && in_array($status, self::STATUSES, true),
            fn (Builder $q) => $q->where('status', $status)
        );
    }
}
