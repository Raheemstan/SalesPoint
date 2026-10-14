@extends('layouts.app')

@section('title', 'Expenses')

@php
    /*
    |--------------------------------------------------------------------------
    | What the shop spends
    |--------------------------------------------------------------------------
    |
    | Rent, fuel, wages, anything that is not stock. The screen is a filter row,
    | a list and one modal that does both add and edit - the modal is driven by
    | the plain script at the bottom rather than Alpine, because the edit button
    | hands it the row as JSON (data-json) and the ids below are what that
    | script holds on to. Those ids and the field names are load-bearing; only
    | the styling has changed.
    |
    | Every figure goes through money(), so the symbol is the one configured in
    | Settings and never a hardcoded one.
    */
    $categories = ['Utilities', 'Office Supplies', 'Travel', 'Food', 'Other'];

    // A stable hue per category, so the same kind of spend looks the same
    // every time the list is read. Nominal, not ranked.
    $categoryVariant = [
        'Utilities' => 'blue',
        'Office Supplies' => 'indigo',
        'Travel' => 'teal',
        'Food' => 'amber',
        'Other' => 'gray',
    ];

    // Honest about its scope: the controller paginates, so this is the page.
    $pageTotal = $expenses->sum('amount');

    $btnPrimary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700';
    $btnSecondary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700';

    $filterLabel = 'mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $control = 'w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
@endphp

