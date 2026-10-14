<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    use BelongsToBranch;

    protected $fillable = ['sale_id', 'user_id', 'shift_id', 'total_amount', 'credit_applied', 'tax_amount', 'method', 'reason', 'is_void'];

    protected $casts = ['is_void' => 'boolean'];

    /**
     * What actually left the drawer. On a credit sale part of a refund can simply
     * cancel the debt, in which case no money changes hands at all.
     */
    public function getCashOutAttribute(): float
    {
        return round((float) $this->total_amount - (float) $this->credit_applied, 2);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** The till shift the money was handed back during (null for refunds made before shifts existed). */
    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function items()
    {
        return $this->hasMany(RefundItem::class);
    }
}
