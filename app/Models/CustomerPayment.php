<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Money a customer paid against what they owe.
 *
 * This row is the money that actually arrived; `allocations` says which invoices
 * it settled. Keeping the two apart means a single payment spread over three
 * invoices stays auditable, and reversing it only has to undo its allocations.
 */
class CustomerPayment extends Model
{
    use HasFactory;

    /** How the money came in. Same whitelist as the till and refunds use. */
    public const METHODS = ['cash', 'pos', 'transfer'];

    protected $fillable = [
        'customer_id',
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

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /** Who took the money. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function allocations()
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    /** The invoices this payment was spread across. */
    public function sales()
    {
        return $this->belongsToMany(Sale::class, 'customer_payment_allocations')->withPivot('amount');
    }
}
