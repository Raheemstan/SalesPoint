<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One cashier's turn at the till: opened with a cash float, closed with a
 * counted drawer. The variance (counted - expected) is the number the whole
 * feature exists for - it is what tells a mistake from theft.
 */
class Shift extends Model
{
    /** @use HasFactory<\Database\Factories\ShiftFactory> */
    use BelongsToBranch, HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    /**
     * The tenders a drawer is counted in. Deliberately the same list as the till's
     * payment methods (POSController::PAYMENT_METHODS): a method that can be taken
     * has to be a method that can be counted, or it would never be reconciled.
     */
    public const METHODS = ['cash', 'pos', 'transfer'];

    protected $fillable = [
        'user_id',
        'opened_at',
        'closed_at',
        'opening_float',
        'counted_cash',
        'counted_pos',
        'counted_transfer',
        'expected_cash',
        'expected_pos',
        'expected_transfer',
        'note',
        'close_note',
        'status',
        'closed_by',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /** Whose drawer this is. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Who signed the reconciliation off (the cashier, or a manager force-closing). */
    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    /** Refunds paid out of this drawer (not refunds of this shift's sales - see ShiftService). */
    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /** A readable label for a tender: "POS" is an initialism, the others are words. */
    public static function methodLabel(string $method): string
    {
        return $method === 'pos' ? 'POS' : ucfirst($method);
    }

    /**
     * Counted minus expected for one tender, against the figures frozen at closing.
     * Positive is over (more money than the records explain), negative is short.
     * Null while the drawer has not been counted - an uncounted tender has no
     * variance, and showing it as zero would read as "balanced".
     */
    public function variance(string $method): ?float
    {
        $counted = $this->{'counted_' . $method};

        if ($counted === null) {
            return null;
        }

        return round((float) $counted - (float) $this->{'expected_' . $method}, 2);
    }

    public function getVarianceCashAttribute(): ?float
    {
        return $this->variance('cash');
    }

    public function getVariancePosAttribute(): ?float
    {
        return $this->variance('pos');
    }

    public function getVarianceTransferAttribute(): ?float
    {
        return $this->variance('transfer');
    }

    /** The whole drawer's variance. Null until every tender has been counted. */
    public function getVarianceTotalAttribute(): ?float
    {
        $total = 0.0;

        foreach (self::METHODS as $method) {
            $variance = $this->variance($method);

            if ($variance === null) {
                return null;
            }

            $total += $variance;
        }

        return round($total, 2);
    }

    public function getExpectedTotalAttribute(): float
    {
        $total = 0.0;

        foreach (self::METHODS as $method) {
            $total += (float) $this->{'expected_' . $method};
        }

        return round($total, 2);
    }

    /** Null until something has been counted, so an open shift shows a dash rather than zero. */
    public function getCountedTotalAttribute(): ?float
    {
        $total = null;

        foreach (self::METHODS as $method) {
            $counted = $this->{'counted_' . $method};

            if ($counted !== null) {
                $total = round(($total ?? 0.0) + (float) $counted, 2);
            }
        }

        return $total;
    }
}
