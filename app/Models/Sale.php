<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    /** @use HasFactory<\Database\Factories\SaleFactory> */
    use BelongsToBranch, HasFactory;

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partially_refunded';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_VOIDED = 'voided';

    /**
     * How much of the money has actually been collected. Separate from `status`,
     * which tracks the refund lifecycle: a sale can be fully paid and refunded,
     * or unpaid and never refunded.
     */
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_PARTIAL = 'partial';
    public const PAYMENT_UNPAID = 'unpaid';

    protected $fillable = [
        'invoice_number',
        'user_id',
        'customer_id',
        'shift_id',
        'total_amount',
        'discount_amount',
        'tax_amount',
        'grand_total',
        'payment_method',
        'paid_amount',
        'change_due',
        'sale_date',
        'status',
        'tax_rate',
        'discount_type',
        'discount_value',
        'refunded_amount',
        'voided_at',
        'voided_by',
        'void_reason',
        'payment_status',
        'balance_due',
        'due_date',
        // A tip or service charge, stored rather than derived: a reprint has to
        // render from the sale row alone, and a guest is entitled to see the
        // charge named rather than folded into the total.
        'service_charge',
        'service_charge_label',
        /*
         * Offline sales. `client_uuid` is the id the till gave this sale on the
         * device, before any server saw it; its unique index is what stops a
         * replayed queue banking the same sale twice. `synced_at` is when the
         * server took it, which is not when it was sold - `sale_date` and
         * `created_at` carry that.
         */
        'client_uuid',
        'synced_at',
    ];

    protected $casts = [
        'voided_at' => 'datetime',
        'due_date' => 'date',
        'synced_at' => 'datetime',
    ];

    /** Was this sale rung up offline and replayed, rather than taken live? */
    public function wasOffline(): bool
    {
        return $this->client_uuid !== null;
    }

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

    public function refunds()
    {
        return $this->hasMany(Refund::class)->latest();
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** The till shift this sale was rung up during (null for sales made before shifts existed). */
    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    /** The tenders that made up this sale: one row per method when payment was split. */
    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }

    /** Money collected later against this sale, when it was sold on credit. */
    public function creditAllocations()
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    /** Can anything still be refunded on this sale? */
    public function isRefundable(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_PARTIAL], true);
    }

    /** What the sale is worth after refunds. */
    public function getNetTotalAttribute(): float
    {
        return round((float) $this->grand_total - (float) $this->refunded_amount, 2);
    }

    /** Was any part of this sale left to be paid later? */
    public function isCredit(): bool
    {
        return in_array($this->payment_status, [self::PAYMENT_PARTIAL, self::PAYMENT_UNPAID], true);
    }

    /** Still owed on this sale, never negative. */
    public function getOutstandingAttribute(): float
    {
        return max(0, round((float) $this->balance_due, 2));
    }

    /** Past its due date with money still owed. */
    public function isOverdue(): bool
    {
        return $this->outstanding > 0
            && $this->due_date !== null
            && $this->due_date->isPast()
            && $this->status !== self::STATUS_VOIDED;
    }

    public function scopeOutstanding($query)
    {
        return $query->where('balance_due', '>', 0)->where('status', '!=', self::STATUS_VOIDED);
    }
}
