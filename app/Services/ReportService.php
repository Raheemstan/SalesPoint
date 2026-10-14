<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Supplier;
use App\Services\BranchContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The figures behind the dashboard and the reports.
 *
 * It exists so the controller is routing and presentation only, and so the
 * conventions below are written down once instead of being re-derived (slightly
 * differently) in every report:
 *
 *   - **Takings** is money through the till: `grand_total`, tax included, less
 *     refunds paid out in the same period. It is what the drawer and the bank
 *     see, so it is what a shopkeeper means by "what did we take today".
 *
 *   - **Revenue** excludes tax, because tax collected belongs to the tax
 *     authority and was never the shop's to earn. It is also net of refunds.
 *     Revenue, not takings, is what profit is measured against.
 *
 *   - **Cost of goods sold** comes from `sale_items.cost_price`, the cost
 *     snapshotted when the sale was rung up. Editing a product's cost today must
 *     not move last month's profit.
 *
 *   - A **voided** sale is not a transaction, so it is left out of counts. Its
 *     money is *not* filtered out of the sums, though: a void writes a refund
 *     for the whole sale, so subtracting refunds already cancels it exactly.
 *     Filtering it out as well would subtract it twice. (This is the convention
 *     ReportController::profitLoss() has always used.)
 *
 * Every method here aggregates in SQL and fills any gaps in PHP. Nothing runs a
 * query per day or per row: a report over a year must cost the same number of
 * queries as a report over a week.
 */
class ReportService
{
    /**
     * Inlined into SQL rather than bound, because conditional aggregation
     * (SUM(CASE WHEN ...)) puts its bindings in the select clause, where they are
     * easy to get out of order. It is a class constant, never user input.
     */
    private const NOT_VOIDED = "status <> '" . Sale::STATUS_VOIDED . "'";

    /** Ageing buckets, in days overdue. The last one is open-ended. */
    public const AGEING = [
        'current' => 'Not yet due',
        '1_30' => '1 - 30 days',
        '31_60' => '31 - 60 days',
        'over_60' => 'Over 60 days',
    ];

    /*
    |--------------------------------------------------------------------------
    | Sales, takings and profit
    |--------------------------------------------------------------------------
    */

    /**
     * The headline figures for a period, in two queries.
     *
     * @param  ?int  $userId  one cashier's own figures (their sales, and the
     *                        refunds given back on those sales)
     * @return array{takings: float, gross: float, refunds: float, transactions: int,
     *               tax: float, discounts: float, subtotal: float, basket: float}
     */
    public function takings(Carbon $start, Carbon $end, ?int $userId = null): array
    {
        $sales = $this->branch(DB::table('sales'))
            ->whereBetween('created_at', [$start, $end])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->selectRaw('COALESCE(SUM(grand_total), 0) as gross')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as subtotal')
            ->selectRaw('COALESCE(SUM(tax_amount), 0) as tax')
            ->selectRaw('COALESCE(SUM(discount_amount), 0) as discounts')
            ->selectRaw('SUM(CASE WHEN ' . self::NOT_VOIDED . ' THEN 1 ELSE 0 END) as transactions')
            ->first();

        $refunds = (float) $this->refundQuery($userId)
            ->whereBetween('refunds.created_at', [$start, $end])
            ->sum('refunds.total_amount');

        $gross = round((float) $sales->gross, 2);
        $takings = round($gross - $refunds, 2);
        $transactions = (int) $sales->transactions;

        return [
            'gross' => $gross,
            'refunds' => round($refunds, 2),
            'takings' => $takings,
            'transactions' => $transactions,
            'tax' => round((float) $sales->tax, 2),
            'discounts' => round((float) $sales->discounts, 2),
            'subtotal' => round((float) $sales->subtotal, 2),
            // A shop's first day has no sales at all, so this must never divide by zero.
            'basket' => $transactions > 0 ? round($takings / $transactions, 2) : 0.0,
        ];
    }

