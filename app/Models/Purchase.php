<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    /** @use HasFactory<\Database\Factories\PurchaseFactory> */
    use BelongsToBranch, HasFactory, SoftDeletes;

    /** Raised with the supplier; nothing has arrived, so no stock has moved. */
    public const STATUS_ORDERED = 'ordered';
    /** The goods are in and stock has been added. */
    public const STATUS_RECEIVED = 'received';
    /** Abandoned; anything it had added to stock has been taken back out. */
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_ORDERED, self::STATUS_RECEIVED, self::STATUS_CANCELLED];

    protected $fillable = [
        'invoice_number',
        'supplier_id',
        'user_id',
        'total_amount',
        'purchase_date',
        'note',
        'status',
        'amount_paid',
        'balance_due',
        'reference',
        'expected_at',
        'received_at',
    ];

    protected $casts = [
        'expected_at' => 'date',
        'received_at' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function returns()
    {
        return $this->hasMany(PurchaseReturn::class)->latest();
    }

    /**
     * Has this purchase put its quantities into stock? Only a received purchase
     * has: everything that moves stock has to ask this rather than assume.
     */
    public function hasMovedStock(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }

    /** Goods can only go back to a supplier if they ever arrived. */
    public function isReturnable(): bool
    {
        return $this->hasMovedStock();
    }

    /** Has any of this purchase already gone back to the supplier? */
    public function hasReturns(): bool
    {
        return $this->purchaseItems()->where('returned_quantity', '>', 0)->exists();
    }

    /** What has gone back to the supplier, at the cost they charged. */
    public function returnedAmount(): float
    {
        return round((float) $this->returns()->sum('total_amount'), 2);
    }

    /**
     * Still owed on this purchase: what was invoiced, less what was handed over
     * at the time, less anything returned. Never negative.
     */
    public function outstanding(): float
    {
        return max(0, round((float) $this->total_amount - (float) $this->amount_paid - $this->returnedAmount(), 2));
    }

    public function scopeReceived($query)
    {
        return $query->where('status', self::STATUS_RECEIVED);
    }
}
