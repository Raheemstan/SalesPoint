<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Scopes\BranchScope;
use App\Services\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes a model belong to one branch, and keeps it there.
 *
 * Adding this trait does three things and asks for nothing else:
 *
 *   1. every read is filtered to the acting branch (App\Scopes\BranchScope);
 *   2. every new row is stamped with the acting branch;
 *   3. `->branch` and the escape hatches below become available.
 *
 * That is the whole point of the design. A controller or service that queries a
 * branch-aware model needs no change at all, because the filtering is a property
 * of the model rather than of the query - which is also why it cannot be
 * forgotten.
 *
 * The stamp happens whether or not `feature('branches')` is on. A single-site
 * shop therefore files everything under its one branch as it goes, and the day
 * it opens a second shop its whole history is already in the right place
 * instead of needing a data migration.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope());

        static::creating(function (Model $model) {
            $column = $model->getBranchColumn();

            /*
             * Only when the caller has not said. An explicit branch_id is how
             * a transfer receipt records a movement at the far end, and how a
             * test sets a scene up, so it must win over the ambient one.
             */
            if ($model->getAttribute($column) === null) {
                $model->setAttribute($column, app(BranchContext::class)->id());
            }
        });
    }

    /** Which column carries the branch. Overridable, but nothing needs to. */
    public function getBranchColumn(): string
    {
        return 'branch_id';
    }

    /**
     * Make `branch_id` mass-assignable wherever this trait is used.
     *
     * Here rather than in a dozen `$fillable` arrays, for the same reason the
     * scope is here rather than in a dozen controllers: one place to state it is
     * one place to get it right. (Laravel calls initialize<TraitName>() from the
     * model constructor, which is what makes this possible.)
     *
     * It needs to be assignable because an explicit branch sometimes has to win
     * over the ambient one - a transfer recording a movement at the far end, a
     * test setting a scene up at another shop.
     */
    public function initializeBelongsToBranch(): void
    {
        $column = $this->getBranchColumn();

        if (!in_array($column, $this->fillable, true)) {
            $this->fillable[] = $column;
        }
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Escaping the scope
    |--------------------------------------------------------------------------
    |
    | Every use of either method below is a deliberate decision to read more
    | than one branch, and every one of them is commented at the call site.
    | `grep -rn withoutBranchScope app/` is the audit trail.
    */

    /**
     * See every branch's rows.
     *
     * For the handful of screens that are honestly group-wide: an owner's
     * consolidated report, an admin list of everything, the transfer screen
     * which has to read both ends at once.
     */
    public function scopeWithoutBranchScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(BranchScope::class);
    }

    /**
     * See one *named* branch's rows, whichever branch we are acting as.
     *
     * Safer than withoutBranchScope() wherever the answer is "that branch"
     * rather than "all branches" - a per-branch report, the far end of a
     * transfer - because it is still filtered, just to a different branch.
     */
    public function scopeForBranch(Builder $query, Branch|int|null $branch): Builder
    {
        $id = $branch instanceof Branch ? $branch->getKey() : $branch;

        if ($id === null) {
            return $query;
        }

        return $query->withoutGlobalScope(BranchScope::class)
            ->where($this->qualifyColumn($this->getBranchColumn()), $id);
    }
}
