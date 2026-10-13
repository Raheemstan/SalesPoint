<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\User;
use App\Services\RefundService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SaleController extends Controller
{
    private const METHODS = 'cash,pos,transfer';

    public function index(Request $request)
    {
        $manager = $request->user()->hasAnyRole(['admin', 'manager']);

        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'status' => 'nullable|in:completed,partially_refunded,refunded,voided',
            'payment_method' => 'nullable|in:' . self::METHODS,
            'cashier' => 'nullable|integer',
        ]);

        $query = Sale::with(['user'])->latest();

        if ($manager) {
            $from = $request->filled('from') ? Carbon::parse($request->from)->startOfDay() : now()->subDays(6)->startOfDay();
            $to = $request->filled('to') ? Carbon::parse($request->to)->endOfDay() : now()->endOfDay();

            $query->whereBetween('created_at', [$from, $to])
                ->when($request->filled('cashier'), fn($q) => $q->where('user_id', $request->cashier));
        } else {
            // Cashiers: their own sales today, nothing else.
            $from = now()->startOfDay();
            $to = now()->endOfDay();
            $query->whereBetween('created_at', [$from, $to])->where('user_id', $request->user()->id);
        }

        $query
            ->when($request->filled('search'), fn($q) => $q->where('invoice_number', 'like', '%' . $request->search . '%'))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('payment_method'), fn($q) => $q->where('payment_method', $request->payment_method));

        $summary = [
            'count' => (clone $query)->where('status', '!=', Sale::STATUS_VOIDED)->count(),
            'total' => (clone $query)->sum('grand_total'),
            'refunded' => (clone $query)->sum('refunded_amount'),
        ];

        $sales = $query->paginate(20)->withQueryString();
        $cashiers = $manager ? User::orderBy('name')->get(['id', 'name']) : collect();
        $from = $from->toDateString();
        $to = $to->toDateString();

        return view('sales.index', compact('sales', 'summary', 'cashiers', 'manager', 'from', 'to'));
    }

    public function show(Sale $sale)
    {
        Gate::authorize('view', $sale);

        $sale->load(['user', 'voider', 'items.product', 'refunds.user', 'refunds.items.product']);

        return view('sales.show', compact('sale'));
    }

    public function refund(Request $request, Sale $sale, RefundService $refunds)
    {
        Gate::authorize('refund', $sale);

        $data = $request->validate([
            'items' => 'required|array',
            'items.*.quantity' => 'nullable|integer|min:0',
            'items.*.restock' => 'nullable|boolean',
            'reason' => 'required|string|min:3|max:255',
            'method' => 'required|in:' . self::METHODS,
        ]);

        $quantities = [];
        $restock = [];
        foreach ($data['items'] as $itemId => $row) {
            $quantities[(int) $itemId] = (int) ($row['quantity'] ?? 0);
            // an unticked checkbox is simply absent, so default is "no restock" only when a row was posted
            $restock[(int) $itemId] = (bool) ($row['restock'] ?? false);
        }

        try {
            $refund = $refunds->refund($sale, $quantities, $restock, $data['reason'], $data['method'], $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.show', $sale)
            ->with('success', 'Refund of ' . money($refund->total_amount) . ' recorded.');
    }

    public function void(Request $request, Sale $sale, RefundService $refunds)
    {
        Gate::authorize('void', $sale);

        $data = $request->validate([
            'reason' => 'required|string|min:3|max:255',
            'method' => 'required|in:' . self::METHODS,
        ]);

        try {
            $refunds->void($sale, $data['reason'], $data['method'], $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('sales.show', $sale)->with('success', 'Sale voided and stock restored.');
    }
}
