@extends('layouts.app')

@section('title', $supplier->name)

@php
    /*
    |--------------------------------------------------------------------------
    | One supplier's account
    |--------------------------------------------------------------------------
    |
    | Tiles showing how the balance was arrived at, then the deliveries, the
    | money sent and the goods that went back.
    |
    | Flash messages and validation errors are printed by the shell.
    */
    $label = term('supplier', false, 'Supplier');
    $labelPlural = term('supplier', true, 'Suppliers');
    $purchaseLabel = term('purchase', false, 'Purchase');
    $purchasesLabel = term('purchase', true, 'Purchases');
    $owed = $summary['balance'];

    // money() puts the symbol first, so a bare negative would read "<symbol>-100".
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));

    $labelClass = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
    $input = 'block w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm
              focus:border-primary-500 focus:ring-primary-500
              dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
    $secondary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700';
    $primary = 'inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700';

    $contact = collect([$supplier->phone, $supplier->email, $supplier->address])->filter()->implode(' · ');

    $statusVariant = [
        \App\Models\Purchase::STATUS_RECEIVED => 'green',
        \App\Models\Purchase::STATUS_ORDERED => 'blue',
        \App\Models\Purchase::STATUS_CANCELLED => 'gray',
    ];
@endphp

@section('content')
    <div x-data="{ payModal: {{ $errors->any() && old('_form') === 'payment' ? 'true' : 'false' }} }">

        <x-page-header :title="$supplier->name"
                       :subtitle="$contact ?: 'No contact details'"
                       :back="route('suppliers.index')"
                       :back-label="$labelPlural">
            <x-slot:actions>
                <button type="button" @click="payModal = true" class="{{ $primary }}">
                    <x-icon name="coins" size="4" />
                    Record payment
                </button>
            </x-slot:actions>
        </x-page-header>

        @unless ($supplier->is_active)
            <div class="mb-4">
                <x-badge variant="gray" size="sm">Inactive</x-badge>
            </div>
        @endunless

        {{-- How the balance was arrived at --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ([
                ['Opening', $summary['opening'], 'database'],
                [$purchasesLabel . ' received', $summary['invoiced'], 'truck'],
                ['Paid on delivery', $summary['settled'], 'cart'],
                ['Payments', $summary['payments'], 'coins'],
                ['Returned', $summary['returns'], 'undo'],
            ] as [$caption, $figure, $icon])
                <x-stat :label="$caption" :value="$amount($figure)" :icon="$icon" />
            @endforeach

            <x-stat :label="$owed < 0 ? 'In credit' : 'Owed'" :value="money(abs($owed))" icon="scale"
                    :trend="$owed > 0 ? 'up' : null" invert
                    :hint="$owed > 0 ? 'still to pay' : ($owed < 0 ? 'paid ahead' : 'account settled')" />
        </div>

        @if ($summary['ordered'] > 0)
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                {{ money($summary['ordered']) }} is on order and not counted above: it is only owed once the goods arrive.
            </p>
        @endif

        @if ($supplier->notes)
            <x-card title="Notes" class="mt-4">
                <p class="whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ $supplier->notes }}</p>
            </x-card>
        @endif

        {{-- Purchases --}}
        <x-card :title="$purchasesLabel" :padding="false" class="mt-4">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>Invoice</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Paid</th>
                        <th class="text-right">Outstanding</th>
                    </tr>
                </x-slot:head>

                @forelse ($purchases as $purchase)
                    <tr>
                        <td>
                            <a href="{{ route('purchases.show', $purchase) }}"
                               class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                {{ $purchase->invoice_number }}
                            </a>
                        </td>
                        <td class="whitespace-nowrap">
                            {{ \Carbon\Carbon::parse($purchase->purchase_date)->format('d M Y') }}
                        </td>
                        <td>
                            <x-badge :variant="$statusVariant[$purchase->status] ?? 'gray'" size="sm">
                                {{ ucfirst($purchase->status) }}
                            </x-badge>
                        </td>
                        <td class="text-right tabular-nums">{{ money($purchase->total_amount) }}</td>
                        <td class="text-right tabular-nums">{{ money($purchase->amount_paid) }}</td>
                        <td class="text-right tabular-nums {{ (float) $purchase->balance_due > 0 ? 'font-semibold text-red-700 dark:text-red-400' : '' }}">
                            {{ money($purchase->balance_due) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-empty icon="truck"
                                     title="No {{ Str::lower($purchasesLabel) }} from this {{ Str::lower($label) }} yet."
                                     :message="'Deliveries recorded against this ' . Str::lower($label) . ' appear here.'">
                                <x-slot:action>
                                    <a href="{{ route('purchases.create') }}"
                                       class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                                        <x-icon name="plus" size="4" />
                                        Record a {{ Str::lower($purchaseLabel) }}
                                    </a>
                                </x-slot:action>
                            </x-empty>
                        </td>
                    </tr>
                @endforelse
            </x-table>
        </x-card>

        {{-- Payments --}}
        <x-card title="Payments made" :padding="false" class="mt-4">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>Date</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Recorded by</th>
                        <th class="text-right">Amount</th>
                        <th class="text-right">Action</th>
                    </tr>
                </x-slot:head>

                @forelse ($payments as $payment)
                    <tr>
                        <td class="whitespace-nowrap">{{ $payment->paid_at?->format('d M Y') }}</td>
                        <td>{{ ucfirst($payment->method) }}</td>
                        <td class="text-gray-500 dark:text-gray-400">
                            {{ $payment->reference ?: '-' }}{{ $payment->note ? ' (' . $payment->note . ')' : '' }}
                        </td>
                        <td>{{ $payment->user->name ?? '-' }}</td>
                        <td class="text-right tabular-nums">{{ money($payment->amount) }}</td>
                        <td class="text-right">
                            <form method="POST" action="{{ route('suppliers.payments.destroy', $payment) }}"
                                  onsubmit="return confirm('Reverse this payment? The balance owed goes back up.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="inline-flex min-h-touch items-center gap-1 text-xs font-medium text-red-600 hover:underline dark:text-red-400">
                                    <x-icon name="undo" size="4" />
                                    Reverse
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-empty icon="coins" title="Nothing paid yet."
                                     :message="'Money sent to this ' . Str::lower($label) . ' is listed here, newest first.'" />
                        </td>
                    </tr>
                @endforelse
            </x-table>
        </x-card>

        {{-- Returns --}}
        <x-card title="Goods returned" :padding="false" class="mt-4">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Against</th>
                        <th>Reason</th>
                        <th class="text-right">Credited</th>
                    </tr>
                </x-slot:head>

                @forelse ($returns as $return)
                    <tr>
                        <td class="whitespace-nowrap">{{ $return->created_at->format('d M Y') }}</td>
                        <td class="font-mono text-xs">{{ $return->reference }}</td>
                        <td>
                            @if ($return->purchase)
                                <a href="{{ route('purchases.show', $return->purchase) }}"
                                   class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                    {{ $return->purchase->invoice_number }}
                                </a>
                            @else
                                -
                            @endif
                        </td>
                        <td class="text-gray-500 dark:text-gray-400">{{ $return->reason }}</td>
                        <td class="text-right tabular-nums">{{ money($return->total_amount) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <x-empty icon="undo" title="Nothing returned."
                                     :message="'Goods sent back to this ' . Str::lower($label)
                                        . ' reduce what is owed, and are listed here.'" />
                        </td>
                    </tr>
                @endforelse
            </x-table>
        </x-card>

        {{-- Record a payment --}}
        <div x-show="payModal" style="display: none" @keydown.escape.window="payModal = false"
             class="fixed inset-0 z-modal flex items-start justify-center overflow-y-auto bg-gray-900/60 p-4"
             role="dialog" aria-modal="true" aria-labelledby="supplier-payment-title">
            <div class="my-8 max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white shadow-xl ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"
                 @click.away="payModal = false">
                <form method="POST" action="{{ route('suppliers.payments.store', $supplier) }}">
                    @csrf
                    <input type="hidden" name="_form" value="payment">

                    <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                        <div class="min-w-0">
                            <h2 id="supplier-payment-title" class="truncate text-base font-semibold text-gray-900 dark:text-gray-100">
                                Pay {{ $supplier->name }}
                            </h2>
                            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                {{ $owed > 0 ? money($owed) . ' owed.' : 'Nothing outstanding.' }}
                                A payment comes off the account as a whole, not off one invoice.
                            </p>
                        </div>

                        <button type="button" @click="payModal = false" aria-label="Close"
                                class="-m-1.5 flex h-touch w-touch shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700">
                            <x-icon name="x" size="5" />
                        </button>
                    </div>

                    <div class="space-y-4 p-4 sm:p-5">
                        <div>
                            <label for="supplier-payment-amount" class="{{ $labelClass }}">
                                Amount ({{ currency() }}) <span class="text-red-600" aria-hidden="true">*</span>
                            </label>
                            {{-- A bare number: an <input type="number"> cannot take grouped digits. --}}
                            <input type="number" id="supplier-payment-amount" step="0.01" min="0.01" name="amount" required
                                   value="{{ old('amount', $owed > 0 ? round($owed, 2) : '') }}"
                                   class="{{ $input }} tabular-nums">
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label for="supplier-payment-method" class="{{ $labelClass }}">Method</label>
                                <select id="supplier-payment-method" name="method" class="{{ $input }}">
                                    @foreach (\App\Models\SupplierPayment::METHODS as $method)
                                        <option value="{{ $method }}" @selected(old('method') === $method)>{{ ucfirst($method) }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="supplier-payment-paid-at" class="{{ $labelClass }}">Date paid</label>
                                <input type="date" id="supplier-payment-paid-at" name="paid_at"
                                       value="{{ old('paid_at', now()->toDateString()) }}" class="{{ $input }}">
                            </div>
                        </div>

                        <div>
                            <label for="supplier-payment-reference" class="{{ $labelClass }}">
                                Reference <span class="font-normal text-gray-400 dark:text-gray-500">(optional)</span>
                            </label>
                            <input type="text" id="supplier-payment-reference" name="reference" maxlength="255"
                                   value="{{ old('reference') }}" placeholder="Teller or cheque number"
                                   class="{{ $input }}">
                        </div>

                        <div>
                            <label for="supplier-payment-note" class="{{ $labelClass }}">
                                Note <span class="font-normal text-gray-400 dark:text-gray-500">(optional)</span>
                            </label>
                            <input type="text" id="supplier-payment-note" name="note" maxlength="255"
                                   value="{{ old('note') }}" class="{{ $input }}">
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700 sm:px-5">
                        <button type="button" @click="payModal = false" class="{{ $secondary }}">Cancel</button>
                        <button type="submit" class="{{ $primary }}">
                            <x-icon name="check-circle" size="4" />
                            Record payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
