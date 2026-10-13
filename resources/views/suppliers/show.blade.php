@extends('layouts.app')

@section('title', $supplier->name)

@section('content')
    @php
        $label = term('supplier', false, 'Supplier');
        $purchaseLabel = term('purchase', false, 'Purchase');
        $purchasesLabel = term('purchase', true, 'Purchases');
        $card = 'bg-white dark:bg-gray-800 rounded shadow p-4';
        $input = 'w-full rounded border border-gray-300 dark:border-gray-600 px-3 py-2 bg-white dark:bg-gray-700';
        $owed = $summary['balance'];
    @endphp

    <div x-data="{ payModal: {{ $errors->any() && old('_form') === 'payment' ? 'true' : 'false' }} }" class="space-y-4">

        {{-- Who they are --}}
        <div class="{{ $card }} flex flex-wrap justify-between items-start gap-4">
            <div>
                <h2 class="text-xl font-semibold">
                    {{ $supplier->name }}
                    @unless ($supplier->is_active)
                        <span class="text-xs px-2 py-1 rounded bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300">Inactive</span>
                    @endunless
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $supplier->phone }}
                    @if ($supplier->email) &middot; {{ $supplier->email }} @endif
                    @if ($supplier->address) &middot; {{ $supplier->address }} @endif
                </p>
                @if ($supplier->notes)
                    <p class="text-sm mt-2 text-gray-600 dark:text-gray-300">{{ $supplier->notes }}</p>
                @endif
            </div>
            <div class="flex gap-2">
                <button type="button" @click="payModal = true"
                        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm">Record payment</button>
                <a href="{{ route('suppliers.index') }}"
                   class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm">&larr; Back</a>
            </div>
        </div>

        {{-- How the balance was arrived at --}}
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
            @foreach ([
                ['Opening', $summary['opening']],
                [$purchasesLabel . ' received', $summary['invoiced']],
                ['Paid on delivery', $summary['settled']],
                ['Payments', $summary['payments']],
                ['Returned', $summary['returns']],
            ] as [$caption, $amount])
                <div class="{{ $card }}">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $caption }}</p>
                    <p class="font-semibold">{{ money($amount) }}</p>
                </div>
            @endforeach

            <div class="{{ $owed > 0 ? 'bg-red-50 dark:bg-red-900' : 'bg-green-50 dark:bg-green-900' }} rounded shadow p-4">
                <p class="text-xs text-gray-600 dark:text-gray-200">{{ $owed < 0 ? 'In credit' : 'Owed' }}</p>
                <p class="font-bold">{{ money(abs($owed)) }}</p>
            </div>
        </div>

        @if ($summary['ordered'] > 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ money($summary['ordered']) }} is on order and not counted above: it is only owed once the goods arrive.
            </p>
        @endif

        {{-- Purchases --}}
        <div class="{{ $card }} overflow-x-auto">
            <h3 class="font-semibold mb-3">{{ $purchasesLabel }}</h3>
            <table class="w-full text-sm">
                <thead class="bg-gray-100 dark:bg-gray-700 text-left">
                    <tr>
                        <th class="p-2">Invoice</th>
                        <th class="p-2">Date</th>
                        <th class="p-2">Status</th>
                        <th class="p-2 text-right">Total</th>
                        <th class="p-2 text-right">Paid</th>
                        <th class="p-2 text-right">Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($purchases as $purchase)
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <td class="p-2">
                                <a href="{{ route('purchases.show', $purchase) }}" class="text-blue-600 dark:text-blue-400 hover:underline">
                                    {{ $purchase->invoice_number }}
                                </a>
                            </td>
                            <td class="p-2">{{ \Carbon\Carbon::parse($purchase->purchase_date)->format('d M Y') }}</td>
                            <td class="p-2">{{ ucfirst($purchase->status) }}</td>
                            <td class="p-2 text-right">{{ money($purchase->total_amount) }}</td>
                            <td class="p-2 text-right">{{ money($purchase->amount_paid) }}</td>
                            <td class="p-2 text-right">{{ money($purchase->balance_due) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-4 text-center text-gray-500 dark:text-gray-400">
                            No {{ Str::lower($purchasesLabel) }} from this {{ Str::lower($label) }} yet.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Payments --}}
        <div class="{{ $card }} overflow-x-auto">
            <h3 class="font-semibold mb-3">Payments made</h3>
            <table class="w-full text-sm">
                <thead class="bg-gray-100 dark:bg-gray-700 text-left">
                    <tr>
                        <th class="p-2">Date</th>
                        <th class="p-2">Method</th>
                        <th class="p-2">Reference</th>
                        <th class="p-2">Recorded by</th>
                        <th class="p-2 text-right">Amount</th>
                        <th class="p-2 text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($payments as $payment)
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <td class="p-2">{{ $payment->paid_at?->format('d M Y') }}</td>
                            <td class="p-2">{{ ucfirst($payment->method) }}</td>
                            <td class="p-2">{{ $payment->reference ?: '-' }}{{ $payment->note ? ' (' . $payment->note . ')' : '' }}</td>
                            <td class="p-2">{{ $payment->user->name ?? '-' }}</td>
                            <td class="p-2 text-right">{{ money($payment->amount) }}</td>
                            <td class="p-2 text-right">
                                <form method="POST" action="{{ route('suppliers.payments.destroy', $payment) }}"
                                      onsubmit="return confirm('Reverse this payment? The balance owed goes back up.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 dark:text-red-400 hover:underline">Reverse</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-4 text-center text-gray-500 dark:text-gray-400">Nothing paid yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Returns --}}
        <div class="{{ $card }} overflow-x-auto">
            <h3 class="font-semibold mb-3">Goods returned</h3>
            <table class="w-full text-sm">
                <thead class="bg-gray-100 dark:bg-gray-700 text-left">
                    <tr>
                        <th class="p-2">Date</th>
                        <th class="p-2">Reference</th>
                        <th class="p-2">Against</th>
                        <th class="p-2">Reason</th>
                        <th class="p-2 text-right">Credited</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($returns as $return)
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <td class="p-2">{{ $return->created_at->format('d M Y') }}</td>
                            <td class="p-2 font-mono text-xs">{{ $return->reference }}</td>
                            <td class="p-2">
                                @if ($return->purchase)
                                    <a href="{{ route('purchases.show', $return->purchase) }}" class="text-blue-600 dark:text-blue-400 hover:underline">
                                        {{ $return->purchase->invoice_number }}
                                    </a>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="p-2">{{ $return->reason }}</td>
                            <td class="p-2 text-right">{{ money($return->total_amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-4 text-center text-gray-500 dark:text-gray-400">Nothing returned.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Record a payment --}}
        <div x-show="payModal" style="display: none"
             class="fixed inset-0 bg-black bg-opacity-50 flex items-start justify-center z-50 p-4 overflow-y-auto">
            <div class="bg-white dark:bg-gray-800 rounded shadow p-6 w-full max-w-lg my-8" @click.away="payModal = false">
                <h3 class="text-lg font-semibold mb-1">Pay {{ $supplier->name }}</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                    {{ $owed > 0 ? money($owed) . ' owed.' : 'Nothing outstanding.' }}
                    A payment comes off the account as a whole, not off one invoice.
                </p>

                <form method="POST" action="{{ route('suppliers.payments.store', $supplier) }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="_form" value="payment">

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Amount ({{ currency() }})</label>
                        {{-- A bare number: an <input type="number"> cannot take grouped digits. --}}
                        <input type="number" step="0.01" min="0.01" name="amount" required
                               value="{{ old('amount', $owed > 0 ? round($owed, 2) : '') }}" class="{{ $input }}">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Method</label>
                        <select name="method" class="{{ $input }}">
                            @foreach (\App\Models\SupplierPayment::METHODS as $method)
                                <option value="{{ $method }}" @selected(old('method') === $method)>{{ ucfirst($method) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date paid</label>
                        <input type="date" name="paid_at" value="{{ old('paid_at', now()->toDateString()) }}" class="{{ $input }}">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reference</label>
                        <input type="text" name="reference" maxlength="255" value="{{ old('reference') }}"
                               placeholder="Teller or cheque number" class="{{ $input }}">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Note</label>
                        <input type="text" name="note" maxlength="255" value="{{ old('note') }}" class="{{ $input }}">
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="payModal = false"
                                class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600">Cancel</button>
                        <button type="submit" class="px-4 py-2 rounded bg-green-600 hover:bg-green-700 text-white">Record payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
