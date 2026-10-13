<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing ordered: "2 x Jollof rice, no pepper, main course".
 *
 * `price` is a snapshot taken when the line was added, never read back from the
 * product. That is the whole point of the column: the guests ordered from the
 * menu that was on the table, and a re-price at 8pm cannot reach back and change
 * what the people who sat down at 7pm agreed to pay.
 */
class TableOrderItem extends Model
{
    /** @use HasFactory<\Database\Factories\TableOrderItemFactory> */
    use HasFactory;

    /**
     * Courses the kitchen can fire in order. Whitelisted rather than free text so
     * the prep ticket can group by them and the kitchen always reads the same
     * five words.
     */
    public const COURSES = [
        'drinks',
        'starter',
        'main',
        'dessert',
    ];

    protected $fillable = [
        'table_order_id',
        'product_id',
        'quantity',
        'price',
        'note',
        'course',
        'sent_to_kitchen_at',
    ];

    protected $casts = [
        'sent_to_kitchen_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(TableOrder::class, 'table_order_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Has the kitchen been told about this line?
     *
     * The one question that decides whether a line can still be changed: once it
     * is true the food has been cooked, and the records have to show what
     * happened to it rather than quietly losing it.
     */
    public function isSent(): bool
    {
        return $this->sent_to_kitchen_at !== null;
    }

    /** What this line adds to the bill before any discount or tax. */
    public function getGrossAttribute(): float
    {
        return round((float) $this->price * (int) $this->quantity, 2);
    }
}
