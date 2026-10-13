<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One product on one count sheet.
 *
 * `system_quantity` and `cost_price` are snapshots from when the sheet was
 * created, not live values: the whole point of a stock-take is to compare what
 * was believed *then* with what was found, and to value the difference at the
 * cost it was carried at.
 */
class StockTakeItem extends Model
{
    /** @use HasFactory<\Database\Factories\StockTakeItemFactory> */
    use HasFactory;

    protected $fillable = [
        'stock_take_id',
        'product_id',
        'system_quantity',
        'counted_quantity',
        'variance',
        'cost_price',
        'note',
    ];

    protected $casts = [
        'system_quantity' => 'integer',
        'counted_quantity' => 'integer',
        'variance' => 'integer',
    ];

    public function stockTake()
    {
        return $this->belongsTo(StockTake::class);
    }

    public function product()
    {
        // withTrashed: a sheet must still read correctly if an item is archived later.
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function isCounted(): bool
    {
        return $this->counted_quantity !== null;
    }

    /** What this line's variance cost (negative = stock missing). */
    public function getVarianceValueAttribute(): float
    {
        return round($this->variance * (float) $this->cost_price, 2);
    }
}
