<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ExpenseController extends Controller
{
    // List expenses with filtering and search
    public function index(Request $request)
    {
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

        $expenses = $query->latest()->paginate(10);

        return view('expenses.index', compact('expenses'));
    }

    // Store new expense
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'amount'       => 'required|numeric|min:0',
            'expense_date' => 'required|date',
            'category'     => 'nullable|string|max:100',
            'description'  => 'nullable|string|max:1000',
        ]);
        try {
            DB::transaction(function () use ($request, $validated) {
                $userid = Auth::id();
                $expense =  Expense::create([
                    ...$validated,
                    'user_id'      => $userid,
                ]);

                AuditLog::create([
                    'user_id'    => $userid,
                    'action'     => 'Espense Created',
                    'table_name' => 'expenses',
                    'record_id' => $expense->id,
                    'ip_address' => $request->ip(),
                ]);
            });
            return redirect()->route('expenses.index')->with('success', 'Expense added successfully.');
        } catch (\Exception $th) {
            Log::error('Expense creation failed: ' . $th->getMessage());
            return redirect()->route('expenses.index')->with('error', 'Failed to add expense.');
        }
    }

    // Update existing expense
    public function update(Request $request, Expense $expense)
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:255',
            'amount'       => 'required|numeric|min:0',
            'expense_date' => 'required|date',
            'category'     => 'nullable|string|max:100',
            'description'  => 'nullable|string|max:1000',
        ]);

        try {
            DB::transaction(function () use ($request, $validated, $expense) {
                $original = $expense->getOriginal();
                $expense->update($validated);

                AuditLog::create([
                    'user_id'    => Auth::id(),
                    'action'     => 'Expense Updated',
                    'table_name' => 'expenses',
                    'record_id' => $expense->id,
                    'ip_address' => $request->ip(),
                ]);
                Log::info('Expense updated', [
                    'user_id' => Auth::id(),
                    'expense_id' => $expense->id,
                    'original' => $original,
                    'changes' => $validated,
                ]);

                return redirect()->route('expenses.index')->with('success', 'Expense updated successfully.');
            });
        } catch (\Exception $th) {
            Log::error('Expense update failed: ' . $th->getMessage());
            return redirect()->route('expenses.index')->with('error', 'Failed to update expense.');
        }
    }

    // Delete expense
    public function destroy(Expense $expense)
    {
        try {
            DB::transaction(function () use ($expense) {
                $original = $expense->getOriginal();
                Log::info('Expense deleted', [
                    'user_id' => Auth::id(),
                    'expense_id' => $expense->id,
                    'original' => $original,
                ]);
                AuditLog::create([
                    'user_id'    => Auth::id(),
                    'action'     => 'Expense Deleted',
                    'table_name' => 'expenses',
                    'record_id' => $expense->id,
                    'ip_address' => request()->ip(),
                ]);
                $expense->delete();
            });
            return redirect()->route('expenses.index')->with('success', 'Expense deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('expenses.index')->with('error', 'Failed to delete expense.');
        }
    }
}
