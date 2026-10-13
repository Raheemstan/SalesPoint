<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One tender on a sale: the cash part, the card part, the transfer part.
 *
 * `amount` is money the drawer *kept*, not money the customer held out. A 215
 * sale settled with a 300 note writes 215 here and 85 into `sales.change_due`;
 * the alternative would make every drawer with change in it look over.
 * ShiftService adds these up for its per-tender expected figures.
 *
 * A sale paid entirely on credit has no rows at all, which is the truth: no
 * money arrived.
 */
class SalePayment extends Model
{
    /** @use HasFactory<\Database\Factories\SalePaymentFactory> */
    use HasFactory;

    /**
     * Deliberately the same whitelist as POSController::PAYMENT_METHODS,
     * Shift::METHODS and CustomerPayment::METHODS. A tender that can be taken
     * must be a tender that can be counted, or it never gets reconciled.
     */
    public const METHODS = ['cash', 'pos', 'transfer'];

    protected $fillable = [
        'sale_id',
        'method',
        'amount',
        'reference',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    /** "POS" is an initialism; the others are ordinary words. */
    public static function label(string $method): string
    {
        return $method === 'pos' ? 'POS' : ucfirst($method);
    }
}
