<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone who buys from the shop. Called a Patient in a pharmacy, a Client in a
 * salon, an Account by a wholesaler - see term('customer') and config/sectors.php.
 *
 * What a customer owes is never stored on this row: it is the sum of the
 * `balance_due` left on their sales, so an invoice and the debt it created can
 * never drift apart. `withOutstanding()` fetches that sum for a whole page of
 * customers in one subquery instead of one query each.
 */
class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'phone', 'email', 'address', 'credit_limit', 'is_active', 'notes',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    /** Money received against this customer's account (not the tender taken at the till). */
    public function payments()
    {
        return $this->hasMany(CustomerPayment::class);
    }

    /** The invoices still owing, oldest first: the order a payment is applied in. */
    public function outstandingSales()
    {
        return $this->sales()->outstanding()->orderBy('created_at')->orderBy('id');
    }

    /**
     * What this customer owes, across every unpaid invoice.
     *
     * Uses the value `withOutstanding()` already fetched when it is there, so
     * rendering a list of customers does not fire a query per row.
     */
    public function getOutstandingAttribute(): float
    {
        if (array_key_exists('outstanding_total', $this->attributes)) {
            return round((float) $this->attributes['outstanding_total'], 2);
        }

        return round((float) $this->sales()->outstanding()->sum('balance_due'), 2);
    }

    /** The part of the balance that is already past its due date. */
    public function getOverdueAttribute(): float
    {
        if (array_key_exists('overdue_total', $this->attributes)) {
            return round((float) $this->attributes['overdue_total'], 2);
        }

        return round((float) $this->sales()->outstanding()
            ->whereNotNull('due_date')->whereDate('due_date', '<', today())
            ->sum('balance_due'), 2);
    }

    /** How much more they may take on credit; null when no limit is set. */
    public function getCreditAvailableAttribute(): ?float
    {
        $limit = (float) $this->credit_limit;

        return $limit <= 0 ? null : round($limit - $this->outstanding, 2);
    }

    /** Already past their credit limit (a limit of 0 means there isn't one). */
    public function isOverLimit(): bool
    {
        $available = $this->credit_available;

        return $available !== null && $available < 0;
    }

    /** Has anything they owe gone past its due date? */
    public function isOverdue(): bool
    {
        return $this->overdue > 0;
    }

    /**
     * Deleting a customer would orphan their invoices and their ledger, so anyone
     * with any trading history is deactivated instead.
     */
    public function isDeletable(): bool
    {
        return $this->sales()->doesntExist() && $this->payments()->doesntExist();
    }

    /**
     * Add each customer's balance, and the overdue part of it, as subquery
     * columns: two extra columns on one query instead of two queries per row.
     */
    public function scopeWithOutstanding($query)
    {
        $owed = fn() => Sale::query()
            ->selectRaw('COALESCE(SUM(balance_due), 0)')
            ->whereColumn('sales.customer_id', $this->getTable() . '.id')
            ->outstanding();

        return $query->select($query->getQuery()->columns ?: [$this->getTable() . '.*'])
            ->selectSub($owed(), 'outstanding_total')
            ->selectSub(
                $owed()->whereNotNull('due_date')->whereDate('due_date', '<', today()),
                'overdue_total'
            );
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Only customers who currently owe something. */
    public function scopeWithBalance($query)
    {
        return $query->whereHas('sales', fn($q) => $q->outstanding());
    }

    /** Only customers with an unpaid invoice that is past its due date. */
    public function scopeOverdue($query)
    {
        return $query->whereHas('sales', fn($q) => $q->outstanding()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today()));
    }
}
