<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A cart put aside so the queue can move: the customer who forgot their wallet,
 * the trade order being checked in the back, the table that has not asked for
 * the bill yet.
 *
 * It is not a sale. Nothing is committed, no stock is reserved, no figure
 * anywhere counts it - which is why the lines live in a JSON column rather than
 * in `sale_items`, where every report would have to learn to ignore them.
 *
 * `item_count` and `total` are a snapshot for the list screen. Prices are read
 * from the catalogue again when the cart is resumed, so a figure that has gone
 * stale here shows a slightly wrong preview and can never reach a sale.
 */
class ParkedSale extends Model
{
    /** @use HasFactory<\Database\Factories\ParkedSaleFactory> */
    use HasFactory;

    /**
     * No 0/O or 1/I: the reference exists to be read aloud across a counter
     * ("hold A4K9, please"), and those four characters are where that fails.
     */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected $fillable = [
        'reference',
        'user_id',
        'customer_id',
        'cart',
        'discount',
        'note',
        'item_count',
        'total',
    ];

    protected $casts = [
        'cart' => 'array',
        'discount' => 'array',
    ];

    /** The cashier who parked it. Only they may resume it. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * A short unique reference.
     *
     * Checked against the table rather than trusted to be unique: the column has
     * a unique index, and a collision should cost a retry rather than throw a
     * database error at a cashier mid-sale.
     */
    public static function newReference(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $reference = 'HOLD-' . self::randomCode();

            if (!static::where('reference', $reference)->exists()) {
                return $reference;
            }
        }

        // Ten collisions in a 32^4 space means something is very wrong; fall back
        // to something that cannot collide rather than refusing to park the sale.
        return 'HOLD-' . now()->format('Hisv');
    }

    private static function randomCode(): string
    {
        $code = '';

        for ($i = 0; $i < 4; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /** Newest first: a held cart is nearly always the one just put down. */
    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
