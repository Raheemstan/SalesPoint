<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One meal: opened against a table, added to through the service, settled at the end.
 *
 * This is a sale that has not happened yet. It carries no money of its own - no
 * totals, no tax, no payment - because none of that is decided until the guests
 * ask for the bill. The running total is computed on demand from the lines by
 * App\Services\TableOrderService (which uses SaleCalculator, like the till), and
 * the real money is only ever recorded on the `sale` this order becomes.
 */
class TableOrder extends Model
{
    /** @use HasFactory<\Database\Factories\TableOrderFactory> */
    use BelongsToBranch, HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_CANCELLED = 'cancelled';

    /** The only values `status` may hold; used by validation and filters. */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_SETTLED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'reference',
        'table_id',
        'user_id',
        'customer_id',
        'status',
        'guests',
        'note',
        'opened_at',
        'settled_at',
        'sale_id',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    /**
     * The table the guests are sitting at.
     *
     * The name shadows Eloquent's own protected `$table` property, which holds a
     * model's database table name. That is safe - `getTable()` reads the
     * protected property from inside the class and falls back to the snake-cased
     * class name, while `$order->table` from outside is inaccessible and so
     * resolves through __get() to this relation - but it does confuse static
     * analysers, and it is the reason never to write `$this->table` inside this
     * class meaning the relation.
     */
    public function table()
    {
        return $this->belongsTo(Table::class);
    }

    /** The server who opened it. Not an owner: see TableOrderPolicy. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /** The sale it became, once it was paid for. */
    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function items()
    {
        return $this->hasMany(TableOrderItem::class);
    }

    /** The lines the kitchen has not seen yet, oldest first. */
    public function pendingItems()
    {
        return $this->items()->whereNull('sent_to_kitchen_at')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_SETTLED;
    }

    /** How long the guests have been sitting there, as "1h 20m". */
    public function seatedFor(): string
    {
        // Cast: Carbon 3 returns a float from diffInMinutes, and intdiv() will not take one.
        $minutes = max(0, (int) $this->opened_at->diffInMinutes(now()));
        $hours = intdiv($minutes, 60);

        return $hours > 0 ? $hours . 'h ' . ($minutes % 60) . 'm' : $minutes . 'm';
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
