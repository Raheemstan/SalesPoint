<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturnItem extends Model
{
    /** @use HasFactory<\Database\Factories\PurchaseReturnItemFactory> */
    use HasFactory;

    protected $fillable = [
        'purchase_return_id',
        'purchase_item_id',
        'product_id',
        'quantity',
        'unit_cost',
        'amount',
    ];

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function purchaseItem()
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function product()
    {
        // withTrashed: history must still show products that were later deleted
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
