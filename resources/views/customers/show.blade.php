@extends('layouts.app')
@section('title', $customer->name)

@php
    $noun = strtolower(term('customer'));
    $input = 'rounded-md border border-gray-300 dark:border-gray-600 px-3 py-2 bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100';
    $limit = (float) $customer->credit_limit;
    $available = $customer->credit_available;   // null = no limit
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

    <div class="space-y-6">

        {{-- Who they are, and what they owe --}}
        <div class="bg-white dark:bg-gray-800 rounded shadow p-4 print-card">
            <div class="flex flex-wrap justify-between items-start gap-4">
                <div>
                    <h2 class="text-2xl font-semibold text-gray-800 dark:text-gray-100">{{ $customer->name }}</h2>
                    <p class="text-sm text-gray-500">
                        {{ collect([$customer->phone, $customer->email, $customer->address])->filter()->implode(' · ') ?: 'No contact details' }}
                    </p>
                    <p class="text-sm text-gray-500">
                        {{ setting('store_name', 'SalesPoint') }} ·
                        Statement to {{ now()->format('d M Y') }}
                        @unless ($customer->is_active)
                            · <span class="text-red-600 dark:text-red-400">Inactive</span>
                        @endunless
                    </p>
                </div>
                <div class="flex gap-2 no-print">
                    @if ($balance > 0)
                        <button type="button" x-data x-on:click="$dispatch('open-modal', 'record-payment')"
                                class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded">Record payment</button>
                    @endif
                    <button type="button" onclick="window.print()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded">🖨️ Print</button>
                    <a href="{{ route('customers.index') }}" class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600">← Back</a>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4 text-sm">
                <div class="p-3 rounded bg-gray-50 dark:bg-gray-700">
                    <p class="text-xs text-gray-500">Credit limit</p>
                    <p class="font-bold">{{ $limit > 0 ? money($limit) : 'No limit' }}</p>
                </div>
                <div class="p-3 rounded {{ $balance > 0 ? 'bg-orange-50 dark:bg-orange-900' : 'bg-gray-50 dark:bg-gray-700' }}">
                    <p class="text-xs text-gray-500 dark:text-gray-300">Balance owing</p>
                    <p class="font-bold">{{ money($balance) }}</p>
                </div>
                <div class="p-3 rounded {{ $customer->overdue > 0 ? 'bg-red-50 dark:bg-red-900' : 'bg-gray-50 dark:bg-gray-700' }}">
                    <p class="text-xs text-gray-500 dark:text-gray-300">Overdue</p>
                    <p class="font-bold">{{ money($customer->overdue) }}</p>
                </div>
                <div class="p-3 rounded bg-gray-50 dark:bg-gray-700">
                    <p class="text-xs text-gray-500">Credit available</p>
                    <p class="font-bold {{ $available !== null && $available < 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                        {{ $available === null ? 'Unlimited' : money($available) }}
                    </p>
                </div>
            </div>

            @if ($customer->notes)
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-300 whitespace-pre-line">{{ $customer->notes }}</p>
            @endif
        </div>

        {{-- The ledger: every invoice and every payment in the order they happened --}}
        <div class="bg-white dark:bg-gray-800 rounded shadow p-4 print-card text-sm">
            <h3 class="font-semibold mb-3">Statement of account</h3>

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-gray-100 dark:bg-gray-700">
                            <th class="p-2">Date</th>
                            <th class="p-2">Reference</th>
                            <th class="p-2">Detail</th>
                            <th class="p-2 text-right">On account</th>
                            <th class="p-2 text-right">Paid</th>
                            <th class="p-2 text-right">Balance</th>
                            <th class="p-2 no-print"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ledger as $row)
                            <tr class="border-b border-gray-200 dark:border-gray-600">
                                <td class="p-2 whitespace-nowrap">{{ $row['at']?->format('d M Y H:i') }}</td>
                                <td class="p-2 font-mono">
                                    @if ($row['kind'] === 'invoice')
                                        <a href="{{ route('sales.show', $row['model']) }}" class="text-blue-600 hover:underline">{{ $row['reference'] }}</a>
                                    @else
                                        {{ $row['reference'] }}
                                    @endif
                                </td>
                                <td class="p-2 text-gray-500">
                                    {{ $row['detail'] }}
                                    @if ($row['kind'] === 'invoice' && $row['debit'] <= 0)
                                        <span class="text-xs">· paid at till</span>
                                    @endif
                                    @if ($row['due_date'])
                                        <span class="text-xs {{ $row['overdue'] ? 'text-red-600 dark:text-red-400' : '' }}">
                                            · due {{ $row['due_date']->format('d M Y') }}{{ $row['overdue'] ? ' (overdue)' : '' }}
                                        </span>
                                    @endif
                                </td>
                                <td class="p-2 text-right">{{ $row['debit'] > 0 ? money($row['debit']) : '—' }}</td>
                                <td class="p-2 text-right text-green-700 dark:text-green-400">{{ $row['credit'] > 0 ? money($row['credit']) : '—' }}</td>
                                <td class="p-2 text-right font-semibold">{{ money($row['balance']) }}</td>
                                <td class="p-2 no-print">
                                    @if ($row['kind'] === 'payment' && auth()->user()->can('delete', $row['model']))
                                        <form method="POST" action="{{ route('customers.payments.destroy', $row['model']) }}"
                                              onsubmit="return confirm('Reverse this payment? Every invoice it settled goes back to what it owed before.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:underline text-xs">Reverse</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="p-4 text-center text-gray-500">Nothing on this account yet.</td></tr>
                        @endforelse
                    </tbody>
                    @if (!empty($ledger))
                        <tfoot>
                            <tr class="bg-gray-50 dark:bg-gray-700 font-semibold">
                                <td class="p-2" colspan="5">Balance owing</td>
                                <td class="p-2 text-right">{{ money($balance) }}</td>
                                <td class="no-print"></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            <p class="text-xs text-gray-500 mt-3">
                "On account" is the part of an invoice that was not paid at the till. A sale settled in
                full at the till is listed for completeness and moves the balance by nothing.
            </p>
        </div>

        {{-- Record a payment --}}
        @can('create', App\Models\CustomerPayment::class)
            <x-modal name="record-payment" :show="$errors->any() && old('amount') !== null" focusable>
                <form method="POST" action="{{ route('customers.payments.store', $customer) }}" class="p-6 space-y-4">
                    @csrf

                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Record a payment</h3>
                    <p class="text-sm text-gray-500">
                        {{ $customer->name }} owes {{ money($balance) }}. Unless you pick one invoice, the money is
                        applied to the oldest unpaid invoices first.
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Amount ({{ currency() }})</label>
                            {{-- A raw number, not money(): a number input cannot read grouped digits. --}}
                            <input type="number" name="amount" step="0.01" min="0.01" max="{{ round($balance, 2) }}"
                                   value="{{ old('amount', round($balance, 2)) }}" required class="w-full {{ $input }}">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Method</label>
                            <select name="method" class="w-full {{ $input }}">
                                @foreach ($paymentMethods as $method)
                                    <option value="{{ $method }}" @selected(old('method') === $method)>{{ strtoupper($method) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Against</label>
                        <select name="sale_id" class="w-full {{ $input }}">
                            <option value="">Oldest invoices first</option>
                            @foreach ($outstandingSales as $sale)
                                <option value="{{ $sale->id }}" @selected((int) old('sale_id') === $sale->id)>
                                    {{ $sale->invoice_number }} — {{ money($sale->outstanding) }} owing
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Reference <span class="text-gray-400">(optional)</span></label>
                            <input type="text" name="reference" value="{{ old('reference') }}" maxlength="255" class="w-full {{ $input }}">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Paid on</label>
                            <input type="date" name="paid_at" value="{{ old('paid_at', today()->toDateString()) }}" class="w-full {{ $input }}">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">Note <span class="text-gray-400">(optional)</span></label>
                        <input type="text" name="note" value="{{ old('note') }}" maxlength="255" class="w-full {{ $input }}">
                    </div>

                    <p class="text-xs text-gray-500">
                        More than the balance is refused: there is nowhere in the ledger to hold money owed back
                        to a {{ $noun }}. Take the balance, or refund the difference at the till.
                    </p>

                    <div class="flex justify-end gap-2">
                        <button type="button" x-on:click="$dispatch('close')"
                                class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600">Cancel</button>
                        <button type="submit" class="px-4 py-2 rounded bg-green-600 hover:bg-green-700 text-white">Save payment</button>
                    </div>
                </form>
            </x-modal>
        @endcan
    </div>
@endsection
