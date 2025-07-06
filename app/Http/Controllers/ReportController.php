<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());

        $sales = Sale::whereBetween('created_at', [$startDate, $endDate])
            ->with('items.product')
            ->when($request->payment_method, fn($q) => $q->where('payment_method', $request->payment_method))
            ->latest()
            ->get();

        $summary = [
            'total_sales' => $sales->sum('grand_total'),
            'total_tax' => $sales->sum('tax'),
            'total_discount' => $sales->sum('discount'),
            'total_transactions' => $sales->count(),
        ];

        return view('reports.sales', compact('sales', 'summary', 'startDate', 'endDate'));
    }


    public function dailySales()
    {
        $today = Sale::whereDate('created_at', today());
        $thisMonth = Sale::whereMonth('created_at', now()->month);

        $data = [
            'sales_today' => $today->sum('grand_total'),
            'sales_this_month' => $thisMonth->sum('grand_total'),
            'transactions_today' => $today->count(),
            'total_products' => Product::count(),
        ];

        return view('reports.sales_daily', compact('data'));
    }
    public function exportPDF(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());
        $paymentMethod = $request->get('payment_method');

        $sales = Sale::whereBetween('created_at', [$startDate, $endDate])
            ->when($paymentMethod, fn($q) => $q->where('payment_method', $paymentMethod))
            ->with('items.product')
            ->latest()
            ->get();

        $pdf = Pdf::loadView('reports.sales_pdf', compact('sales', 'startDate', 'endDate'));
        return $pdf->download('sales_report.pdf');
    }
    public function movement(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());

        $movements = Sale::whereBetween('created_at', [$startDate, $endDate])
            ->with('items.product')
            ->latest()
            ->get();

        return view('inventory.movement', compact('movements', 'startDate', 'endDate'));
    }

    public function exportCSV(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());
        $paymentMethod = $request->get('payment_method');

        $sales = Sale::whereBetween('created_at', [$startDate, $endDate])
            ->when($paymentMethod, fn($q) => $q->where('payment_method', $paymentMethod))
            ->with('items.product')
            ->latest()
            ->get();

        $filename = 'sales_report.csv';
        $headers = [
            "Content-type" => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
        ];

        $callback = function () use ($sales) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Date', 'Invoice ID', 'Items', 'Total', 'Tax', 'Discount', 'Payment Method']);

            foreach ($sales as $sale) {
                $items = $sale->items->map(fn($i) => "{$i->product->name} (x{$i->quantity})")->implode('; ');
                fputcsv($handle, [
                    $sale->created_at->format('d M Y'),
                    $sale->id,
                    $items,
                    $sale->grand_total,
                    $sale->tax,
                    $sale->discount,
                    ucfirst($sale->payment_method),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function profitLoss(Request $request)
    {
        $from = $request->get('from', Carbon::now()->startOfMonth()->toDateString());
        $to = $request->get('to', Carbon::now()->endOfMonth()->toDateString());

        $start = Carbon::parse($from);
        $end = Carbon::parse($to);

        $labels = [];
        $salesData = [];
        $purchasesData = [];
        $expensesData = [];
        $netProfitData = [];

        while ($start->lte($end)) {
            $date = $start->toDateString();
            $labels[] = $date;

            $sales = Sale::whereDate('created_at', $date)->sum('total_amount');
            $purchases = Purchase::whereDate('purchase_date', $date)->sum('total_amount');
            $expenses = Expense::whereDate('expense_date', $date)->sum('amount');
            $netProfit = $sales - ($purchases + $expenses);

            $salesData[] = $sales;
            $purchasesData[] = $purchases;
            $expensesData[] = $expenses;
            $netProfitData[] = $netProfit;

            $start->addDay();
        }
        $salesList = Sale::whereBetween('created_at', [$from, $to])->get();
        $purchaseList = Purchase::whereBetween('purchase_date', [$from, $to])->get();
        $expenseList = Expense::whereBetween('expense_date', [$from, $to])->get();

        $totalSales = array_sum($salesData);
        $totalPurchases = array_sum($purchasesData);
        $totalExpenses = array_sum($expensesData);
        $grossProfit = $totalSales - $totalPurchases;
        $netProfit = $grossProfit - $totalExpenses;

        return view('reports.profit_loss', [
            'from' => $from,
            'to' => $to,
            'labels' => $labels,
            'salesData' => $salesData,
            'purchasesData' => $purchasesData,
            'expensesData' => $expensesData,
            'netProfitData' => $netProfitData,
            'sales' => $totalSales,
            'purchases' => $totalPurchases,
            'expenses' => $totalExpenses,
            'grossProfit' => $grossProfit,
            'netProfit' => $netProfit,
            'salesList' => $salesList,
            'purchaseList' => $purchaseList,
            'expenseList' => $expenseList,
        ]);
    }
}
