<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Money paid to a supplier against their account, not against one invoice:
 * shops pay a round figure off a running balance and sort the paperwork later.
 */
class SupplierPayment extends Model
{
    /** @use HasFactory<\Database\Factories\SupplierPaymentFactory> */
    use HasFactory;

    public const METHODS = ['cash', 'transfer', 'pos', 'cheque'];

    protected $fillable = [
        'supplier_id',
        'user_id',
        'amount',
        'method',
        'reference',
        'note',
        'paid_at',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
