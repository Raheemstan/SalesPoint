<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Expense;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\Shift;
use App\Services\CreditService;
use App\Services\ReportService;
use App\Services\ShiftService;
use App\Services\SupplierLedgerService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    private const PAYMENT_METHODS = 'cash,pos,transfer';

    /** The trend windows the dashboard offers. */
    private const PERIODS = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'];

    /** inventory_logs.type: IN and OUT are written everywhere; ADJUST by a manual stock edit. */
    private const MOVEMENT_TYPES = ['IN' => 'In', 'OUT' => 'Out', 'ADJUST' => 'Adjusted'];

    /**
     * What caused a stock movement.
     *
     * `inventory_logs.reason` is free text written by whatever moved the stock, but
     * every writer uses a recognisable opening (see StockAdjustmentService,
     * RefundService, POSController), so a prefix match is exact rather than fuzzy.
     * The structured reasons a stock adjustment now carries are listed too, which
     * is what makes "show me everything written off as damage" possible.
     *
     * Only the key comes from the request (validated against this list); the
     * pattern is never user input.
     */
    private const MOVEMENT_SOURCES = [
        'sale' => ['label' => 'Sales', 'like' => 'Sale %'],
        'refund' => ['label' => 'Refunds', 'like' => 'Refund %'],
        'void' => ['label' => 'Voided sales', 'like' => 'Void %'],
        'purchase' => ['label' => 'Purchases', 'like' => 'Purchase%'],
        'supplier_return' => ['label' => 'Returned to supplier', 'like' => 'Returned to supplier%'],
        'opening' => ['label' => 'Opening stock', 'like' => 'Opening stock%'],
        'stock_take' => ['label' => 'Stock takes', 'like' => 'Stock-take %'],
        'adjustment' => ['label' => 'Adjustments (any reason)', 'like' => 'Adjustment:%'],
        'damage' => ['label' => 'Adjusted: damage', 'like' => 'Adjustment: damage%'],
        'theft' => ['label' => 'Adjusted: theft', 'like' => 'Adjustment: theft%'],
        'expiry' => ['label' => 'Adjusted: expiry', 'like' => 'Adjustment: expiry%'],
        'count' => ['label' => 'Adjusted: count', 'like' => 'Adjustment: count%'],
        'correction' => ['label' => 'Adjusted: correction', 'like' => 'Adjustment: correction%'],
        'other' => ['label' => 'Adjusted: other', 'like' => 'Adjustment: other%'],
    ];

    /** Columns the per-product report may be sorted by. */
    private const PRODUCT_SORTS = [
        'revenue' => 'Revenue',
        'quantity' => 'Quantity sold',
        'gross_profit' => 'Gross profit',
        'margin' => 'Margin %',
        'cogs' => 'Cost of goods sold',
        'name' => 'Name',
    ];

    public function __construct(private readonly ReportService $reports)
    {
    }

    /**
     * Resolve and validate a date range. Both ends are inclusive: the end date
     * runs to 23:59:59, not midnight (which silently dropped the whole last day).
     *
     * @param  ?string  $remember  a key under which to remember the range, so a
     *                             report comes back the way you left it. The
     *                             request always wins; the memory only supplies
     *                             the default.
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dateRange(Request $request, string $startKey = 'start_date', string $endKey = 'end_date', ?string $remember = null): array
    {
        $request->validate([
            $startKey => 'nullable|date',
            $endKey => "nullable|date|after_or_equal:{$startKey}",
            'payment_method' => 'nullable|in:' . self::PAYMENT_METHODS,
            'export' => 'nullable|in:csv',
        ]);

        $saved = $remember === null ? [] : (array) session('reports.range.' . $remember, []);

        $start = Carbon::parse($request->input($startKey, $saved[$startKey] ?? now()->startOfMonth()->toDateString()))->startOfDay();
        $end = Carbon::parse($request->input($endKey, $saved[$endKey] ?? now()->endOfMonth()->toDateString()))->endOfDay();

        if ($remember !== null) {
            session(['reports.range.' . $remember => [
                $startKey => $start->toDateString(),
                $endKey => $end->toDateString(),
            ]]);
        }

        return [$start, $end];
    }

    /**
     * Remember one non-date filter per report, the same way the range is remembered.
     *
     * The request always wins and is always validated by the caller's own rules
     * first; this only decides what the default is when the filter is absent.
     *
     * @param  string  $key  the query-string field to read
     * @param  ?array<int, mixed>  $allowed  re-check a value read back out of the
     *                                       session against this list. Pass null
     *                                       where the caller's validation rules
     *                                       already pin the value down (an
     *                                       `exists:` rule, a numeric range).
     * @param  ?string  $as  where to remember it, when the field name is too
     *                       generic to share a session with every other report
     *                       ("type", "search"). Defaults to the field name.
     */
    private function remembered(Request $request, string $key, ?array $allowed, $default, ?string $as = null)
    {
        $session = 'reports.filter.' . ($as ?? $key);

        if ($request->has($key)) {
            // A blank choice means "no filter", and is remembered as such.
            $value = $request->input($key);
            $value = $value === '' || $value === null ? null : $value;

            session([$session => $value]);

            return $value;
        }

        $value = session($session, $default);

        // Settings can change under a remembered filter (a category is deleted, a
        // feature is switched off), so a stale value falls back rather than 500s.
        if ($value !== null && $allowed !== null && !in_array($value, $allowed, false)) {
            return $default;
        }

        return $value;
    }

    private function wantsCsv(Request $request): bool
    {
        return $request->query('export') === 'csv';
    }

    private function salesQuery(Carbon $start, Carbon $end, ?string $paymentMethod)
    {
        return Sale::whereBetween('created_at', [$start, $end])
            ->when($paymentMethod, fn($q) => $q->where('payment_method', $paymentMethod))
            ->with('items.product')
            ->latest();
    }

    private function summarise($sales, Carbon $start, Carbon $end): array
    {
        // Refunds are counted on the day they were paid out, not the day of the original sale.
        $refunds = (float) Refund::whereBetween('created_at', [$start, $end])->sum('total_amount');

        return [
            'total_sales' => $sales->sum('grand_total'),
            'total_refunds' => $refunds,
            'net_sales' => round($sales->sum('grand_total') - $refunds, 2),
            'total_tax' => $sales->sum('tax_amount'),
            'total_discount' => $sales->sum('discount_amount'),
            'total_transactions' => $sales->where('status', '!=', Sale::STATUS_VOIDED)->count(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | The dashboard
    |--------------------------------------------------------------------------
    */

    /**
     * The home dashboard.
     *
     * Role-aware on purpose. A cashier gets their own takings, their own drawer
     * and their own recent sales; they never see margin, whole-shop profit or
     * what anyone else took. That is not only a permissions matter - a figure a
     * cashier cannot act on is noise on the one screen they look at all day.
     *
     * Everything here has to survive a brand-new install with no rows at all:
     * every average is guarded against dividing by zero and every panel has an
     * empty state, because a shop's first day must not show a broken page.
     */
    public function dashboard(Request $request, ShiftService $shifts)
    {
        $request->validate(['days' => 'nullable|integer|in:' . implode(',', array_keys(self::PERIODS))]);

        $days = (int) $request->input('days', 30);
        $user = $request->user();

        $end = now()->endOfDay();
        $start = now()->copy()->subDays($days - 1)->startOfDay();

        // The window of the same length immediately before, for comparison.
        $previousEnd = $start->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();

        $todayStart = today()->startOfDay();
        $todayEnd = today()->endOfDay();

        if (!$user->hasAnyRole(['admin', 'manager'])) {
            return $this->cashierDashboard($shifts, $user, $days, $start, $end, $todayStart, $todayEnd);
        }

        $today = $this->reports->takings($todayStart, $todayEnd);
        $yesterday = $this->reports->takings(
            today()->copy()->subDay()->startOfDay(),
            today()->copy()->subDay()->endOfDay()
        );

        $trend = $this->reports->takingsByDay($start, $end);
        $previous = $this->reports->takingsByDay($previousStart, $previousEnd);

        $open = Shift::open()->with('user')->orderBy('opened_at')->get();

        /*
         * Stock figures only for a trade that keeps stock. A salon or a repair
         * shop sells labour: there is nothing to run low, nothing to reorder and
         * nothing to value, and the sidebar hides those screens for the same
         * reason. Skipped rather than computed and hidden, so the queries are not
         * run to produce a number that can never move.
         */
        $tracksStock = feature('stock');

        return view('dashboard', [
            'role' => 'manager',
            'days' => $days,
            'periods' => self::PERIODS,
            'rangeLabel' => $start->format('d M Y') . ' - ' . $end->format('d M Y'),

            'today' => $today,
            'yesterday' => $yesterday,
            'profit' => $this->reports->profit($todayStart, $todayEnd),
            // Shown in place of the stock tiles where there is no stock.
            'periodProfit' => $tracksStock ? null : $this->reports->profit($start, $end),

            'trend' => $trend,
            // Aligned by position, not by date: day 1 of this window against day 1
            // of the last. Both are takings, so they share one axis.
            'previousValues' => $previous['values'],
            'previousLabel' => $previousStart->format('d M') . ' - ' . $previousEnd->format('d M Y'),
            'periodTotal' => round(array_sum($trend['values']), 2),
            'previousTotal' => round(array_sum($previous['values']), 2),

            'methods' => $this->reports->takenByMethod($todayStart, $todayEnd),

            'topQuantity' => $this->reports->topProducts($start, $end, 'quantity', 5),
            'topProfit' => $this->reports->topProducts($start, $end, 'gross_profit', 5),

            'owed' => $this->reports->totalOwed(),
            'lowStockCount' => $tracksStock ? $this->reports->lowStockCount() : 0,
            'lowStock' => $tracksStock ? $this->reports->lowStock(5) : collect(),
            'expiringCount' => $tracksStock && feature('expiry') ? $this->reports->expiringCount(30) : 0,
            'stockAtCost' => $tracksStock
                ? round((float) Product::query()->stocked()->sum(DB::raw('stock_quantity * cost_price')), 2)
                : 0.0,

            // One summarise() per open drawer - there is one per cashier on duty,
            // and ShiftService owns that arithmetic.
            'openShifts' => $open->map(fn(Shift $shift) => [
                'shift' => $shift,
                'summary' => $shifts->summarise($shift),
            ]),

            'recent' => Sale::with(['user', 'customer'])->latest()->limit(8)->get(),
        ]);
    }

    /**
     * The same screen for a cashier: their takings, their drawer, their sales.
     *
     * Deliberately a separate method rather than a pile of @role checks in the
     * view: what a cashier may see is decided here, once, where it can be read
     * and tested, instead of being spread across a template.
     */
    private function cashierDashboard(ShiftService $shifts, $user, int $days, Carbon $start, Carbon $end, Carbon $todayStart, Carbon $todayEnd)
    {
        $shift = $shifts->currentFor($user);

        return view('dashboard', [
            'role' => 'cashier',
            'days' => $days,
            'periods' => self::PERIODS,
            'rangeLabel' => $start->format('d M Y') . ' - ' . $end->format('d M Y'),

            'today' => $this->reports->takings($todayStart, $todayEnd, $user->id),
            'trend' => $this->reports->takingsByDay($start, $end, $user->id),

            'shift' => $shift,
            'shiftSummary' => $shift ? $shifts->summarise($shift) : null,
            'shiftRequired' => $shifts->requiredToSell(),

            // A cashier may only see their own sales, and only today's: the same
            // rule SalePolicy applies on the sales history screen.
            'recent' => Sale::where('user_id', $user->id)
                ->whereBetween('created_at', [$todayStart, $todayEnd])
                ->with('customer')
                ->latest()
                ->limit(8)
                ->get(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Sales
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        [$start, $end] = $this->dateRange($request, 'start_date', 'end_date', 'sales');
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        $sales = $this->salesQuery($start, $end, $request->payment_method)->get();

        $summary = $this->summarise($sales, $start, $end);

        return view('reports.sales', [
            'sales' => $sales,
            'summary' => $summary,
            'startDate' => $startDate,
            'endDate' => $endDate,
            // The same figures the table shows, day by day, for the chart.
            'trend' => $this->reports->takingsByDay($start, $end),
        ]);
    }

    /**
     * Day-by-day takings for a period, plus the till shifts behind them.
     *
     * The `data` block is the original today/this-month headline and keeps its
     * exact keys: other things read it. Everything else is the Z-report side -
     * who was on the till, what the drawer should have held and what it did.
     */
    public function dailySales(Request $request, ShiftService $shifts)
    {
        [$start, $end] = $this->dateRange($request, 'start_date', 'end_date', 'daily');

        $today = Sale::whereDate('created_at', today());
        // whereMonth alone matched the same month of *every* year.
        $thisMonth = Sale::whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]);

        // Net of refunds paid out in the same period; voided sales are not transactions.
        $data = [
            'sales_today' => $today->sum('grand_total') - Refund::whereDate('created_at', today())->sum('total_amount'),
            'sales_this_month' => $thisMonth->sum('grand_total')
                - Refund::whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('total_amount'),
            'transactions_today' => (clone $today)->where('status', '!=', Sale::STATUS_VOIDED)->count(),
            'total_products' => Product::count(),
        ];

        $trend = $this->reports->takingsByDay($start, $end);

        if ($this->wantsCsv($request)) {
            return $this->csv('daily_summary.csv', ['Date', 'Takings'], function () use ($trend) {
                foreach ($trend['labels'] as $i => $label) {
                    yield [$label, $trend['values'][$i]];
                }
            });
        }

        return view('reports.sales_daily', [
            'data' => $data,
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'trend' => $trend,
            'summary' => $this->reports->takings($start, $end),
            'methods' => $this->reports->takenByMethod($start, $end),
            // ShiftService::summarise() is the single source of truth for a
            // drawer's expected/counted/variance; this report only lists it.
            'shifts' => $this->reports->shifts($shifts, $start, $end),
        ]);
    }

    public function exportPDF(Request $request)
    {
        [$start, $end] = $this->dateRange($request, 'start_date', 'end_date', 'sales');
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        $sales = $this->salesQuery($start, $end, $request->payment_method)->get();

        // The PDF template reads $summary; it was never passed, so every export crashed.
        $summary = $this->summarise($sales, $start, $end);

        $pdf = Pdf::loadView('reports.sales_pdf', compact('sales', 'summary', 'startDate', 'endDate'));
        return $pdf->download('sales_report.pdf');
    }

    public function exportCSV(Request $request)
    {
        [$start, $end] = $this->dateRange($request, 'start_date', 'end_date', 'sales');

        $sales = $this->salesQuery($start, $end, $request->payment_method)->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="sales_report.csv"',
        ];

        $callback = function () use ($sales) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Date', 'Invoice', 'Items', 'Total', 'Tax', 'Discount', 'Payment Method', 'Status', 'Refunded']);

            foreach ($sales as $sale) {
                $items = $sale->items
                    ->map(fn($i) => ($i->product->name ?? 'Deleted product') . " (x{$i->quantity})")
                    ->implode('; ');

                fputcsv($handle, [
                    $sale->created_at->format('d M Y'),
                    $sale->invoice_number,
                    $this->csvSafe($items),
                    $sale->grand_total,
                    $sale->tax_amount,
                    $sale->discount_amount,
                    ucfirst($sale->payment_method),
                    $sale->status,
                    $sale->refunded_amount,
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Stop spreadsheet apps treating user-entered text (e.g. a product name
     * starting with "=") as a formula.
     */
    private function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    /**
     * A streamed CSV, headers and formula-guarding included.
     *
     * Streamed rather than built in memory because a year of a busy shop is more
     * rows than a response should hold. Every cell is a raw number or a plain
     * string: a formatted money string ("<symbol>1,200.00") is unusable in a
     * spreadsheet, so money() never appears in here.
     *
     * @param  array<int, string>  $header
     * @param  Closure():iterable<array<int, mixed>>  $rows
     */
    private function csv(string $filename, array $header, Closure $rows)
    {
        return response()->stream(function () use ($header, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $header);

            foreach ($rows() as $row) {
                fputcsv($handle, array_map(
                    fn($cell) => is_string($cell) ? $this->csvSafe($cell) : $cell,
                    $row
                ));
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Stock movement
    |--------------------------------------------------------------------------
    */

    public function movement(Request $request)
    {
        [$start, $end] = $this->dateRange($request, 'start_date', 'end_date', 'movement');

        $request->validate([
            'product_id' => 'nullable|integer|exists:products,id',
            'type' => 'nullable|in:' . implode(',', array_keys(self::MOVEMENT_TYPES)),
            'source' => 'nullable|in:' . implode(',', array_keys(self::MOVEMENT_SOURCES)),
        ]);

        // Nothing is counted in or out in a trade that keeps no stock.
        if (!feature('stock')) {
            return view('reports.movement', ['relevant' => false]);
        }

        $productId = $this->remembered($request, 'product_id', null, null, 'movement_product');
        $type = $this->remembered($request, 'type', array_keys(self::MOVEMENT_TYPES), null, 'movement_type');
        $source = $this->remembered($request, 'source', array_keys(self::MOVEMENT_SOURCES), null, 'movement_source');

        $filtered = fn($query) => $query
            ->whereBetween('inventory_logs.created_at', [$start, $end])
            ->when($productId, fn($q) => $q->where('inventory_logs.product_id', $productId))
            ->when($type, fn($q) => $q->where('inventory_logs.type', $type))
            ->when($source, fn($q) => $q->where('inventory_logs.reason', 'like', self::MOVEMENT_SOURCES[$source]['like']));

        // Real stock movements (sales, purchases, manual adjustments), not just sales.
        $movements = $filtered(InventoryLog::with(['product', 'user']))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        if ($this->wantsCsv($request)) {
            // Chunked so an export of a year does not load a year into memory.
            $query = $filtered(InventoryLog::with(['product', 'user']))->orderBy('inventory_logs.id');

            return $this->csv('stock_movement.csv',
                ['Date', term('product'), 'SKU', 'Type', 'Quantity', 'Reason', 'By'],
                function () use ($query) {
                    foreach ($query->lazy(500) as $log) {
                        yield [
                            $log->created_at->format('Y-m-d H:i'),
                            $log->product->name ?? 'Deleted ' . strtolower(term('product')),
                            $log->product->sku ?? '',
                            $log->type,
                            $this->signedQuantity($log),
                            (string) $log->reason,
                            $log->user->name ?? '',
                        ];
                    }
                });
        }

        return view('reports.movement', [
            'relevant' => true,
            'movements' => $movements,
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'products' => Product::orderBy('name')->get(['id', 'name']),
            'types' => self::MOVEMENT_TYPES,
            'sources' => array_map(fn($s) => $s['label'], self::MOVEMENT_SOURCES),
            'productId' => $productId,
            'type' => $type,
            'source' => $source,
            'flow' => $this->movementFlow($start, $end, $productId, $type, $source),
        ]);
    }

    /**
     * Units in and units out per day, for the movement chart.
     *
     * One grouped query for the whole range, folded into two series in PHP. An
     * ADJUST row carries a signed quantity (a manual stock edit can go either
     * way), so the sign decides which side of the baseline it lands on.
     *
     * @return array{labels: array<int, string>, in: array<int, int>, out: array<int, int>}
     */
    private function movementFlow(Carbon $start, Carbon $end, $productId, $type, $source): array
    {
        $rows = InventoryLog::query()
            ->whereBetween('inventory_logs.created_at', [$start, $end])
            ->when($productId, fn($q) => $q->where('product_id', $productId))
            ->when($type, fn($q) => $q->where('type', $type))
            ->when($source, fn($q) => $q->where('reason', 'like', self::MOVEMENT_SOURCES[$source]['like']))
            ->groupBy('day', 'type')
            ->selectRaw('DATE(inventory_logs.created_at) as day, inventory_logs.type as type, SUM(inventory_logs.quantity) as total')
            ->get();

        $in = [];
        $out = [];

        foreach ($rows as $row) {
            $total = (int) $row->total;

            // IN/OUT name the direction and always carry a positive quantity;
            // ADJUST carries the direction in the sign instead.
            $isIn = $row->type === 'IN' || ($row->type !== 'OUT' && $total >= 0);

            if ($isIn) {
                $in[$row->day] = ($in[$row->day] ?? 0) + abs($total);
            } else {
                $out[$row->day] = ($out[$row->day] ?? 0) + abs($total);
            }
        }

        $labels = [];
        $inSeries = [];
        $outSeries = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();

            $labels[] = $date;
            $inSeries[] = $in[$date] ?? 0;
            // Drawn below the baseline: in above, out below, which is what makes
            // a quiet week and a week of churn tell themselves apart at a glance.
            $outSeries[] = -($out[$date] ?? 0);
        }

        return ['labels' => $labels, 'in' => $inSeries, 'out' => $outSeries];
    }

    /** The movement as a signed number, for a CSV cell or a table. */
    private function signedQuantity(InventoryLog $log): int
    {
        $quantity = (int) $log->quantity;

        return $log->type === 'OUT' ? -abs($quantity) : $quantity;
    }

    /*
    |--------------------------------------------------------------------------
    | Profit & loss
    |--------------------------------------------------------------------------
    */

    public function profitLoss(Request $request)
    {
        [$start, $end] = $this->dateRange($request, 'from', 'to', 'profit_loss');
        $from = $start->toDateString();
        $to = $end->toDateString();

        abort_if($start->diffInDays($end) > 366, 422, 'Please choose a range of one year or less.');

        // One grouped query per figure instead of several queries per day.
        // expense_date is a DATE column, so it needs date-only bounds.
        $byDay = fn($query, string $dateColumn, string $sumExpr, $lo, $hi) => $query
            ->whereBetween($dateColumn, [$lo, $hi])
            ->selectRaw("DATE({$dateColumn}) as day, SUM({$sumExpr}) as total")
            ->groupBy('day')
            ->pluck('total', 'day');

        // Revenue excludes tax: tax collected belongs to the tax authority, not the shop.
        $revenueByDay = $byDay(Sale::query(), 'created_at', 'grand_total - tax_amount', $start, $end);
        $refundedByDay = $byDay(Refund::query(), 'created_at', 'total_amount - tax_amount', $start, $end);

        // Cost of goods sold uses the cost recorded at the moment of sale.
        $cogsByDay = $byDay(
            DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id'),
            'sales.created_at', 'sale_items.quantity * sale_items.cost_price', $start, $end
        );
        // Goods that came back into stock are no longer a cost; written-off returns stay one.
        $cogsReturnedByDay = $byDay(
            DB::table('refund_items')->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')->where('refund_items.restocked', true),
            'refunds.created_at', 'refund_items.quantity * refund_items.cost_price', $start, $end
        );

        $purchasesByDay = $byDay(Purchase::query(), 'purchase_date', 'total_amount', $start, $end);
        $expensesByDay = $byDay(Expense::query(), 'expense_date', 'amount', $from, $to);

        $labels = [];
        $salesData = [];
        $cogsData = [];
        $purchasesData = [];
        $expensesData = [];
        $netProfitData = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();

            $revenue = (float) ($revenueByDay[$date] ?? 0) - (float) ($refundedByDay[$date] ?? 0);
            $cogs = (float) ($cogsByDay[$date] ?? 0) - (float) ($cogsReturnedByDay[$date] ?? 0);
            $expenses = (float) ($expensesByDay[$date] ?? 0);

            $labels[] = $date;
            $salesData[] = round($revenue, 2);
            $cogsData[] = round($cogs, 2);
            $purchasesData[] = (float) ($purchasesByDay[$date] ?? 0);
            $expensesData[] = $expenses;
            $netProfitData[] = round($revenue - $cogs - $expenses, 2);
        }

        $salesList = Sale::whereBetween('created_at', [$start, $end])->get();
        $refundList = Refund::with('sale')->whereBetween('created_at', [$start, $end])->get();
        $purchaseList = Purchase::whereBetween('purchase_date', [$start, $end])->get();
        $expenseList = Expense::whereBetween('expense_date', [$from, $to])->get();

        $totalSales = round(array_sum($salesData), 2);
        $totalCogs = round(array_sum($cogsData), 2);
        $totalExpenses = array_sum($expensesData);
        $grossProfit = round($totalSales - $totalCogs, 2);
        $netProfit = round($grossProfit - $totalExpenses, 2);

        if ($this->wantsCsv($request)) {
            return $this->csv('profit_and_loss.csv',
                ['Date', 'Revenue (excl. tax, net of refunds)', 'Cost of goods sold', 'Gross profit', 'Expenses', 'Net profit', 'Stock purchased'],
                function () use ($labels, $salesData, $cogsData, $expensesData, $netProfitData, $purchasesData) {
                    foreach ($labels as $i => $label) {
                        yield [
                            $label,
                            $salesData[$i],
                            $cogsData[$i],
                            round($salesData[$i] - $cogsData[$i], 2),
                            $expensesData[$i],
                            $netProfitData[$i],
                            $purchasesData[$i],
                        ];
                    }
                });
        }

        return view('reports.profit_loss', [
            'from' => $from,
            'to' => $to,
            'labels' => $labels,
            'salesData' => $salesData,
            'cogsData' => $cogsData,
            'purchasesData' => $purchasesData,
            'expensesData' => $expensesData,
            'netProfitData' => $netProfitData,
            'sales' => $totalSales,
            'cogs' => $totalCogs,
            'purchases' => array_sum($purchasesData),   // stock bought: cash out, but not an expense until sold
            'expenses' => $totalExpenses,
            'grossProfit' => $grossProfit,
            'netProfit' => $netProfit,
            'taxCollected' => round(
                (float) Sale::whereBetween('created_at', [$start, $end])->sum('tax_amount')
                - (float) Refund::whereBetween('created_at', [$start, $end])->sum('tax_amount'), 2),
            'refundsTotal' => (float) $refundList->sum('total_amount'),
            'salesList' => $salesList,
            'refundList' => $refundList,
            'purchaseList' => $purchaseList,
            'expenseList' => $expenseList,
            // Presentation only: the margin the figures above already imply.
            'margin' => $totalSales > 0 ? round($grossProfit / $totalSales * 100, 2) : 0.0,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Staff, categories and products
    |--------------------------------------------------------------------------
    */

    /**
     * Who took what, and who gave what away.
     *
     * The discount column is the reason this report exists as much as the takings
     * one: a cashier knocking something off every sale is invisible everywhere
     * else in the app.
     */
    public function byCashier(Request $request)
    {
        [$start, $end] = $this->dateRange($request, 'start_date', 'end_date', 'cashiers');

        $rows = $this->reports->cashierPerformance($start, $end);

        $totals = [
            'takings' => round((float) $rows->sum('takings'), 2),
            'gross' => round((float) $rows->sum('gross'), 2),
            'refunded' => round((float) $rows->sum('refunded'), 2),
            'transactions' => (int) $rows->sum('transactions'),
            'voids' => (int) $rows->sum('voids'),
            'discounts' => round((float) $rows->sum('discounts'), 2),
            'refunds_count' => (int) $rows->sum('refunds_count'),
            'refunds_value' => round((float) $rows->sum('refunds_value'), 2),
        ];

        $totals['basket'] = $totals['transactions'] > 0
            ? round($totals['takings'] / $totals['transactions'], 2)
            : 0.0;

        if ($this->wantsCsv($request)) {
            return $this->csv('takings_by_' . strtolower(term('cashier')) . '.csv',
                [term('cashier'), 'Role', 'Takings', 'Refunds on own sales', 'Transactions', 'Average basket', 'Discounts given', 'Discount rate %', 'Voids', 'Refunds processed', 'Refunds processed value'],
                function () use ($rows) {
                    foreach ($rows as $row) {
                        yield [
                            $row['name'], $row['role'], $row['takings'], $row['refunded'],
                            $row['transactions'], $row['basket'], $row['discounts'],
                            $row['discount_rate'], $row['voids'], $row['refunds_count'], $row['refunds_value'],
                        ];
                    }
                });
        }

        return view('reports.cashiers', [
            'rows' => $rows,
            'totals' => $totals,
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
        ]);
    }

    /** Revenue, cost, gross profit and margin per category. */
    public function byCategory(Request $request)
    {
        [$start, $end] = $this->dateRange($request, 'start_date', 'end_date', 'categories');

        $rows = $this->reports->categoryPerformance($start, $end);
        $totals = $this->performanceTotals($rows);

        if ($this->wantsCsv($request)) {
            return $this->csv('margin_by_' . strtolower(term('category')) . '.csv',
                [term('category'), term('product', true), 'Quantity sold', 'Revenue', 'Cost of goods sold', 'Gross profit', 'Margin %'],
                function () use ($rows) {
                    foreach ($rows as $row) {
                        yield [
                            $row['category'], $row['products'], $row['quantity'],
                            $row['revenue'], $row['cogs'], $row['gross_profit'], $row['margin'],
                        ];
                    }
                });
        }

        return view('reports.categories', [
            'rows' => $rows,
            'totals' => $totals,
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
        ]);
    }

    /**
     * Margin per product - the report the cost snapshot made possible.
     *
     * Before `sale_items.cost_price` existed there was no honest way to say what
     * a past sale earned, because the product's cost had moved since.
     */
    public function byProduct(Request $request)
    {
        [$start, $end] = $this->dateRange($request, 'start_date', 'end_date', 'products');

        $request->validate([
            'sort' => 'nullable|in:' . implode(',', array_keys(self::PRODUCT_SORTS)),
            'dir' => 'nullable|in:asc,desc',
        ]);

        $sort = $this->remembered($request, 'sort', array_keys(self::PRODUCT_SORTS), 'revenue', 'product_sort');
        $dir = $request->input('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $rows = $this->reports->productPerformance($start, $end);

        $rows = $dir === 'asc'
            ? $rows->sortBy($sort, SORT_NATURAL | SORT_FLAG_CASE)->values()
            : $rows->sortByDesc($sort, SORT_NATURAL | SORT_FLAG_CASE)->values();

        $totals = $this->performanceTotals($rows);

        if ($this->wantsCsv($request)) {
            return $this->csv('margin_by_' . strtolower(term('product')) . '.csv',
                [term('product'), 'SKU', term('category'), 'Quantity sold', 'Revenue', 'Cost of goods sold', 'Gross profit', 'Margin %'],
                function () use ($rows) {
                    foreach ($rows as $row) {
                        yield [
                            $row['name'], $row['sku'], $row['category'], $row['quantity'],
                            $row['revenue'], $row['cogs'], $row['gross_profit'], $row['margin'],
                        ];
                    }
                });
        }

        return view('reports.products', [
            'rows' => $rows,
            'totals' => $totals,
            'sorts' => self::PRODUCT_SORTS,
            'sort' => $sort,
            'dir' => $dir,
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
        ]);
    }

    /**
     * Totals for a performance table, with the margin recomputed from the totals.
     *
     * Averaging the per-row margins would weight a one-unit line the same as a
     * thousand-unit one; the margin of the whole is the only honest figure.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     */
    private function performanceTotals($rows): array
    {
        $revenue = round((float) $rows->sum('revenue'), 2);
        $cogs = round((float) $rows->sum('cogs'), 2);

        return [
            'rows' => $rows->count(),
            'quantity' => (int) $rows->sum('quantity'),
            'revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => round($revenue - $cogs, 2),
            'margin' => $revenue > 0 ? round(($revenue - $cogs) / $revenue * 100, 2) : 0.0,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Stock value and expiry
    |--------------------------------------------------------------------------
    */

    /**
     * What the stock on the shelves is worth, at cost and at retail.
     *
     * There is no date range: stock on hand is a figure about now, and pretending
     * otherwise would need a movement history replay this app does not keep.
     */
    public function inventoryValuation(Request $request)
    {
        $request->validate([
            'category_id' => 'nullable|integer|exists:categories,id',
            'search' => 'nullable|string|max:100',
            'export' => 'nullable|in:csv',
        ]);

        /*
         * A trade that keeps no stock has nothing to value, and the sidebar hides
         * this screen for it. An explanation rather than a 404: the setting can be
         * switched back, and the link may still be bookmarked.
         */
        if (!feature('stock')) {
            return view('reports.valuation', ['relevant' => false]);
        }

        $categoryId = $this->remembered($request, 'category_id', null, null, 'valuation_category');
        $search = $request->input('search');

        $valuation = $this->reports->valuation();

        // The detail list is what you print to walk the shelves with, so it is
        // filterable even though the summary above it always covers the whole shop.
        $detail = Product::query()
            ->stocked()
            ->with('category')
            ->when($categoryId, fn($q) => $q->where('category_id', $categoryId))
            ->when($search, fn($q) => $q->where(fn($inner) => $inner
                ->where('name', 'like', '%' . $search . '%')
                ->orWhere('sku', 'like', '%' . $search . '%')))
            ->orderByRaw('stock_quantity * cost_price DESC')
            ->paginate(50)
            ->withQueryString();

        if ($this->wantsCsv($request)) {
            $query = Product::query()
                ->stocked()
                ->with('category')
                ->when($categoryId, fn($q) => $q->where('category_id', $categoryId))
                ->orderBy('id');

            return $this->csv('stock_valuation.csv',
                [term('product'), 'SKU', term('category'), 'In stock', 'Unit cost', 'Unit price', 'Value at cost', 'Value at retail', 'Potential profit'],
                function () use ($query) {
                    foreach ($query->lazy(500) as $product) {
                        $cost = round((float) $product->cost_price * (int) $product->stock_quantity, 2);
                        $retail = round((float) $product->sale_price * (int) $product->stock_quantity, 2);

                        yield [
                            $product->name,
                            $product->sku,
                            $product->category->name ?? '',
                            (int) $product->stock_quantity,
                            (float) $product->cost_price,
                            (float) $product->sale_price,
                            $cost,
                            $retail,
                            round($retail - $cost, 2),
                        ];
                    }
                });
        }

        return view('reports.valuation', [
            'relevant' => true,
            'rows' => $valuation['rows'],
            'totals' => $valuation['totals'],
            'detail' => $detail,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'categoryId' => $categoryId,
            'search' => $search,
        ]);
    }

    /**
     * Stock that has gone off, or is about to, with the money at risk.
     *
     * Only meaningful where the trade tracks batches and expiry dates. A shop
     * that does not gets a plain explanation rather than a 404: the link may well
     * still be in someone's bookmarks from when the feature was on.
     */
    public function expiry(Request $request)
    {
        $request->validate([
            'days' => 'nullable|integer|min:1|max:365',
            'export' => 'nullable|in:csv',
        ]);

        if (!feature('expiry')) {
            return view('reports.expiry', ['relevant' => false]);
        }

        // A blank "days" clears the remembered value, so fall back rather than
        // looking zero days ahead.
        $days = max(1, (int) $this->remembered($request, 'days', null, 30, 'expiry_days'));
        $report = $this->reports->expiring($days);

        if ($this->wantsCsv($request)) {
            return $this->csv('expiring_stock.csv',
                [term('product'), 'SKU', 'Batch', 'Expires', 'Days left', 'In stock', 'Unit cost', 'Value at risk'],
                function () use ($report) {
                    foreach ($report['rows'] as $product) {
                        yield [
                            $product->name,
                            $product->sku,
                            $product->batch_no,
                            $product->expiry_date?->toDateString(),
                            (int) today()->diffInDays($product->expiry_date, false),
                            (int) $product->stock_quantity,
                            (float) $product->cost_price,
                            $product->stock_value,
                        ];
                    }
                });
        }

        return view('reports.expiry', [
            'relevant' => true,
            'rows' => $report['rows'],
            'buckets' => $report['buckets'],
            'totals' => $report['totals'],
            'days' => $days,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | What is owed, in both directions
    |--------------------------------------------------------------------------
    */

    /** Who owes the shop money, and how long they have owed it. */
    public function receivables(Request $request, CreditService $credit)
    {
        $request->validate([
            'overdue_only' => 'nullable|in:0,1',
            'export' => 'nullable|in:csv',
        ]);

        $overdueOnly = (string) $this->remembered($request, 'overdue_only', ['0', '1'], '0', 'receivables_overdue') === '1';

        $report = $this->reports->receivables($credit);
        $rows = $overdueOnly ? $report['rows']->where('overdue', true)->values() : $report['rows'];

        if ($this->wantsCsv($request)) {
            return $this->csv('receivables.csv',
                [term('customer'), 'Phone', 'Owed', 'Not yet due', '1-30 days', '31-60 days', 'Over 60 days', 'Overdue', 'Credit limit'],
                function () use ($rows) {
                    foreach ($rows as $row) {
                        yield [
                            $row['customer']->name,
                            $row['customer']->phone,
                            $row['owed'],
                            $row['buckets']['current'],
                            $row['buckets']['1_30'],
                            $row['buckets']['31_60'],
                            $row['buckets']['over_60'],
                            $row['overdue'] ? 'yes' : 'no',
                            (float) $row['customer']->credit_limit,
                        ];
                    }
                });
        }

        return view('reports.receivables', [
            'rows' => $rows,
            'totals' => $report['totals'],
            'buckets' => ReportService::AGEING,
            'overdueOnly' => $overdueOnly,
        ]);
    }

    /** The same, the other way round: what the shop owes its suppliers. */
    public function payables(Request $request, SupplierLedgerService $ledger)
    {
        $request->validate(['export' => 'nullable|in:csv']);

        $report = $this->reports->payables($ledger);

        if ($this->wantsCsv($request)) {
            return $this->csv('payables.csv',
                [term('supplier'), 'Phone', 'Balance owed', 'On order (not delivered)', 'Not yet due', '1-30 days', '31-60 days', 'Over 60 days'],
                function () use ($report) {
                    foreach ($report['rows'] as $row) {
                        yield [
                            $row['supplier']->name,
                            $row['supplier']->phone,
                            $row['balance'],
                            $row['on_order'],
                            $row['buckets']['current'],
                            $row['buckets']['1_30'],
                            $row['buckets']['31_60'],
                            $row['buckets']['over_60'],
                        ];
                    }
                });
        }

        return view('reports.payables', [
            'rows' => $report['rows'],
            'totals' => $report['totals'],
            'buckets' => ReportService::AGEING,
        ]);
    }
}
