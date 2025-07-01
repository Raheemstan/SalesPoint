<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());

        $sales = Sale::whereBetween('created_at', [$startDate, $endDate])
                    ->with('items.product')
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
}
