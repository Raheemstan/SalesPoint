<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Services\BranchContext;
use App\Services\StockTransferService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;

/**
 * Stock moving between shops.
 *
 * The screens; the rules are all in App\Services\StockTransferService, which is
 * where the one thing that matters lives - stock in transit is counted in
 * neither place, so it leaves the source when it is sent and only arrives at the
 * destination when somebody there confirms it.
 *
 * You send from where you are standing and receive where you are standing. The
 * service refuses anything else and says which branch to switch to, which is why
 * these actions can pass a request straight through without re-checking it.
 */
class StockTransferController extends Controller implements HasMiddleware
{
    /** Transfers between shops make no sense for a shop with one. */
    public static function middleware(): array
    {
        return [
            function ($request, $next) {
                abort_unless(feature('branches'), 404);

                return $next($request);
            },
        ];
    }

    public function __construct(
        private BranchContext $branches,
        private StockTransferService $transfers,
    ) {
    }

    /**
     * What is coming here, and what we have sent.
     *
     * Narrowed to the acting branch by default, because a transfer is only any
     * business of its two ends. An admin can ask for the group-wide list with
     * `?scope=all` - this model is not globally scoped (a transfer has two
     * branches, so a single-column scope would hide half of them), so that is a
     * matter of not applying visibleTo() rather than of escaping anything.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', StockTransfer::class);

        $request->validate([
            'status' => 'nullable|in:' . implode(',', StockTransfer::STATUSES),
            'scope' => 'nullable|in:branch,all',
        ]);

        $groupWide = $request->input('scope') === 'all' && $request->user()->hasRole('admin');
        $active = $this->branches->current();

        $transfers = StockTransfer::query()
            ->with(['fromBranch', 'toBranch', 'creator', 'receiver'])
            ->withCount('items')
            ->withSum('items', 'quantity')
            ->unless($groupWide, fn ($query) => $query->visibleTo($active))
            ->status($request->input('status'))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('transfers.index', [
            'transfers' => $transfers,
            'active' => $active,
            'groupWide' => $groupWide,
            // The number that needs somebody to act: goods sitting on a van.
            'awaiting' => StockTransfer::query()->incomingTo($active)->count(),
            'canCreate' => Gate::allows('create', StockTransfer::class),
        ]);
    }

    /**
     * The send form: what this branch has, and where it could go.
     */
    public function create(Request $request)
    {
        Gate::authorize('create', StockTransfer::class);

        $request->validate(['search' => 'nullable|string|max:120']);

        $from = $this->branches->current();

        if (!$from) {
            return redirect()->route('branches.index')
                ->with('error', 'Switch to a branch before sending stock from it.');
        }

        $search = trim((string) $request->input('search', '')) ?: null;

        return view('transfers.create', [
            'from' => $from,
            // Every other open shop. Group-wide by definition: the whole point
            // of the screen is the branch you are not standing in.
            'destinations' => Branch::query()->active()->ordered()
                ->whereKeyNot($from->getKey())->get(),
            'products' => $this->transfers->sendable($from, $search),
            'search' => $search,
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', StockTransfer::class);

        $data = $request->validate([
            'to_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'quantities' => ['required', 'array'],
            // A blank box is how a form says "not this one", so nullable rather
            // than required - the service refuses a transfer with nothing on it.
            'quantities.*' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $from = $this->branches->current();

        if (!$from) {
            return back()->withInput()->with('error', 'Switch to a branch before sending stock from it.');
        }

        try {
            $transfer = $this->transfers->dispatch(
                $from,
                Branch::findOrFail($data['to_branch_id']),
                $data['quantities'],
                $request->user(),
                $data['note'] ?? null
            );
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('transfers.show', $transfer)->with(
            'success',
            "Transfer {$transfer->reference} sent. The stock has left {$from->name} and arrives when "
            . $transfer->toBranch->name . ' confirms it.'
        );
    }

    public function show(StockTransfer $transfer)
    {
        Gate::authorize('view', $transfer);

        $transfer->load(['items.product.category', 'fromBranch', 'toBranch', 'creator', 'receiver', 'canceller']);

        $active = $this->branches->current();

        return view('transfers.show', [
            'transfer' => $transfer,
            'active' => $active,
            /*
             * What the destination holds of each line right now, so a receiver
             * can see what they are adding to. Named branch, read deliberately:
             * `branch_stock` is branch-scoped and this is the far end.
             */
            'destinationStock' => Product::query()
                ->withBranchStock($transfer->toBranch)
                ->whereIn('id', $transfer->items->pluck('product_id'))
                ->pluck('branch_stock_quantity', 'id'),
            // Both halves of "may I act on this": the role, and standing in the
            // right shop. The service enforces the second; the button should not
            // be offered when it would be refused.
            'canReceive' => Gate::allows('receive', $transfer)
                && $active?->getKey() === (int) $transfer->to_branch_id,
            'canCancel' => Gate::allows('cancel', $transfer)
                && $active?->getKey() === (int) $transfer->from_branch_id,
        ]);
    }

    /**
     * Confirm the goods arrived. Only at the destination, and only once.
     */
    public function receive(Request $request, StockTransfer $transfer)
    {
        Gate::authorize('receive', $transfer);

        try {
            $this->transfers->receive($transfer, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('transfers.show', $transfer)->with(
            'success',
            "Transfer {$transfer->reference} received. The stock is on the shelf."
        );
    }

    /**
     * Recall a transfer that has not landed. The sender's call.
     */
    public function cancel(Request $request, StockTransfer $transfer)
    {
        Gate::authorize('cancel', $transfer);

        $data = $request->validate(['reason' => 'nullable|string|max:255']);

        try {
            $this->transfers->cancel($transfer, $request->user(), $data['reason'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('transfers.show', $transfer)->with(
            'success',
            "Transfer {$transfer->reference} cancelled and the stock put back."
        );
    }
}
