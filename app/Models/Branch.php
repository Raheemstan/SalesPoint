<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One shop.
 *
 * A branch is a *place*, not a separate business: it shares the catalogue, the
 * customers, the suppliers and the settings with every other branch, and owns
 * only what is physically there - its stock, its tills, its drawers, its sales.
 * See the 2026_10_14_000012 migration for why that split was chosen.
 */
class Branch extends Model
{
    /** @use HasFactory<\Database\Factories\BranchFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'address',
        'phone',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    |
    | Deliberately few. Every branch-aware model has a `branch()` relation
    | pointing here (App\Models\Concerns\BelongsToBranch), and the reverse
    | direction is almost never what a screen wants: "every sale at this branch"
    | is what the global scope already gives you by simply being here.
    */

    /** People whose home branch this is. */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /** People who may also work here, on top of those whose home it is. */
    public function staff()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** What is on this branch's shelves, product by product. */
    public function stock()
    {
        return $this->hasMany(BranchStock::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** The order the switcher and every list shows branches in. */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /*
    |--------------------------------------------------------------------------
    | The default branch
    |--------------------------------------------------------------------------
    |
    | More than a UI convenience. This is the branch the whole app acts as while
    | `feature('branches')` is off, and the home branch of anyone who has none,
    | so *something* must always answer. The migration creates exactly one.
    */

    /**
     * The fallback branch: the one flagged default, else the oldest.
     *
     * Falling back to the oldest rather than to null matters: a shop that
     * deleted its default row would otherwise have every new sale, movement and
     * drawer stamped with no branch at all, which is the one state the rest of
     * this feature cannot recover from.
     */
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->orderBy('id')->first();
    }

    /**
     * Make this the default, and the only one.
     *
     * `is_default` is a flag on many rows standing in for a single-row fact, so
     * nothing may set it without clearing the others. Done in one statement
     * inside a transaction, because two defaults is a state that silently sends
     * new data to the wrong shop.
     */
    public function makeDefault(): void
    {
        DB::transaction(function () {
            static::query()->whereKeyNot($this->getKey())->update(['is_default' => false]);

            $this->forceFill(['is_default' => true, 'is_active' => true])->save();
        });
    }

    /** "IKJ - Ikeja", the form staff recognise in a switcher. */
    public function label(): string
    {
        return $this->code . ' - ' . $this->name;
    }
}
