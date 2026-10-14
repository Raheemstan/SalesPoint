<?php

namespace App\Scopes;

use App\Services\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filter every branch-aware model to the branch we are acting as.
 *
 * WHY A GLOBAL SCOPE AND NOT `where('branch_id', ...)` IN THE CONTROLLERS
 *
 * Because that approach always misses a query. There are several hundred queries
 * in this app and a missed one in a point of sale is not a cosmetic bug: it is
 * one shop selling another shop's stock, or a cashier reading another branch's
 * takings. A global scope is the only version of this that is *closed* - a new
 * query on a branch-aware model is filtered because it exists, not because
 * somebody remembered.
 *
 * The cost of that is the escape hatch (see BranchContext's "Escaping the scope"
 * section), which is deliberately noisy and greppable.
 *
 * IT FILTERS STRICTLY, INCLUDING OUT NULLS
 *
 * A row with no `branch_id` is invisible to every branch. That is safe because
 * nothing can create one: the migration backfills every existing row and
 * BelongsToBranch stamps every new one, whether or not the feature is on. The
 * alternative - treating null as "visible everywhere" - would make a single
 * forgotten stamp leak a sale into every branch's reports, which is the failure
 * mode that is hardest to notice and worst to explain.
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $branches = app(BranchContext::class);

        /*
         * Inert for a single-site shop. This one line is why 800-odd existing
         * tests, and every existing install, behave exactly as they did before
         * this feature was written.
         */
        if (!$branches->scoping()) {
            return;
        }

        // Qualified: these models are joined in places, and an unqualified
        // `branch_id` is ambiguous the moment two scoped tables meet.
        $builder->where(
            $model->qualifyColumn($model->getBranchColumn()),
            $branches->id()
        );
    }
}
