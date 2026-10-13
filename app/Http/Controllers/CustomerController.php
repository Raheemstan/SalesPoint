<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Services\CreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Customers and what they owe.
 *
 * Balances are never stored on the customer row: they are the sum of the
 * `balance_due` left on that customer's sales, read through
 * Customer::withOutstanding() so a page of 20 customers costs one query rather
 * than 21.
 */
class CustomerController extends Controller
{
    private const SORTS = ['name', 'balance', 'created_at'];

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Customer::class);

        $request->validate([
            'search' => 'nullable|string|max:100',
            'filter' => 'nullable|in:balance,overdue,inactive',
            'sort' => 'nullable|in:' . implode(',', self::SORTS),
            'direction' => 'nullable|in:asc,desc',
        ]);

        $sort = $request->input('sort', 'name');
        $direction = $request->input('direction', $sort === 'name' ? 'asc' : 'desc');

        $customers = Customer::query()
            ->withOutstanding()
            ->when($request->filled('search'), function ($query) use ($request) {
                // The wildcards are escaped so a search for "50%" is not a search for everything.
                $like = '%' . addcslashes(trim($request->search), '%_\\') . '%';

                $query->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->when($request->filter === 'balance', fn($query) => $query->withBalance())
            ->when($request->filter === 'overdue', fn($query) => $query->overdue())
            ->when($request->filter === 'inactive', fn($query) => $query->where('is_active', false))
            ->orderBy($sort === 'balance' ? 'outstanding_total' : $sort, $direction)
            ->orderBy('customers.id')                       // a stable tiebreak, so paging cannot repeat a row
            ->paginate(20)
            ->withQueryString();

        $owing = Sale::outstanding()->whereNotNull('customer_id');

        $summary = [
            'customers' => Customer::active()->count(),
            'owed' => round((float) (clone $owing)->sum('balance_due'), 2),
            'overdue' => round((float) (clone $owing)
                ->whereNotNull('due_date')->whereDate('due_date', '<', today())
                ->sum('balance_due'), 2),
            'accounts' => Customer::withBalance()->count(),
        ];

        return view('customers.index', compact('customers', 'summary', 'sort', 'direction'));
    }

    /**
     * Customer lookup for the till's picker (JSON).
     *
     * Open to cashiers - a credit sale cannot be rung up without choosing a
     * customer - so it returns only the fields the till needs, never notes or
     * addresses, and never more rows than a dropdown can show.
     */
    public function search(Request $request, CreditService $credit)
    {
        Gate::authorize('search', Customer::class);

        $search = trim((string) $request->query('q', ''));

        $customers = Customer::query()
            ->active()
            ->withOutstanding()
            ->when($search !== '', function ($query) use ($search) {
                $like = '%' . addcslashes($search, '%_\\') . '%';

                $query->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)->orWhere('phone', 'like', $like);
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get();

        return response()->json($customers->map(fn(Customer $customer) => [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'balance' => $customer->outstanding,
            'credit_limit' => round((float) $customer->credit_limit, 2),
            // null = no limit set, so the till may always offer credit.
            'credit_available' => $credit->remainingCredit($customer),
        ]));
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Customer::class);

        // A new customer is active unless the form says otherwise.
        $customer = Customer::create($this->validated($request, true));

        AuditLog::record('Created customer ' . $customer->name, 'customers', $customer->id);

        return redirect()->route('customers.show', $customer)
            ->with('success', $customer->name . ' added.');
    }

    /** The statement: contact details, credit limit, and every invoice and payment in order. */
    public function show(Customer $customer)
    {
        Gate::authorize('view', $customer);

        // withSum: how much of each invoice has been settled by later payments, so
        // the statement can show what originally went on account without a query per row.
        $sales = $customer->sales()
            ->with('user')
            ->withSum('creditAllocations as credit_paid', 'amount')
            ->where('status', '!=', Sale::STATUS_VOIDED)
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $payments = $customer->payments()
            ->with(['user', 'allocations.sale'])
            ->orderBy('paid_at')->orderBy('id')
            ->get();

        return view('customers.show', [
            'customer' => $customer,
            'ledger' => $this->ledger($sales, $payments),
            'outstandingSales' => $sales->filter(fn(Sale $sale) => $sale->outstanding > 0)->values(),
            'balance' => round((float) $sales->sum('balance_due'), 2),
            'paymentMethods' => CustomerPayment::METHODS,
        ]);
    }

    public function update(Request $request, Customer $customer)
    {
        Gate::authorize('update', $customer);

        $customer->update($this->validated($request, $customer->is_active));

        AuditLog::record('Updated customer ' . $customer->name, 'customers', $customer->id);

        return back()->with('success', $customer->name . ' updated.');
    }

    public function destroy(Customer $customer)
    {
        Gate::authorize('delete', $customer);

        // Deleting someone who has traded would orphan their invoices and their
        // ledger, and lose the record of a debt. Deactivating keeps the history
        // and takes them out of the till's picker, which is what is really wanted.
        if (!$customer->isDeletable()) {
            $owed = $customer->outstanding;

            return back()->with('error', sprintf(
                '%s has trading history%s and cannot be deleted. Mark them inactive instead.',
                $customer->name,
                $owed > 0 ? ' and owes ' . money($owed) : ''
            ));
        }

        DB::transaction(function () use ($customer) {
            AuditLog::record('Deleted customer ' . $customer->name, 'customers', $customer->id);
            $customer->delete();
        });

        return redirect()->route('customers.index')->with('success', $customer->name . ' deleted.');
    }

    /**
     * @param  bool  $activeByDefault  what "is_active" means when the request does not mention it
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $activeByDefault): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            // Nullable on purpose: a walk-in buying on account often gives only a name.
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            // 0 means no limit.
            'credit_limit' => 'nullable|numeric|min:0|max:99999999999999',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        // A blank limit is "no limit", not null: the column is NOT NULL.
        $data['credit_limit'] = round((float) ($data['credit_limit'] ?? 0), 2);

        // The form always posts is_active (a hidden 0 sits in front of the checkbox),
        // so an unticked box really does deactivate. A request that leaves the field
        // out entirely is not trying to change it.
        $data['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : $activeByDefault;

        return $data;
    }

    /**
     * One chronological ledger of invoices and payments with a running balance.
     *
     * An invoice is debited with what actually went on account - the balance still
     * owed plus whatever later payments settled - so a sale paid in full at the
     * till appears for completeness but moves the balance by nothing, and the
     * closing balance always equals what the customer owes today.
     *
     * @param  \Illuminate\Support\Collection<int, Sale>  $sales
     * @param  \Illuminate\Support\Collection<int, CustomerPayment>  $payments
     * @return array<int, array<string, mixed>>
     */
    private function ledger($sales, $payments): array
    {
        $rows = [];

        foreach ($sales as $sale) {
            $rows[] = [
                'at' => $sale->created_at,
                'kind' => 'invoice',
                'order' => 0,                       // an invoice comes before a payment made the same second
                'reference' => $sale->invoice_number,
                'detail' => trim(ucfirst((string) $sale->payment_method) . ' · ' . ($sale->user->name ?? '-')),
                'debit' => round((float) $sale->balance_due + (float) $sale->credit_paid, 2),
                'credit' => 0.0,
                'due_date' => $sale->due_date,
                'overdue' => $sale->isOverdue(),
                'model' => $sale,
            ];
        }

        foreach ($payments as $payment) {
            $settled = $payment->allocations
                ->map(fn($allocation) => ($allocation->sale->invoice_number ?? '?') . ' ' . money($allocation->amount))
                ->implode(', ');

            $rows[] = [
                'at' => $payment->paid_at ?? $payment->created_at,
                'kind' => 'payment',
                'order' => 1,
                'reference' => ucfirst($payment->method) . ($payment->reference ? ' · ' . $payment->reference : ''),
                'detail' => trim(($payment->user->name ?? '-') . ($settled ? ' · ' . $settled : '')),
                'debit' => 0.0,
                'credit' => round((float) $payment->amount, 2),
                'due_date' => null,
                'overdue' => false,
                'model' => $payment,
            ];
        }

        usort($rows, fn($a, $b) => [$a['at'], $a['order']] <=> [$b['at'], $b['order']]);

        $balance = 0.0;

        foreach ($rows as $i => $row) {
            $balance = round($balance + $row['debit'] - $row['credit'], 2);
            $rows[$i]['balance'] = $balance;
        }

        return $rows;
    }
}
