<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Table;
use App\Models\TableOrder;
use App\Services\TableOrderService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The tables a restaurant serves at, and the floor view built on them.
 *
 * `index` is the home screen of a restaurant install: it answers, at a glance,
 * which tables are free, how long the occupied ones have been sitting, what each
 * one owes and whose section it is in. Everything else here is the manager-only
 * job of keeping the list of tables itself up to date.
 */
class TableController extends Controller implements HasMiddleware
{
    /**
     * One gate for the whole feature.
     *
     * A pharmacy or a hardware shop has no tables, so these screens must not
     * merely come up empty - they must not exist. Declared here rather than
     * repeated in every action so there is no way to add a method that forgets
     * it. (`$this->middleware()` in a constructor is gone in Laravel 11+; this
     * contract is its replacement.)
     *
     * feature() reads a per-install override before the sector preset, so a bar
     * set up as "retail" can still switch tables on in Settings.
     */
    public static function middleware(): array
    {
        return [
            function ($request, $next) {
                abort_unless(feature('tables'), 404);

                return $next($request);
            },
        ];
    }

    public function __construct(private TableOrderService $orders)
    {
    }

    /**
     * The floor view: every table as a card.
     *
     * The running total of each open order goes through the same calculator the
     * till uses, so the figure on the card is the figure the guests will be
     * asked for.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Table::class);

        // Eager-loaded three deep: without this the floor view runs a query per
        // table per line, and a 30-table restaurant feels broken on a tablet.
        $tables = Table::with(['openOrder.user', 'openOrder.items'])->ordered()->get();

        $totals = $tables
            ->filter(fn (Table $table) => $table->openOrder !== null)
            ->mapWithKeys(fn (Table $table) => [
                $table->id => $this->orders->totals($table->openOrder)['grand_total'],
            ]);

        $summary = [
            'tables' => $tables->count(),
            'occupied' => $totals->count(),
            'guests' => $tables->sum(fn (Table $table) => (int) ($table->openOrder->guests ?? 0)),
            'open_total' => round((float) $totals->sum(), 2),
        ];

        $manager = $request->user()->hasAnyRole(['admin', 'manager']);
        $areas = $tables->pluck('area')->filter()->unique()->sort()->values();

        return view('tables.index', compact('tables', 'totals', 'summary', 'manager', 'areas'));
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Table::class);

        $data = $this->validated($request);

        $table = Table::create($data);

        AuditLog::record("Added table {$table->name}", 'tables', $table->id);

        return redirect()->route('tables.index')->with('success', "Table {$table->name} added.");
    }

    public function update(Request $request, Table $table)
    {
        Gate::authorize('update', $table);

        $data = $this->validated($request, $table);

        /*
         * Taking a table out of service while guests are sitting at it would
         * leave an open order on a table the floor view treats as unusable, and
         * nobody could seat or transfer it. Settle or cancel first.
         */
        if (!($data['is_active'] ?? false) && $table->is_active && $this->hasOpenOrder($table)) {
            return back()->with('error', "{$table->name} has an open order. Settle it before taking the table out of service.");
        }

        $table->update($data);

        AuditLog::record("Updated table {$table->name}", 'tables', $table->id);

        return redirect()->route('tables.index')->with('success', "Table {$table->name} updated.");
    }

    /**
     * Remove a table.
     *
     * Refused outright while an order is open on it, and refused for a table with
     * any history at all: the orders point at it, and a restaurant that loses the
     * record of what table 7 sold last month has lost real information.
     * Deactivating is the answer in both cases, and the message says so.
     */
    public function destroy(Table $table)
    {
        Gate::authorize('delete', $table);

        if ($this->hasOpenOrder($table)) {
            return back()->with('error', "{$table->name} has an open order. Settle or cancel it first.");
        }

        if ($table->orders()->exists()) {
            return back()->with(
                'error',
                "{$table->name} has past orders, so it cannot be deleted. Take it out of service instead."
            );
        }

        $name = $table->name;
        $table->delete();

        AuditLog::record("Deleted table {$name}", 'tables', 0);

        return redirect()->route('tables.index')->with('success', "Table {$name} deleted.");
    }

    /**
     * Shared rules for store and update.
     *
     * `name` is unique and is what staff shout across a room, so the uniqueness
     * check has to ignore the row being edited or renaming "T1" to "T1" fails.
     */
    private function validated(Request $request, ?Table $table = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('tables', 'name')->ignore($table?->id)],
            'area' => ['nullable', 'string', 'max:50'],
            'seats' => ['required', 'integer', 'min:1', 'max:99'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // An unticked checkbox is simply absent from the request, so the default
        // has to be decided here rather than left to the column default.
        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    private function hasOpenOrder(Table $table): bool
    {
        return TableOrder::open()->where('table_id', $table->id)->exists();
    }
}
