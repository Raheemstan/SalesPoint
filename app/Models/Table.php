<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A table (or a booth, or a room) that guests are served at.
 *
 * Deliberately thin: a table is a label and a position on the floor. Everything
 * interesting - who is sitting there, what they have ordered, what they owe -
 * belongs to the open TableOrder, because a table outlives any number of meals.
 */
class Table extends Model
{
    /** @use HasFactory<\Database\Factories\TableFactory> */
    use BelongsToBranch, HasFactory;

    protected $fillable = [
        'name',
        'area',
        'seats',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function orders()
    {
        return $this->hasMany(TableOrder::class);
    }

    /**
     * The order currently running on this table, if any.
     *
     * A hasOne rather than a scope so the floor view can eager-load it for every
     * table in one query. Only one order may be open on a table at a time - that
     * is enforced in TableOrderService::open(), under a lock.
     */
    public function openOrder()
    {
        return $this->hasOne(TableOrder::class)->where('status', TableOrder::STATUS_OPEN);
    }

    public function isOccupied(): bool
    {
        return $this->openOrder !== null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** The order staff expect to see on the floor plan: by hand, then by name. */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
