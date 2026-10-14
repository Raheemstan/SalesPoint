@extends('layouts.app')
@section('title', $customer->name)

@php
    /*
    |--------------------------------------------------------------------------
    | One customer's statement
    |--------------------------------------------------------------------------
    |
    | Four tiles for the account, then every invoice and every payment in the
    | order they happened, with a running balance.
    |
    | Flash messages and validation errors come from the shell, so nothing is
    | printed for them here.
    */
    $noun = Str::lower(term('customer'));
    $limit = (float) $customer->credit_limit;
    $available = $customer->credit_available;   // null = no limit

    // money() puts the symbol first, so a bare negative would read "<symbol>-100".
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));

    $label = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
    $optional = 'font-normal text-gray-400 dark:text-gray-500';
    $field = 'block w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm
              focus:border-primary-500 focus:ring-primary-500
              dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';

    $contact = collect([$customer->phone, $customer->email, $customer->address])->filter()->implode(' · ');
@endphp

@section('content')
    {{-- A statement is something a shop hands over or posts, so it has to print
         without the sidebar, the filters and the buttons. --}}
    @verbatim
        <style>
            @media print {
                aside, header, .no-print { display: none !important; }
                main { overflow: visible !important; padding: 0 !important; }
                body { background: #fff !important; color: #000 !important; }
                .print-card { box-shadow: none !important; border: 0 !important; }
            }
        </style>
    @endverbatim

    <x-page-header :title="$customer->name"
                   :subtitle="$contact ?: 'No contact details'"
                   :back="route('customers.index')"
                   :back-label="term('customer', true)">
        <x-slot:actions>
            <div class="no-print flex flex-wrap items-center gap-2">
                @if ($balance > 0)
                    <button type="button" x-data x-on:click="$dispatch('open-modal', 'record-payment')"
                            class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                        <x-icon name="coins" size="4" />
                        Record payment
                    </button>
                @endif

                <button type="button" onclick="window.print()"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                    <x-icon name="receipt" size="4" />
                    Print
                </button>
            </div>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
        <span>{{ setting('store_name', 'SalesPoint') }} &middot; Statement to {{ now()->format('d M Y') }}</span>
        @unless ($customer->is_active)
            <x-badge variant="gray" size="sm">Inactive</x-badge>
        @endunless
    </div>

    {{-- The account --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat label="Credit limit" :value="$limit > 0 ? money($limit) : 'No limit'" icon="scale"
                :hint="$limit > 0 ? null : 'the till never refuses a sale'" />

        <x-stat label="Balance owing" :value="$amount($balance)" icon="coins"
                :trend="$balance > 0 ? 'up' : null" invert
                :hint="$balance > 0 ? 'still to collect' : 'nothing outstanding'" />

        <x-stat label="Overdue" :value="$amount($customer->overdue)" icon="alert-triangle"
                :trend="$customer->overdue > 0 ? 'up' : null" invert
                :hint="$customer->overdue > 0 ? 'past the agreed due date' : 'nothing past due'" />

        <x-stat label="Credit available"
                :value="$available === null ? 'Unlimited' : $amount($available)"
                icon="check-circle"
                :delta="$available !== null && $available < 0 ? 'over limit' : null"
                :trend="$available !== null && $available < 0 ? 'down' : null"
                :hint="$available === null ? 'no limit set' : null" />
    </div>

    @if ($customer->notes)
        <x-card title="Notes" class="print-card mt-4">
            <p class="whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ $customer->notes }}</p>
        </x-card>
    @endif

    {{-- The ledger: every invoice and every payment in the order they happened --}}
    <x-card title="Statement of account" :padding="false" class="print-card mt-4">
        <x-table>
            <x-slot:head>
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Detail</th>
                    <th class="text-right">On account</th>
                    <th class="text-right">Paid</th>
                    <th class="text-right">Balance</th>
                    <th class="no-print"><span class="sr-only">Actions</span></th>
                </tr>
            </x-slot:head>

            @forelse ($ledger as $row)
                <tr>
                    <td class="whitespace-nowrap tabular-nums">{{ $row['at']?->format('d M Y H:i') }}</td>
                    <td class="font-mono">
                        @if ($row['kind'] === 'invoice')
                            <a href="{{ route('sales.show', $row['model']) }}"
                               class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                {{ $row['reference'] }}
                            </a>
                        @else
                            {{ $row['reference'] }}
                        @endif
                    </td>
                    <td class="text-gray-500 dark:text-gray-400">
                        {{ $row['detail'] }}
                        @if ($row['kind'] === 'invoice' && $row['debit'] <= 0)
                            <span class="text-xs">&middot; paid at till</span>
                        @endif
                        @if ($row['due_date'])
                            <span class="text-xs {{ $row['overdue'] ? 'font-medium text-red-700 dark:text-red-400' : '' }}">
                                &middot; due {{ $row['due_date']->format('d M Y') }}{{ $row['overdue'] ? ' (overdue)' : '' }}
                            </span>
                        @endif
                    </td>
                    <td class="text-right tabular-nums">{{ $row['debit'] > 0 ? money($row['debit']) : '—' }}</td>
                    <td class="text-right tabular-nums text-green-700 dark:text-green-400">
                        {{ $row['credit'] > 0 ? money($row['credit']) : '—' }}
                    </td>
                    <td class="text-right font-semibold tabular-nums">{{ money($row['balance']) }}</td>
                    <td class="no-print text-right">
                        @if ($row['kind'] === 'payment' && auth()->user()->can('delete', $row['model']))
                            <form method="POST" action="{{ route('customers.payments.destroy', $row['model']) }}"
                                  onsubmit="return confirm('Reverse this payment? Every invoice it settled goes back to what it owed before.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="inline-flex min-h-touch items-center gap-1 text-xs font-medium text-red-600 hover:underline dark:text-red-400">
                                    <x-icon name="undo" size="4" />
                                    Reverse
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-empty icon="receipt" title="Nothing on this account yet."
                                 :message="'Invoices taken on account, and the payments against them, appear here as this '
                                    . $noun . ' trades.'" />
                    </td>
                </tr>
            @endforelse

            @if (!empty($ledger))
                <x-slot:foot>
                    <tr>
                        <td colspan="5">Balance owing</td>
                        <td class="text-right tabular-nums">{{ money($balance) }}</td>
                        <td class="no-print"></td>
                    </tr>
                </x-slot:foot>
            @endif
        </x-table>

        <x-slot:footer>
            "On account" is the part of an invoice that was not paid at the till. A sale settled in
            full at the till is listed for completeness and moves the balance by nothing.
        </x-slot:footer>
    </x-card>

    {{-- Record a payment --}}
    @can('create', App\Models\CustomerPayment::class)
        <x-modal name="record-payment" :show="$errors->any() && old('amount') !== null" focusable>
            <form method="POST" action="{{ route('customers.payments.store', $customer) }}"
                  class="max-h-[90vh] overflow-y-auto">
                @csrf

                <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">Record a payment</h2>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                            {{ $customer->name }} owes {{ money($balance) }}. Unless you pick one invoice, the money is
                            applied to the oldest unpaid invoices first.
                        </p>
                    </div>

                    <button type="button" x-on:click="$dispatch('close')" aria-label="Close"
                            class="-m-1.5 flex h-touch w-touch shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700">
                        <x-icon name="x" size="5" />
                    </button>
                </div>

                <div class="space-y-4 p-4 sm:p-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="payment-amount" class="{{ $label }}">
                                Amount ({{ currency() }}) <span class="text-red-600" aria-hidden="true">*</span>
                            </label>
                            {{-- A raw number, not money(): a number input cannot read grouped digits. --}}
                            <input type="number" id="payment-amount" name="amount" step="0.01" min="0.01"
                                   max="{{ round($balance, 2) }}" value="{{ old('amount', round($balance, 2)) }}"
                                   required class="{{ $field }} tabular-nums">
                        </div>
                        <div>
                            <label for="payment-method" class="{{ $label }}">Method</label>
                            <select id="payment-method" name="method" class="{{ $field }}">
                                @foreach ($paymentMethods as $method)
                                    <option value="{{ $method }}" @selected(old('method') === $method)>{{ strtoupper($method) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Searchable: a long-standing account can have hundreds of open invoices. --}}
                    <x-searchable-select name="sale_id" label="Against"
                                         :options="$outstandingSales->map(fn($sale) => [
                                             'value' => $sale->id,
                                             'label' => $sale->invoice_number . ' — ' . money($sale->outstanding) . ' owing',
                                         ])"
                                         placeholder="Oldest invoices first"
                                         search-placeholder="Type an invoice number..." />

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="payment-reference" class="{{ $label }}">
                                Reference <span class="{{ $optional }}">(optional)</span>
                            </label>
                            <input type="text" id="payment-reference" name="reference" value="{{ old('reference') }}"
                                   maxlength="255" class="{{ $field }}">
                        </div>
                        <div>
                            <label for="payment-paid-at" class="{{ $label }}">Paid on</label>
                            <input type="date" id="payment-paid-at" name="paid_at"
                                   value="{{ old('paid_at', today()->toDateString()) }}" class="{{ $field }}">
                        </div>
                    </div>

                    <div>
                        <label for="payment-note" class="{{ $label }}">
                            Note <span class="{{ $optional }}">(optional)</span>
                        </label>
                        <input type="text" id="payment-note" name="note" value="{{ old('note') }}" maxlength="255"
                               class="{{ $field }}">
                    </div>

                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        More than the balance is refused: there is nowhere in the ledger to hold money owed back
                        to a {{ $noun }}. Take the balance, or refund the difference at the till.
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                    <button type="button" x-on:click="$dispatch('close')"
                            class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="submit"
                            class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                        <x-icon name="check-circle" size="4" />
                        Save payment
                    </button>
                </div>
            </form>
        </x-modal>
    @endcan
@endsection