    /**
     * Revenue, cost of goods sold and gross profit for a period, in four queries.
     *
     * Deliberately the same arithmetic as profitLoss() does per day, so the
     * dashboard tile and the P&L report can never disagree.
     *
     * @return array{revenue: float, cogs: float, gross_profit: float, margin: float}
     */
    public function profit(Carbon $start, Carbon $end): array
    {
        $revenue = (float) $this->branch(DB::table('sales'))
            ->whereBetween('created_at', [$start, $end])
            ->sum(DB::raw('grand_total - tax_amount'));

        $refunded = (float) $this->branch(DB::table('refunds'), 'refunds')
            ->whereBetween('created_at', [$start, $end])
            ->sum(DB::raw('total_amount - tax_amount'));

        $cogs = (float) $this->branch(DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id'))
            ->whereBetween('sales.created_at', [$start, $end])
            ->sum(DB::raw('sale_items.quantity * sale_items.cost_price'));

        // Goods back on the shelf are no longer a cost; written-off returns stay one.
        $returned = (float) $this->branch(DB::table('refund_items')
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id'), 'refunds')
            ->where('refund_items.restocked', true)
            ->whereBetween('refunds.created_at', [$start, $end])
            ->sum(DB::raw('refund_items.quantity * refund_items.cost_price'));

        return $this->margin(round($revenue - $refunded, 2), round($cogs - $returned, 2));
    }

    /**
     * Takings per day across a period, with the empty days present as zero.
     *
     * The gaps have to be filled or a chart would draw a straight line across a
     * closed Sunday as though the shop had traded through it.
     *
     * @return array{labels: array<int, string>, values: array<int, float>}
     */
    public function takingsByDay(Carbon $start, Carbon $end, ?int $userId = null): array
    {
        $sales = $this->perDay(
            $this->branch(DB::table('sales'))->when($userId, fn ($q) => $q->where('user_id', $userId)),
            'sales.created_at',
            'sales.grand_total',
            $start,
            $end
        );

        $refunds = $this->perDay(
            $this->refundQuery($userId),
            'refunds.created_at',
            'refunds.total_amount',
            $start,
            $end
        );

        $labels = [];
        $values = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();

            $labels[] = $date;
            $values[] = round((float) ($sales[$date] ?? 0) - (float) ($refunds[$date] ?? 0), 2);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Money taken per tender for a period.
     *
     * Prefers per-tender rows from `sale_payments` (split payments) and falls
     * back to the single `sales.payment_method` column for sales that have none,
     * exactly as ShiftService::salesByMethod() does - the table is being added by
     * another job, so everything that reads it has to be guarded.
     *
     * `taken` is what came in, which is what the doughnut divides up; `refunds`
     * and `net` are carried alongside for the table, because a net figure can be
     * negative on a heavy refund day and a negative slice of a pie is nonsense.
     *
     * @return array<string, array{label: string, taken: float, refunds: float, net: float}>
     */
    public function takenByMethod(Carbon $start, Carbon $end): array
    {
        $taken = array_fill_keys(Shift::METHODS, 0.0);
        $given = array_fill_keys(Shift::METHODS, 0.0);

        $tendered = $this->hasTenderRows();

        if ($tendered) {
            $this->addMethodRows($taken, $this->branch(DB::table('sale_payments')
                ->join('sales', 'sales.id', '=', 'sale_payments.sale_id'))
                ->whereBetween('sales.created_at', [$start, $end])
                ->where('sales.status', '!=', Sale::STATUS_VOIDED)
                ->groupBy('sale_payments.method')
                ->selectRaw('sale_payments.method as method, SUM(sale_payments.amount) as total')
                ->get());
        }

        /*
         * What the till actually took: handed over, less change given back. On a
         * credit sale that is only the deposit, because the rest never arrived.
         */
        $fallback = $this->branch(DB::table('sales'))
            ->whereBetween('sales.created_at', [$start, $end])
            ->where('sales.status', '!=', Sale::STATUS_VOIDED)
            ->groupBy('sales.payment_method')
            ->selectRaw('sales.payment_method as method, SUM(sales.paid_amount - COALESCE(sales.change_due, 0)) as total');

        if ($tendered) {
            $fallback->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('sale_payments')
                ->whereColumn('sale_payments.sale_id', 'sales.id'));
        }

        $this->addMethodRows($taken, $fallback->get());

        $this->addMethodRows($given, $this->branch(DB::table('refunds'), 'refunds')
            ->whereBetween('refunds.created_at', [$start, $end])
            ->groupBy('refunds.method')
            ->selectRaw('refunds.method as method, SUM(refunds.total_amount) as total')
            ->get());

        $out = [];

        foreach (Shift::METHODS as $method) {
            $out[$method] = [
                'label' => Shift::methodLabel($method),
                'taken' => $taken[$method],
                'refunds' => $given[$method],
                'net' => round($taken[$method] - $given[$method], 2),
            ];
        }

        return $out;
    }

    /*
    |--------------------------------------------------------------------------
    | Performance by product, category and cashier
    |--------------------------------------------------------------------------
    */

    /**
     * Quantity, revenue, cost and margin per product for a period.
     *
     * Three queries whatever the range: what was sold, what came back, and the
     * names. The two aggregates are joined in PHP over the union of their product
     * ids, so a refund of an older sale is still subtracted even though the sale
     * itself falls outside the range.
     *
     * @return Collection<int, array{product_id: int, name: string, sku: ?string,
     *          category: string, quantity: int, revenue: float, cogs: float,
     *          gross_profit: float, margin: float}>
     */
    public function productPerformance(Carbon $start, Carbon $end): Collection
    {
        $sold = $this->branch(DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id'))
            ->whereBetween('sales.created_at', [$start, $end])
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id as product_id')
            ->selectRaw('COALESCE(SUM(sale_items.quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(sale_items.paid_total - sale_items.tax_amount), 0) as revenue')
            ->selectRaw('COALESCE(SUM(sale_items.quantity * sale_items.cost_price), 0) as cogs')
            ->get()
            ->keyBy('product_id');

        $back = $this->branch(DB::table('refund_items')
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id'), 'refunds')
            ->whereBetween('refunds.created_at', [$start, $end])
            ->groupBy('refund_items.product_id')
            ->selectRaw('refund_items.product_id as product_id')
            ->selectRaw('COALESCE(SUM(refund_items.quantity), 0) as quantity')
            ->selectRaw('COALESCE(SUM(refund_items.amount - refund_items.tax_amount), 0) as revenue')
            // Only restocked goods stop being a cost - see profit().
            ->selectRaw('COALESCE(SUM(CASE WHEN refund_items.restocked = 1 THEN refund_items.quantity * refund_items.cost_price ELSE 0 END), 0) as cogs')
            ->get()
            ->keyBy('product_id');

        $names = $this->productNames($sold->keys()->merge($back->keys()));

        return $sold->keys()->merge($back->keys())->unique()->values()
            ->map(function ($id) use ($sold, $back, $names) {
                $out = $sold->get($id);
                $in = $back->get($id);
                $product = $names->get($id);

                $revenue = round((float) ($out->revenue ?? 0) - (float) ($in->revenue ?? 0), 2);
                $cogs = round((float) ($out->cogs ?? 0) - (float) ($in->cogs ?? 0), 2);

                return array_merge([
                    'product_id' => (int) $id,
                    // A product can be archived after it was sold; its history stays.
                    'name' => $product->name ?? 'Deleted ' . strtolower(term('product')),
                    'sku' => $product->sku ?? null,
                    'category' => $product->category ?? 'Uncategorised',
                    'quantity' => (int) ($out->quantity ?? 0) - (int) ($in->quantity ?? 0),
                ], $this->margin($revenue, $cogs));
            });
    }

    /**
     * The same figures rolled up to categories.
     *
     * Built from productPerformance() rather than from its own query: one source
     * of truth means the two reports can never show different totals.
     *
     * @return Collection<int, array{category: string, products: int, quantity: int,
     *          revenue: float, cogs: float, gross_profit: float, margin: float}>
     */
    public function categoryPerformance(Carbon $start, Carbon $end): Collection
    {
        return $this->productPerformance($start, $end)
            ->groupBy('category')
            ->map(fn (Collection $rows, string $category) => array_merge([
                'category' => $category,
                'products' => $rows->count(),
                'quantity' => (int) $rows->sum('quantity'),
            ], $this->margin(
                round((float) $rows->sum('revenue'), 2),
                round((float) $rows->sum('cogs'), 2)
            )))
            ->sortByDesc('revenue')
            ->values();
    }

    /**
     * Staff performance: what each person took, and what they gave away.
     *
     * The discount column is the point of this report as much as the takings one:
     * a cashier quietly discounting every sale shows up here and nowhere else.
     * Takings are net of refunds given back on that person's own sales, while
     * "refunds processed" counts the refunds *they* put through, which is a
     * different question (and a different kind of worry).
     *
     * Five queries, whatever the staff count.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function cashierPerformance(Carbon $start, Carbon $end): Collection
    {
        $sales = $this->branch(DB::table('sales'))
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('user_id')
            ->selectRaw('user_id')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as gross')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as subtotal')
            ->selectRaw('COALESCE(SUM(discount_amount), 0) as discounts')
            ->selectRaw('SUM(CASE WHEN ' . self::NOT_VOIDED . ' THEN 1 ELSE 0 END) as transactions')
            ->selectRaw('SUM(CASE WHEN ' . self::NOT_VOIDED . ' THEN 0 ELSE 1 END) as voids')
            ->get()
            ->keyBy('user_id');

        // Money handed back on their sales: subtracted from their takings.
        $againstTheirSales = $this->branch(DB::table('refunds')
            ->join('sales', 'sales.id', '=', 'refunds.sale_id'), 'refunds')
            ->whereBetween('refunds.created_at', [$start, $end])
            ->groupBy('sales.user_id')
            ->selectRaw('sales.user_id as user_id, COALESCE(SUM(refunds.total_amount), 0) as total')
            ->pluck('total', 'user_id');

        // Refunds they themselves put through, whoever made the original sale.
        $processed = $this->branch(DB::table('refunds'), 'refunds')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total')
            ->get()
            ->keyBy('user_id');

        $ids = $sales->keys()->merge($againstTheirSales->keys())->merge($processed->keys())->unique();

        $users = $ids->isEmpty()
            ? collect()
            : DB::table('users')->whereIn('id', $ids)->select('id', 'name', 'role')->get()->keyBy('id');

        return $ids->values()->map(function ($id) use ($sales, $againstTheirSales, $processed, $users) {
            $row = $sales->get($id);
            $refunded = round((float) ($againstTheirSales[$id] ?? 0), 2);
            $gross = round((float) ($row->gross ?? 0), 2);
            $takings = round($gross - $refunded, 2);
            $transactions = (int) ($row->transactions ?? 0);
            $subtotal = round((float) ($row->subtotal ?? 0), 2);
            $discounts = round((float) ($row->discounts ?? 0), 2);

            return [
                'user_id' => (int) $id,
                'name' => $users->get($id)->name ?? 'Deleted user',
                'role' => $users->get($id)->role ?? null,
                'gross' => $gross,
                'refunded' => $refunded,
                'takings' => $takings,
                'transactions' => $transactions,
                'voids' => (int) ($row->voids ?? 0),
                'basket' => $transactions > 0 ? round($takings / $transactions, 2) : 0.0,
                'discounts' => $discounts,
                // As a share of what the goods would have come to undiscounted.
                'discount_rate' => $subtotal > 0 ? round($discounts / $subtotal * 100, 2) : 0.0,
                'refunds_count' => (int) ($processed->get($id)->count ?? 0),
                'refunds_value' => round((float) ($processed->get($id)->total ?? 0), 2),
            ];
        })->sortByDesc('takings')->values();
    }

    /**
     * The best sellers for a period.
     *
     * @param  string  $by  'quantity' or 'gross_profit'
     * @return Collection<int, array<string, mixed>>
     */
    public function topProducts(Carbon $start, Carbon $end, string $by = 'quantity', int $limit = 5): Collection
    {
        return $this->productPerformance($start, $end)
            ->sortByDesc($by)
            ->take($limit)
            ->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Stock
    |--------------------------------------------------------------------------
    */

    /**
     * What the stock on the shelves is worth, per category and in total.
     *
     * Stocked items only: a service (a haircut, a delivery fee) has no stock, so
     * valuing it would invent an asset the shop does not own.
     *
     * "Potential profit" is what the stock would earn if every unit sold at its
     * current price. It is not money yet, and the view says so.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, float|int>}
     */
    public function valuation(): array
    {
        /*
         * Group-wide, and knowingly so. This reads `products.stock_quantity`,
         * which is the total across every branch by design (see
         * App\Models\BranchStock) - so a multi-branch shop gets the value of
         * everything it owns, which is the right answer for a balance sheet and
         * the wrong one for "what is on this shelf". The per-branch version is
         * on the branch page, built from `branch_stock`.
         */
        $rows = DB::table('products')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereNull('products.deleted_at')      // DB::table() does not know about soft deletes
            ->where('products.track_stock', true)
            ->where('products.is_service', false)
            ->groupBy('categories.name')
            ->selectRaw('categories.name as category')
            ->selectRaw('COUNT(*) as products')
            ->selectRaw('COALESCE(SUM(products.stock_quantity), 0) as units')
            ->selectRaw('COALESCE(SUM(products.stock_quantity * products.cost_price), 0) as cost')
            ->selectRaw('COALESCE(SUM(products.stock_quantity * products.sale_price), 0) as retail')
            ->get()
            ->map(function ($row) {
                $cost = round((float) $row->cost, 2);
                $retail = round((float) $row->retail, 2);

                return [
                    'category' => $row->category ?: 'Uncategorised',
                    'products' => (int) $row->products,
                    'units' => (int) $row->units,
                    'cost' => $cost,
                    'retail' => $retail,
                    'potential_profit' => round($retail - $cost, 2),
                    'margin' => $retail > 0 ? round(($retail - $cost) / $retail * 100, 2) : 0.0,
                ];
            })
            ->sortByDesc('cost')
            ->values();

        $cost = round((float) $rows->sum('cost'), 2);
        $retail = round((float) $rows->sum('retail'), 2);

        return [
            'rows' => $rows,
            'totals' => [
                'products' => (int) $rows->sum('products'),
                'units' => (int) $rows->sum('units'),
                'cost' => $cost,
                'retail' => $retail,
                'potential_profit' => round($retail - $cost, 2),
                'margin' => $retail > 0 ? round(($retail - $cost) / $retail * 100, 2) : 0.0,
            ],
        ];
    }

    /** How many stocked items are at or below their reorder level. */
    public function lowStockCount(): int
    {
        return Product::query()->active()->lowStock()->count();
    }

    /**
     * The shortest list worth acting on, for the dashboard panel.
     *
     * @return Collection<int, Product>
     */
    public function lowStock(int $limit = 5): Collection
    {
        return Product::query()
            ->active()
            ->lowStock()
            ->with('category')
            ->orderBy('stock_quantity')
            ->limit($limit)
            ->get();
    }

    /**
     * Stock that has expired or is about to, with what it is worth.
     *
     * Only items still physically held (stock above zero) are at risk: an expired
     * line with nothing left on the shelf has already been sold or written off.
     *
     * @param  int  $days  how far ahead to look
     * @return array{rows: Collection<int, Product>, buckets: array<string, array<string, float|int>>, totals: array<string, float|int>}
     */
    public function expiring(int $days = 30): array
    {
        $today = today();
        $horizon = $today->copy()->addDays($days);

        $rows = Product::query()
            ->stocked()
            ->whereNotNull('expiry_date')
            ->where('stock_quantity', '>', 0)
            ->whereDate('expiry_date', '<=', $horizon)
            ->with('category')
            ->orderBy('expiry_date')
            ->get();

        /*
         * Ordered bands rather than a flat list: "expired" and "goes off on
         * Friday" need different action today, and a chart of ordered bands is
         * readable in a way that 200 dated rows is not.
         */
        $buckets = [
            'expired' => ['label' => 'Already expired', 'items' => 0, 'units' => 0, 'value' => 0.0],
            'week' => ['label' => 'Within 7 days', 'items' => 0, 'units' => 0, 'value' => 0.0],
            'month' => ['label' => '8 - 30 days', 'items' => 0, 'units' => 0, 'value' => 0.0],
            'later' => ['label' => 'Over 30 days', 'items' => 0, 'units' => 0, 'value' => 0.0],
        ];

        foreach ($rows as $product) {
            // Signed, and whole: negative means it went off that many days ago.
            $daysLeft = (int) $today->diffInDays($product->expiry_date, false);

            $key = match (true) {
                $daysLeft < 0 => 'expired',
                $daysLeft <= 7 => 'week',
                $daysLeft <= 30 => 'month',
                default => 'later',
            };

            $buckets[$key]['items']++;
            $buckets[$key]['units'] += (int) $product->stock_quantity;
            $buckets[$key]['value'] = round($buckets[$key]['value'] + $product->stock_value, 2);
        }

        return [
            'rows' => $rows,
            'buckets' => $buckets,
            'totals' => [
                'items' => $rows->count(),
                'units' => (int) $rows->sum('stock_quantity'),
                'value' => round(array_sum(array_column($buckets, 'value')), 2),
            ],
            'window' => $days,
        ];
    }

    /** Just the count, for a dashboard tile. */
    public function expiringCount(int $days = 30): int
    {
        return Product::query()
            ->stocked()
            ->whereNotNull('expiry_date')
            ->where('stock_quantity', '>', 0)
            ->whereDate('expiry_date', '<=', today()->copy()->addDays($days))
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | What is owed, in both directions
    |--------------------------------------------------------------------------
    */

    /**
     * Who owes the shop money, and how long they have owed it.
     *
     * The balance comes from CreditService, which is the only thing allowed to
     * decide what a sale still owes. The ageing is one grouped query on top.
     *
     * A debt is aged from its due date, or from the invoice date when no due date
     * was set - an invoice with no terms was payable on the day, so letting it sit
     * in "not yet due" forever would hide it.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, float>}
     */
    public function receivables(CreditService $credit): array
    {
        $customers = Customer::query()->withBalance()->orderBy('name')->get();
        $balances = $credit->balancesFor($customers->pluck('id'));
        $overdueIds = Customer::query()->overdue()->pluck('id')->all();
        $ageing = $this->receivablesAgeing();

        $rows = $customers->map(function (Customer $customer) use ($balances, $overdueIds, $ageing) {
            $buckets = $ageing[$customer->id] ?? array_fill_keys(array_keys(self::AGEING), 0.0);

            return [
                'customer' => $customer,
                'owed' => $balances[$customer->id] ?? 0.0,
                'overdue' => in_array($customer->id, $overdueIds, true),
                'buckets' => $buckets,
            ];
        })->sortByDesc('owed')->values();

        $totals = ['owed' => round((float) $rows->sum('owed'), 2)];

        foreach (array_keys(self::AGEING) as $bucket) {
            $totals[$bucket] = round((float) $rows->sum(fn ($row) => $row['buckets'][$bucket]), 2);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Everything customers owe the shop right now, as one figure for a tile.
     *
     * The same condition as Sale::scopeOutstanding(), as a single SUM rather
     * than a balance per customer: the tile needs a number, not a list.
     *
     * @return array{owed: float, overdue: float, customers: int}
     */
    public function totalOwed(): array
    {
        /*
         * Deliberately NOT narrowed to a branch. A customer owes the business,
         * not a building: they can buy in one shop and pay in another, and
         * splitting the debt by where it was incurred would show two balances
         * for one person and chase them twice. Receivables and payables are
         * group-wide for the same reason.
         */
        $row = DB::table('sales')
            ->where('balance_due', '>', 0)
            ->where('status', '!=', Sale::STATUS_VOIDED)
            ->selectRaw('COALESCE(SUM(balance_due), 0) as owed')
            ->selectRaw('COUNT(DISTINCT customer_id) as customers')
            ->selectRaw('COALESCE(SUM(CASE WHEN due_date IS NOT NULL AND due_date < ? THEN balance_due ELSE 0 END), 0) as overdue', [today()->toDateString()])
            ->first();

        return [
            'owed' => round((float) $row->owed, 2),
            'overdue' => round((float) $row->overdue, 2),
            'customers' => (int) $row->customers,
        ];
    }

    /**
     * One grouped query that splits every outstanding balance into age bands.
     *
     * The boundaries are worked out as dates in PHP and compared as dates in SQL,
     * rather than asking the database for a day count: SQLite has julianday() and
     * MySQL has DATEDIFF(), and this app runs on both.
     *
     * @return array<int, array<string, float>>  customer id => bucket => amount
     */
    private function receivablesAgeing(): array
    {
        $today = today()->toDateString();
        $thirty = today()->copy()->subDays(30)->toDateString();
        $sixty = today()->copy()->subDays(60)->toDateString();

        // The date the clock runs from: the agreed due date, else the sale date.
        $due = 'COALESCE(sales.due_date, DATE(sales.created_at))';

        $rows = DB::table('sales')
            // The same condition as Sale::scopeOutstanding(), spelled out because
            // an Eloquent scope is not available on a DB::table() builder.
            ->where('balance_due', '>', 0)
            ->where('status', '!=', Sale::STATUS_VOIDED)
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->selectRaw('customer_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$due} >= ? THEN balance_due ELSE 0 END), 0) as current", [$today])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$due} < ? AND {$due} >= ? THEN balance_due ELSE 0 END), 0) as b1_30", [$today, $thirty])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$due} < ? AND {$due} >= ? THEN balance_due ELSE 0 END), 0) as b31_60", [$thirty, $sixty])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$due} < ? THEN balance_due ELSE 0 END), 0) as over_60", [$sixty])
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->customer_id] = [
                'current' => round((float) $row->current, 2),
                '1_30' => round((float) $row->b1_30, 2),
                '31_60' => round((float) $row->b31_60, 2),
                'over_60' => round((float) $row->over_60, 2),
            ];
        }

        return $out;
    }

    /**
     * What the shop owes its suppliers.
     *
     * The balance is SupplierLedgerService's to decide (it is derived from five
     * kinds of row, never stored), so this only adds the two things a payables
     * screen needs beyond it: what is on order but not yet delivered, and how old
     * the unsettled deliveries are.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, float>}
     */
    public function payables(SupplierLedgerService $ledger): array
    {
        $suppliers = Supplier::query()->orderBy('name')->get();
        $balances = $ledger->balances($suppliers);
        $ordered = $this->orderedNotReceived();
        $ageing = $this->payablesAgeing();

        $rows = $suppliers->map(fn (Supplier $supplier) => [
            'supplier' => $supplier,
            'balance' => $balances[$supplier->id] ?? 0.0,
            'on_order' => round((float) ($ordered[$supplier->id] ?? 0), 2),
            'buckets' => $ageing[$supplier->id] ?? array_fill_keys(array_keys(self::AGEING), 0.0),
        ])
            // A supplier with nothing owing and nothing coming is noise on this page.
            ->filter(fn (array $row) => $row['balance'] != 0.0 || $row['on_order'] > 0)
            ->sortByDesc('balance')
            ->values();

        $totals = [
            'balance' => round((float) $rows->sum('balance'), 2),
            'on_order' => round((float) $rows->sum('on_order'), 2),
        ];

        foreach (array_keys(self::AGEING) as $bucket) {
            $totals[$bucket] = round((float) $rows->sum(fn ($row) => $row['buckets'][$bucket]), 2);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /** Raised with the supplier but not yet delivered: not a debt yet, but money coming. */
    private function orderedNotReceived(): Collection
    {
        return $this->branch(DB::table('purchases'), 'purchases')
            ->whereNull('deleted_at')
            ->where('status', Purchase::STATUS_ORDERED)
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COALESCE(SUM(total_amount), 0) as total')
            ->pluck('total', 'supplier_id');
    }

    /**
     * How old the unsettled deliveries are, by invoice date.
     *
     * A guide to the oldest exposure rather than a split of the balance: supplier
     * payments are made against the account as a whole, not against one invoice,
     * so they cannot be attributed to a band. The view says as much.
     *
     * @return array<int, array<string, float>>
     */
    private function payablesAgeing(): array
    {
        $today = today()->toDateString();
        $thirty = today()->copy()->subDays(30)->toDateString();
        $sixty = today()->copy()->subDays(60)->toDateString();

        $date = 'DATE(purchases.purchase_date)';
        $owed = 'purchases.total_amount - purchases.amount_paid';

        $rows = $this->branch(DB::table('purchases'), 'purchases')
            ->whereNull('deleted_at')
            ->where('status', Purchase::STATUS_RECEIVED)
            ->whereColumn('total_amount', '>', 'amount_paid')
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN {$date} >= ? THEN {$owed} ELSE 0 END), 0) as current", [$today])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$date} < ? AND {$date} >= ? THEN {$owed} ELSE 0 END), 0) as b1_30", [$today, $thirty])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$date} < ? AND {$date} >= ? THEN {$owed} ELSE 0 END), 0) as b31_60", [$thirty, $sixty])
            ->selectRaw("COALESCE(SUM(CASE WHEN {$date} < ? THEN {$owed} ELSE 0 END), 0) as over_60", [$sixty])
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->supplier_id] = [
                'current' => round((float) $row->current, 2),
                '1_30' => round((float) $row->b1_30, 2),
                '31_60' => round((float) $row->b31_60, 2),
                'over_60' => round((float) $row->over_60, 2),
            ];
        }

        return $out;
    }

    /*
    |--------------------------------------------------------------------------
    | Shifts
    |--------------------------------------------------------------------------
    */

    /**
     * Shifts for a period, each summarised by the service that owns that logic.
     *
     * ShiftService::summarise() is deliberately called rather than reimplemented:
     * the expected-versus-counted arithmetic has one home, and it is not here.
     * That costs a handful of queries per shift, which is the right trade for a
     * list that holds one row per cashier per day.
     *
     * @return Collection<int, array{shift: Shift, summary: array<string, mixed>}>
     */
    public function shifts(ShiftService $shifts, Carbon $start, Carbon $end, ?int $userId = null): Collection
    {
        return Shift::query()
            ->whereBetween('opened_at', [$start, $end])
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->with(['user', 'closedBy'])
            ->orderByDesc('opened_at')
            ->get()
            ->map(fn (Shift $shift) => [
                'shift' => $shift,
                'summary' => $shifts->summarise($shift),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Shared internals
    |--------------------------------------------------------------------------
    */

    /**
     * Narrow a query-builder read to the branch being acted as.
     *
     * WHY THIS HAS TO BE HERE AT ALL
     *
     * Every method in this class aggregates through DB::table() rather than
     * through Eloquent, deliberately: a report over a year has to cost the same
     * number of queries as a report over a week. The price of that is that the
     * global branch scope - which is an *Eloquent* scope - does not apply to any
     * of it. So the branch is applied by hand, and it is applied in exactly one
     * place so that it cannot be applied to five reports and forgotten on the
     * sixth.
     *
     * HOW IT BEHAVES
     *
     *   - single-site shop: scoping() is false, nothing is added, and every
     *     figure is exactly what it has always been;
     *   - multi-branch: the report answers for the shop you are standing in,
     *     which is what the rest of the app does and so what a branch manager
     *     reading it expects;
     *   - inside BranchContext::groupWide(): scoping() is false again, so the
     *     same report answers for the whole group. That is the owner's
     *     consolidated view, and it is the *same* escape hatch the Eloquent side
     *     uses rather than a second one with its own rules.
     *
     * @param  string  $table  the aliased or real table carrying `branch_id`
     */
    private function branch($query, string $table = 'sales')
    {
        $branches = app(BranchContext::class);

        return $branches->scoping()
            ? $query->where($table . '.branch_id', $branches->id())
            : $query;
    }

    /**
     * Revenue, cost and the two figures derived from them, rounded once.
     *
     * @return array{revenue: float, cogs: float, gross_profit: float, margin: float}
     */
    private function margin(float $revenue, float $cogs): array
    {
        return [
            'revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => round($revenue - $cogs, 2),
            // Margin on no revenue is not zero, it is undefined; zero is the only
            // sane thing to print, and it must not be a division.
            'margin' => $revenue > 0 ? round(($revenue - $cogs) / $revenue * 100, 2) : 0.0,
        ];
    }

    /**
     * One grouped "total per day" query, as [Y-m-d => total].
     *
     * The same shape profitLoss() uses, for the same reason: one query per figure
     * instead of one query per day.
     */
    private function perDay($query, string $dateColumn, string $sumExpr, $lo, $hi): Collection
    {
        return $query
            ->whereBetween($dateColumn, [$lo, $hi])
            ->selectRaw("DATE({$dateColumn}) as day, SUM({$sumExpr}) as total")
            ->groupBy('day')
            ->pluck('total', 'day');
    }

    /**
     * Refunds, optionally narrowed to the refunds given back on one person's sales.
     *
     * Joining only when a user is asked for keeps the common case a single-table
     * query instead of always paying for the join.
     */
    private function refundQuery(?int $userId)
    {
        // Refunds carry their own branch - the money left a particular drawer -
        // so they are narrowed directly rather than through the sale.
        $query = $this->branch(DB::table('refunds'), 'refunds');

        if ($userId !== null) {
            $query->join('sales', 'sales.id', '=', 'refunds.sale_id')->where('sales.user_id', $userId);
        }

        return $query;
    }

    /**
     * Names and categories for a set of product ids, in one query.
     *
     * Reads through DB::table() so archived (soft-deleted) products still resolve:
     * a report of last month must not lose the name of something deleted since.
     *
     * @param  Collection<int, mixed>  $ids
     * @return Collection<int, object>
     */
    private function productNames(Collection $ids): Collection
    {
        $ids = $ids->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table('products')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('products.id', $ids)
            ->selectRaw('products.id, products.name, products.sku, categories.name as category')
            ->get()
            ->keyBy('id');
    }

    /**
     * Fold "method => total" rows into running totals, ignoring any unknown tender.
     *
     * @param  array<string, float>  $totals
     * @param  Collection<int, object>  $rows
     */
    private function addMethodRows(array &$totals, Collection $rows): void
    {
        foreach ($rows as $row) {
            if (array_key_exists($row->method, $totals)) {
                $totals[$row->method] = round($totals[$row->method] + (float) $row->total, 2);
            }
        }
    }

    /** Does the split-payment table exist yet, with the columns this reads? */
    private function hasTenderRows(): bool
    {
        return Schema::hasTable('sale_payments')
            && Schema::hasColumn('sale_payments', 'method')
            && Schema::hasColumn('sale_payments', 'amount');
    }
}
