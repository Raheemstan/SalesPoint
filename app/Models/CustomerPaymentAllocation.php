<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of a customer payment: how much of it went against one invoice.
 *
 * Written by CreditService, which guarantees the allocations of a payment add up
 * to the payment itself to the penny.
 */
class CustomerPaymentAllocation extends Model
{
    protected $fillable = [
        'customer_payment_id',
        'sale_id',
        'amount',
    ];

    public function payment()
    {
        return $this->belongsTo(CustomerPayment::class, 'customer_payment_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