@section('content')
    <x-page-header title="Expenses" subtitle="Everything the shop spends outside stock purchases">
        <x-slot:actions>
            <button type="button" id="add-expense-btn" class="{{ $btnPrimary }}">
                <x-icon name="plus" size="4" />
                Add expense
            </button>
        </x-slot:actions>
    </x-page-header>

    {{-- One filter row, above the list it scopes. --}}
    <form method="GET"
          class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-full sm:w-auto sm:min-w-56">
                <label for="filter-search" class="{{ $filterLabel }}">Search</label>
                <input type="search" id="filter-search" name="search" value="{{ request('search') }}"
                       placeholder="Name or description" class="{{ $control }}">
            </div>

            <div class="w-full sm:w-auto sm:min-w-44">
                <label for="filter-category" class="{{ $filterLabel }}">Category</label>
                <select id="filter-category" name="category" class="{{ $control }}">
                    <option value="">All categories</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat }}" @selected(request('category') === $cat)>{{ $cat }}</option>
                    @endforeach
                </select>
            </div>

            <div class="w-full sm:w-auto">
                <label for="filter-date-from" class="{{ $filterLabel }}">From</label>
                <input type="date" id="filter-date-from" name="date_from" value="{{ request('date_from') }}"
                       class="{{ $control }}">
            </div>

            <div class="w-full sm:w-auto">
                <label for="filter-date-to" class="{{ $filterLabel }}">To</label>
                <input type="date" id="filter-date-to" name="date_to" value="{{ request('date_to') }}"
                       class="{{ $control }}">
            </div>

            <div class="flex min-h-touch flex-wrap items-center gap-2">
                <button type="submit" class="{{ $btnPrimary }}">
                    <x-icon name="search" size="4" />
                    Apply
                </button>

                <a href="{{ route('expenses.index') }}"
                   class="inline-flex min-h-touch items-center rounded-md px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                    Reset
                </a>
            </div>
        </div>
    </form>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-stat label="Expenses recorded" :value="number_format($expenses->total())" icon="sliders"
                :hint="request()->hasAny(['search', 'category', 'date_from', 'date_to'])
                    ? 'matching these filters'
                    : 'all time'" />

        {{-- invert: on a spend figure a rise is the bad direction, not the good one. --}}
        <x-stat label="Spend on this page" :value="money($pageTotal)" icon="coins" invert
                :hint="$expenses->total() > $expenses->count()
                    ? 'of ' . number_format($expenses->total()) . ' matching rows'
                    : null" />
    </div>

    <x-card :padding="false" class="mt-4">
        <x-table>
            <x-slot:head>
                <tr>
                    <th>Name</th>
                    <th class="text-right">Amount</th>
                    <th>Date</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th class="text-right">Actions</th>
                </tr>
            </x-slot:head>

            @forelse($expenses as $expense)
                <tr>
                    <td class="font-medium">{{ $expense->name }}</td>
                    <td class="text-right tabular-nums">{{ money($expense->amount) }}</td>
                    <td class="whitespace-nowrap text-gray-500 dark:text-gray-400">
                        {{ \Carbon\Carbon::parse($expense->expense_date)->format('d M Y') }}
                    </td>
                    <td>
                        @if ($expense->category)
                            <x-badge :variant="$categoryVariant[$expense->category] ?? 'gray'" size="sm">
                                {{ $expense->category }}
                            </x-badge>
                        @else
                            <span class="text-gray-400 dark:text-gray-500">-</span>
                        @endif
                    </td>
                    {{-- A description can run to 1000 characters; one line of it is enough here. --}}
                    <td class="max-w-xs truncate text-gray-600 dark:text-gray-300"
                        title="{{ $expense->description }}">{{ $expense->description }}</td>
                    <td>
                        <div class="flex items-center justify-end gap-3">
                            <button type="button"
                                    class="edit-expense-btn inline-flex min-h-touch items-center font-medium text-primary-600 hover:underline dark:text-primary-400"
                                    data-json='@json($expense)'>
                                Edit
                            </button>

                            <form method="POST" action="{{ route('expenses.destroy', $expense) }}"
                                  onsubmit="return confirm('Are you sure you want to delete this expense?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="inline-flex min-h-touch items-center font-medium text-red-600 hover:underline dark:text-red-400">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-empty icon="coins" title="No expenses found"
                                 :message="request()->hasAny(['search', 'category', 'date_from', 'date_to'])
                                    ? 'Nothing matches these filters. Widen the dates, or clear them.'
                                    : 'Rent, fuel, wages and anything else that is not stock belongs here.'" />
                    </td>
                </tr>
            @endforelse

            @if ($expenses->isNotEmpty())
                <x-slot:foot>
                    <tr>
                        <td>Total on this page</td>
                        <td class="text-right tabular-nums">{{ money($pageTotal) }}</td>
                        <td colspan="4"></td>
                    </tr>
                </x-slot:foot>
            @endif
        </x-table>

        @if ($expenses->hasPages())
            <x-slot:footer>{{ $expenses->withQueryString()->links() }}</x-slot:footer>
        @endif
    </x-card>

    {{--
        Add / edit. Opened and populated by the script below, which is why the
        ids stay exactly as they were; `hidden` wins over `flex` in Tailwind's
        display group, so toggling that one class is what shows and hides it.
    --}}
    <div id="expense-modal" role="dialog" aria-modal="true" aria-labelledby="modal-title"
         class="fixed inset-0 z-modal hidden items-center justify-center bg-gray-900/50 p-4 flex">
        <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-5 shadow-xl ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h2 id="modal-title" class="mb-4 text-base font-semibold text-gray-900 dark:text-gray-100">
                Add Expense
            </h2>

            <form id="expense-form" method="POST" action="{{ route('expenses.store') }}">
                @csrf
                <input type="hidden" name="_method" id="form-method" value="POST">
                <input type="hidden" name="expense_id" id="expense-id">

                <x-input name="name" label="Name" />
                <x-input name="amount" label="Amount ({{ currency() }})" type="number" step="0.01" />
                <x-input name="expense_date" label="Date" type="date" />
                <x-select name="category" label="Category" :options="array_combine($categories, $categories)" />
                <x-textarea name="description" label="Description" rows="3" />

                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <button type="button" id="cancel-modal-btn" class="{{ $btnSecondary }}">
                        Cancel
                    </button>
                    <button type="submit" class="{{ $btnPrimary }}">
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

        const openModal = () => {
            modal.classList.remove('hidden');
            form.name.focus();
        };

        const closeModal = () => modal.classList.add('hidden');

        // Open modal to add
        addBtn.addEventListener('click', () => {
            form.reset();
            form.action = '{{ route('expenses.store') }}';
            methodInput.value = 'POST';
            modalTitle.textContent = 'Add Expense';
            openModal();
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
                openModal();
            });
        });

        // Close modal: the Cancel button, the backdrop, or Escape.
        cancelBtn.addEventListener('click', closeModal);

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });
    </script>
@endsection
