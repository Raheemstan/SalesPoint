<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    /** @use HasFactory<\Database\Factories\SaleFactory> */
    use HasFactory;



    protected $fillable = [
        'invoice_number',
        'user_id',
        'customer_id',
        'total_amount',
        'discount_amount',
        'tax_amount',
        'grand_total',
        'payment_method',
        'paid_amount',
        'change_due',
        'sale_date'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
