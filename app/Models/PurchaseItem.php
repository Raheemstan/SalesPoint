<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'purchase_id',
        'product_id',
        'quantity',
        'cost_price',
        'total',
        'returned_quantity'
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product()
    {
        // withTrashed: history must still show products that were later deleted
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function returnItems()
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    /** How much of this line can still go back to the supplier. */
    public function getReturnableQuantityAttribute(): int
    {
        return max(0, (int) $this->quantity - (int) $this->returned_quantity);
    }

    /** What has already been credited back on this line. */
    public function returnedAmount(): float
    {
        return round((float) $this->returnItems()->sum('amount'), 2);
    }
}
