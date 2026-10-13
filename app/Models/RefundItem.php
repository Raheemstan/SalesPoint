<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefundItem extends Model
{
    protected $fillable = ['refund_id', 'sale_item_id', 'product_id', 'quantity', 'amount', 'tax_amount', 'cost_price', 'restocked'];

    protected $casts = ['restocked' => 'boolean'];

    public function refund()
    {
        return $this->belongsTo(Refund::class);
    }

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
