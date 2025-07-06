@extends('layouts.app')

@section('title', 'Expense Tracking')

@section('content')
    <div class="bg-white dark:bg-gray-800 shadow rounded p-4">
        {{-- Header --}}
        <div class="flex flex-col md:flex-row justify-between md:items-center mb-4 gap-4">
            <form method="GET" class="flex flex-wrap items-center gap-2 text-sm">
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search..."
                    class="border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-800 dark:text-gray-200 rounded px-2 py-1" />

                <select name="category"
                    class="border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-800 dark:text-gray-200 rounded px-2 py-1">
                    <option value="">All Categories</option>
                    @foreach(['Utilities', 'Office Supplies', 'Travel', 'Food', 'Other'] as $cat)
                        <option value="{{ $cat }}" {{ request('category') === $cat ? 'selected' : '' }}>
                            {{ $cat }}
                        </option>
                    @endforeach
                </select>

                <input type="date" name="date_from" value="{{ request('date_from') }}"
                    class="border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-800 dark:text-gray-200 rounded px-2 py-1" />

                <input type="date" name="date_to" value="{{ request('date_to') }}"
                    class="border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-800 dark:text-gray-200 rounded px-2 py-1" />

                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1 rounded text-sm">
                    Filter
                </button>
            </form>

            <button id="add-expense-btn"
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm self-start md:self-auto">
                + Add Expense
            </button>
        </div>


        {{-- Expense Table --}}
        <table class="w-full table-auto text-sm text-left">
            <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                <tr>
                    <th class="px-3 py-2">Name</th>
                    <th class="px-3 py-2">Amount</th>
                    <th class="px-3 py-2">Date</th>
                    <th class="px-3 py-2">Category</th>
                    <th class="px-3 py-2">Description</th>
                    <th class="px-3 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($expenses as $expense)
                    <tr class="border-t dark:border-gray-700">
                        <td class="px-3 py-2">{{ $expense->name }}</td>
                        <td class="px-3 py-2">₦{{ number_format($expense->amount, 2) }}</td>
                        <td class="px-3 py-2">{{ \Carbon\Carbon::parse($expense->expense_date)->format('d M Y') }}</td>
                        <td class="px-3 py-2">{{ $expense->category }}</td>
                        <td class="px-3 py-2">{{ $expense->description }}</td>
                        <td class="px-3 py-2 space-x-2">
                            <button type="button" class="edit-expense-btn text-blue-600 hover:underline"
                                data-json='@json($expense)'>
                                Edit
                            </button>

                            <form method="POST" action="{{ route('expenses.destroy', $expense) }}" class="inline-block"
                                onsubmit="return confirm('Are you sure you want to delete this expense?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-gray-500 py-4">No expenses found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- Pagination --}}
        <div class="mt-4">
            {{ $expenses->withQueryString()->links() }}
        </div>
    </div>

    {{-- Modal --}}
    <div id="expense-modal" class="fixed inset-0 z-50 hidden bg-black bg-opacity-50 flex items-center justify-center">
        <div class="bg-white dark:bg-gray-800 w-full max-w-lg p-6 rounded shadow-lg relative">
            <h3 id="modal-title" class="text-lg font-semibold mb-4">Add Expense</h3>
            <form id="expense-form" method="POST" action="{{ route('expenses.store') }}">
                @csrf
                <input type="hidden" name="_method" id="form-method" value="POST">
                <input type="hidden" name="expense_id" id="expense-id">

                <x-input name="name" label="Name" />
                <x-input name="amount" label="Amount" type="number" step="0.01" />
                <x-input name="expense_date" label="Date" type="date" />
                <x-select name="category" label="Category" :options="['Utilities' => 'Utilities', 'Office Supplies' => 'Office Supplies', 'Travel' => 'Travel', 'Food' => 'Food', 'Other' => 'Other']" />
                <x-textarea name="description" label="Description" />

                <div class="mt-4 flex justify-end space-x-2">
                    <button type="button" id="cancel-modal-btn"
                        class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded">
                        Save Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        const modal = document.getElementById('expense-modal');
        const addBtn = document.getElementById('add-expense-btn');
        const cancelBtn = document.getElementById('cancel-modal-btn');
        const form = document.getElementById('expense-form');
        const methodInput = document.getElementById('form-method');
        const idInput = document.getElementById('expense-id');
        const modalTitle = document.getElementById('modal-title');

        // Open modal to add
        addBtn.addEventListener('click', () => {
            form.reset();
            form.action = '{{ route('expenses.store') }}';
            methodInput.value = 'POST';
            modalTitle.textContent = 'Add Expense';
            modal.classList.remove('hidden');
        });

        // Open modal to edit
        document.querySelectorAll('.edit-expense-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const data = JSON.parse(btn.dataset.json);
                form.action = `/expenses/${data.id}`;
                methodInput.value = 'PUT';
                idInput.value = data.id;
                form.name.value = data.name;
                form.amount.value = data.amount;
                form.expense_date.value = data.expense_date;
                form.category.value = data.category || '';
                form.description.value = data.description || '';
                modalTitle.textContent = 'Edit Expense';
                modal.classList.remove('hidden');
            });
        });

        // Close modal
        cancelBtn.addEventListener('click', () => {
            modal.classList.add('hidden');
        });
    </script>
@endsection