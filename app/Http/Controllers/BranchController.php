<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\StockTransferService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The shops themselves, and standing in one of them.
 *
 * Two jobs that look alike and are not. Managing branches is an owner's
 * occasional administration (see App\Policies\BranchPolicy); switching between
 * them is something a member of staff may do several times a day and is the only
 * action on this controller a cashier can reach - which is why the switch is
 * guarded by what the *user* is allowed rather than by their role.
 *
 * All of it 404s for a single-site shop. Nothing here is reachable, and no
 * branch interface appears anywhere, until `feature('branches')` is on.
 */
class BranchController extends Controller implements HasMiddleware
{
    /**
     * A shop that has one branch has no use for any of this, so none of it
     * exists. The same shape as table service uses for `feature('tables')`.
     */
    public static function middleware(): array
    {
        return [
            function ($request, $next) {
                abort_unless(feature('branches'), 404);

                return $next($request);
            },
        ];
    }

    public function __construct(private BranchContext $branches)
    {
    }

    /**
     * Every shop, with the figures that say whether it is working.
     *
     * Group-wide by definition - it is a list of branches - so the per-branch
     * sums are built as subqueries rather than by switching branch and asking
     * twelve times.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Branch::class);

        $branches = Branch::query()
            ->ordered()
            ->withCount('users')
            // One subquery per figure, so the page costs the same whether the
            // group has two shops or twenty.
            ->addSelect(['stock_units' => DB::table('branch_stock')
                ->selectRaw('COALESCE(SUM(stock_quantity), 0)')
                ->whereColumn('branch_stock.branch_id', 'branches.id')])
            ->addSelect(['stock_value' => DB::table('branch_stock')
                ->join('products', 'products.id', '=', 'branch_stock.product_id')
                ->selectRaw('COALESCE(SUM(branch_stock.stock_quantity * products.cost_price), 0)')
                ->whereColumn('branch_stock.branch_id', 'branches.id')])
            ->addSelect(['takings' => DB::table('sales')
                ->selectRaw('COALESCE(SUM(grand_total), 0)')
                ->whereColumn('sales.branch_id', 'branches.id')
                ->where('sales.status', '!=', Sale::STATUS_VOIDED)])
            ->get();

        return view('branches.index', [
            'branches' => $branches,
            'active' => $this->branches->current(),
            'canManage' => Gate::allows('create', Branch::class),
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Branch::class);

        $data = $this->validated($request);

        $branch = Branch::create($data + ['is_default' => false]);

        /*
         * Whoever opens a shop can work in it. Without this an admin would open
         * a branch and immediately be unable to switch to it, which looks like
         * the feature is broken rather than like a permission.
         */
        $branch->staff()->syncWithoutDetaching([$request->user()->getKey()]);

        AuditLog::record("Opened branch {$branch->label()}", 'branches', (int) $branch->getKey());

        return redirect()->route('branches.index')
            ->with('success', "{$branch->name} is open. Assign staff to it, then send it some stock.");
    }

    /**
     * One shop: what is on its shelves, who works there, what is on its way.
     */
    public function show(Branch $branch, StockTransferService $transfers)
    {
        Gate::authorize('view', $branch);

        $stock = Product::query()
            ->stocked()
            // This branch's shelf, named explicitly - the page is about a branch
            // that is very often not the one being acted as.
            ->withBranchStock($branch)
            ->stockedAt($branch)
            ->with('category')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('branches.show', [
            'branch' => $branch,
            'stock' => $stock,
            'staff' => User::query()
                ->where('branch_id', $branch->getKey())
                ->orWhereHas('branches', fn ($q) => $q->whereKey($branch->getKey()))
                ->orderBy('name')
                ->get(),
            'incoming' => $transfers->inTransit($branch, true)->sum(),
            'outgoing' => $transfers->inTransit($branch, false)->sum(),
            // Deliberately group-wide: these are branch-scoped models and this
            // page asks about a branch that may not be the acting one.
            'sales' => Sale::query()->forBranch($branch)->where('status', '!=', Sale::STATUS_VOIDED)->count(),
            'takings' => round((float) Sale::query()->forBranch($branch)
                ->where('status', '!=', Sale::STATUS_VOIDED)->sum('grand_total'), 2),
            'canManage' => Gate::allows('update', $branch),
        ]);
    }

    public function update(Request $request, Branch $branch)
    {
        Gate::authorize('update', $branch);

        $data = $this->validated($request, $branch);

        /*
         * The default branch must stay open. It is where new rows go when
         * nothing else answers, and a closed fallback would mean stock
         * movements with nowhere to land.
         */
        if ($branch->is_default && !($data['is_active'] ?? true)) {
            return back()->withInput()->with('error', 'The default branch cannot be closed. Make another branch the default first.');
        }

        $branch->update($data);

        // One statement, inside a transaction, because two defaults quietly
        // sends new data to the wrong shop. See Branch::makeDefault().
        if ($request->boolean('is_default') && !$branch->is_default) {
            $branch->makeDefault();
        }

        AuditLog::record("Updated branch {$branch->label()}", 'branches', (int) $branch->getKey());

        return redirect()->route('branches.show', $branch)->with('success', "{$branch->name} updated.");
    }

    /**
     * Close a shop. Never delete one.
     *
     * A branch id is stamped on every sale it took, every drawer it counted and
     * every stock movement it made. Deleting the row would null all of that out
     * and the history would lose its place - so "delete" means deactivate, the
     * screen says so, and the data stays.
     */
    public function destroy(Branch $branch)
    {
        Gate::authorize('delete', $branch);

        $branch->forceFill(['is_active' => false])->save();

        AuditLog::record("Closed branch {$branch->label()}", 'branches', (int) $branch->getKey());

        return redirect()->route('branches.index')->with(
            'success',
            "{$branch->name} is closed. Its history is kept, and it can be reopened at any time."
        );
    }

    /**
     * Stand in a different shop.
     *
     * Reachable by any signed-in user, because a relief cashier needs it as much
     * as an owner does - and authorised per *branch* rather than per role, so a
     * cashier can only reach the shops they are assigned to however they post
     * this form. BranchContext::switchTo() checks the same thing again, which
     * means no other caller can get it wrong either.
     */
    public function switch(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $branch = Branch::findOrFail($data['branch_id']);

        Gate::authorize('switchTo', $branch);

        $this->branches->switchTo($branch);

        /*
         * The cart, the held-cart count and any open table order all belong to
         * the shop they were started in: the goods are on that floor. Carrying a
         * cart across a branch switch would price items from a shelf the cashier
         * is no longer standing at.
         */
        $request->session()->forget(['cart', 'cart_discount', 'cart_customer', 'cart_service_charge']);

        return back()->with('success', "You are now at {$branch->name}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Branch $branch = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Short, unique, and shouted down a phone - so it is normalised to
            // upper case rather than letting "ikj" and "IKJ" both exist.
            'code' => [
                'required', 'string', 'max:12', 'alpha_dash',
                Rule::unique('branches', 'code')->ignore($branch?->getKey()),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['code'] = strtoupper($data['code']);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
