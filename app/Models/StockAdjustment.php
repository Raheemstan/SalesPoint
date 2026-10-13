<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One stock correction, with a reason and an author.
 *
 * `quantity` is always positive; `type` carries the direction. Keeping the sign
 * out of the number means a report can total "stock lost to damage" without
 * having to know which way round the sign goes.
 */
class StockAdjustment extends Model
{
    /** @use HasFactory<\Database\Factories\StockAdjustmentFactory> */
    use HasFactory;

    public const TYPE_INCREASE = 'increase';
    public const TYPE_DECREASE = 'decrease';

    public const TYPES = [self::TYPE_INCREASE, self::TYPE_DECREASE];

    /**
     * Why the stock moved. 'count' is reserved for stock-take approval, so a
     * manager can tell a counted correction from a hand-entered one.
     */
    public const REASONS = ['damage', 'theft', 'expiry', 'count', 'correction', 'other'];

    protected $fillable = [
        'product_id',
        'user_id',
        'type',
        'quantity',
        'reason',
        'note',
        'cost_price',
        'stock_take_id',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function product()
    {
        // withTrashed: history must still show products that were later archived.
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function stockTake()
    {
        return $this->belongsTo(StockTake::class);
    }

    /** The change as a signed number, for display and for totalling. */
    public function getSignedQuantityAttribute(): int
    {
        return $this->type === self::TYPE_DECREASE ? -$this->quantity : $this->quantity;
    }

    /** What the movement was worth at the cost recorded with it. */
    public function getValueAttribute(): float
    {
        return round($this->signed_quantity * (float) $this->cost_price, 2);
    }

    /** Came out of an approved count rather than being typed in by hand. */
    public function scopeFromStockTake($query)
    {
        return $query->whereNotNull('stock_take_id');
    }

    public function scopeReason($query, ?string $reason)
    {
        return $reason ? $query->where('reason', $reason) : $query;
    }
}
