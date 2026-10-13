<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Goods sent back to a supplier: the mirror image of a customer refund.
 * Built by App\Services\PurchaseReturnService, never written to directly.
 */
class PurchaseReturn extends Model
{
    /** @use HasFactory<\Database\Factories\PurchaseReturnFactory> */
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'supplier_id',
        'user_id',
        'reference',
        'total_amount',
        'reason',
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }
}
