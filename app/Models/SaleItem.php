<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id', 'product_id', 'quantity', 'price', 'cost_price', 'total',
        'discount_amount', 'tax_amount', 'paid_total', 'refunded_quantity', 'refunded_amount',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        // withTrashed: history must still show products that were later deleted
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * The serials / IMEIs recorded for this line: one row per unit sold.
     *
     * Written through App\Services\SerialService, never directly, so the count
     * always matches the quantity and no unit can be sold twice.
     */
    public function serials()
    {
        return $this->hasMany(SaleItemSerial::class);
    }

    public function getRefundableQuantityAttribute(): int
    {
        return max(0, (int) $this->quantity - (int) $this->refunded_quantity);
    }

    /** Does this line's product expect a serial for every unit? */
    public function requiresSerials(): bool
    {
        return (bool) $this->product?->requiresSerials();
    }

    /** How many serials this line is still missing (0 when it is complete). */
    public function missingSerials(): int
    {
        $recorded = $this->relationLoaded('serials') ? $this->serials->count() : $this->serials()->count();

        return max(0, (int) $this->quantity - $recorded);
    }
}
