<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * The routes are behind `role:admin|manager`, and every action also asks
 * ExpensePolicy. The duplication is deliberate: the middleware guards the URL,
 * the policy guards the action, so a future route added outside that group
 * cannot quietly let a cashier sign money out of the business.
 */
class ExpenseController extends Controller
{
    // List expenses with filtering and search
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Expense::class);

        $query = Expense::query();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('expense_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('expense_date', '<=', $request->date_to);
        }

        $expenses = $query->latest()->paginate(10)->withQueryString();

        return view('expenses.index', compact('expenses'));
    }

    private function rules(): array
    {
        return [
            'name'         => 'required|string|max:255',
            'amount'       => 'required|numeric|min:0',
            'expense_date' => 'required|date',
            'category'     => 'nullable|string|max:100',
            'description'  => 'nullable|string|max:1000',
        ];
    }

    // Store new expense
    public function store(Request $request)
    {
        Gate::authorize('create', Expense::class);

        $validated = $request->validate($this->rules());

        try {
            DB::transaction(function () use ($validated) {
                $expense = Expense::create([...$validated, 'user_id' => userId()]);

                AuditLog::record('Expense Created', 'expenses', $expense->id);
            });
        } catch (\Throwable $e) {
            Log::error('Expense creation failed: ' . $e->getMessage());
            return redirect()->route('expenses.index')->with('error', 'Failed to add expense.');
        }

        return redirect()->route('expenses.index')->with('success', 'Expense added successfully.');
    }

    // Update existing expense
    public function update(Request $request, Expense $expense)
    {
        Gate::authorize('update', $expense);

        $validated = $request->validate($this->rules());

        try {
            DB::transaction(function () use ($validated, $expense) {
                $original = $expense->getOriginal();
                $expense->update($validated);

                AuditLog::record('Expense Updated', 'expenses', $expense->id);

                Log::info('Expense updated', [
                    'user_id' => userId(),
                    'expense_id' => $expense->id,
                    'original' => $original,
                    'changes' => $validated,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Expense update failed: ' . $e->getMessage());
            return redirect()->route('expenses.index')->with('error', 'Failed to update expense.');
        }

        // This used to be returned from *inside* the transaction closure and then
        // discarded, so a successful update answered with an empty 200 page.
        return redirect()->route('expenses.index')->with('success', 'Expense updated successfully.');
    }

    // Delete expense
    public function destroy(Expense $expense)
    {
        Gate::authorize('delete', $expense);

        try {
            DB::transaction(function () use ($expense) {
                Log::info('Expense deleted', [
                    'user_id' => userId(),
                    'expense_id' => $expense->id,
                    'original' => $expense->getOriginal(),
                ]);

                AuditLog::record('Expense Deleted', 'expenses', $expense->id);

                $expense->delete();
            });
        } catch (\Throwable $e) {
            Log::error('Expense delete failed: ' . $e->getMessage());
            return redirect()->route('expenses.index')->with('error', 'Failed to delete expense.');
        }

        return redirect()->route('expenses.index')->with('success', 'Expense deleted successfully.');
    }
}
