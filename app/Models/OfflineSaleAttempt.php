<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One arrival of one queued offline sale, and what the server decided about it.
 *
 * WHY THIS TABLE EXISTS
 *
 * A sale rung up offline has already happened: the goods are gone and the money
 * is in the drawer. When it reaches the server it can still turn out to be
 * unacceptable - the customer has gone past their credit limit since, the
 * discount is above what that cashier may give, the product was deleted
 * outright. Those are refused, and a refusal with no record is a sale that
 * simply disappears: nobody knows it existed, what was in it, who took it or
 * why it was turned away. For money that is not acceptable.
 *
 * So every verdict is written here - accepted, rejected and duplicate alike -
 * with the payload exactly as it arrived. A manager can read back what a refused
 * sale contained, fix the cause and have the till send it again, or ring it up
 * by hand from the record. An accepted row is the receipt for the sync itself:
 * it says which device id became which invoice.
 *
 * `payload` is the client's own description of the sale. It is kept verbatim as
 * *evidence*, and nothing reads a figure out of it to bank: every price, total
 * and tax on the resulting sale is recomputed by CheckoutService.
 */
class OfflineSaleAttempt extends Model
{
    /** @use HasFactory<\Database\Factories\OfflineSaleAttemptFactory> */
    use HasFactory;

    /** Banked: the sale exists and the till may forget it. */
    public const ACCEPTED = 'accepted';

    /** Refused: no sale was created and a human has to look at it. */
    public const REJECTED = 'rejected';

    /** Already banked by an earlier arrival of the same client_uuid. */
    public const DUPLICATE = 'duplicate';

    public const STATUSES = [self::ACCEPTED, self::REJECTED, self::DUPLICATE];

    protected $fillable = [
        'client_uuid',
        'user_id',
        'payload',
        'status',
        'reason',
        'sale_id',
        'received_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
    ];

    /** Who posted the batch. Not necessarily who rang the sale up - that is on the sale. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    /** The ones a human still has to deal with. */
    public function scopeNeedsAttention($query)
    {
        return $query->where('status', self::REJECTED);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('received_at')->orderByDesc('id');
    }

    /**
     * What this sale said it came to, for a list screen.
     *
     * Explicitly the *claimed* total, never a figure to bank or reconcile
     * against: a rejected sale has no server-computed total, because the whole
     * point of the refusal is that the server would not stand behind one. It is
     * here so a manager can see roughly what is at stake.
     */
    public function claimedTotal(): float
    {
        return round((float) ($this->payload['claimed_total'] ?? 0), 2);
    }

    /** How many lines the queued sale had, for the same screen. */
    public function lineCount(): int
    {
        return is_array($this->payload['lines'] ?? null) ? count($this->payload['lines']) : 0;
    }
}
