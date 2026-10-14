<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One product on one transfer.
 *
 * No `branch_id`: it is reached through its transfer, which already knows both
 * ends. (See the 2026_10_14_000012 migration for the rule - a table earns a
 * branch column only if it is queried on its own.)
 *
 * `cost_price` is a snapshot, for the same reason stock-takes and sale lines
 * snapshot it: what the goods were worth when they moved must not change because
 * somebody edited the product afterwards.
 */
class StockTransferItem extends Model
{
    /** @use HasFactory<\Database\Factories\StockTransferItemFactory> */
    use HasFactory;

    protected $fillable = [
        'stock_transfer_id',
        'product_id',
        'quantity',
        'cost_price',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function transfer()
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product()
    {
        // withTrashed: a transfer is history, and history must keep the name of
        // something archived since.
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** What this line was worth when it was sent. */
    public function value(): float
    {
        return round((float) $this->cost_price * $this->quantity, 2);
    }
}
