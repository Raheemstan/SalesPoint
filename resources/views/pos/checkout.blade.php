@php
    /*
    |--------------------------------------------------------------------------
    | The receipt
    |--------------------------------------------------------------------------
    |
    | Printed on a thermal roll, so the width is the configured paper size and
    | everything is monospaced and single-column. `receipt_width` and
    | `receipt_footer` come from Settings > Receipt; both have sensible defaults
    | so an install that never visited that screen still prints.
    |
    | No currency symbol is written here: money() reads it from the settings,
    | because this is the one piece of paper a customer keeps.
    |
    | The tender breakdown shows what the customer *handed over*, per method.
    | `sale_payments` records money kept, so the cash line has the change added
    | back onto it - a receipt that says "cash 215, change 85" on a 215 sale
    | reads as nonsense to the person holding it.
    */
    $width = setting('receipt_width', '80mm') === '58mm' ? '58mm' : '80mm';
    $footer = setting('receipt_footer');
    $taxNumber = setting('receipt_tax_number');
    $change = round((float) $sale->change_due, 2);
    // Guarded: the column arrives with the till's own migration, and this view
    // also renders sales made before it.
    $serviceCharge = round((float) ($sale->service_charge ?? 0), 2);

    $tenders = $sale->payments->map(fn ($payment) => [
        'label' => \App\Models\SalePayment::label($payment->method),
        'amount' => round((float) $payment->amount + ($payment->method === 'cash' ? $change : 0), 2),
        'reference' => $payment->reference,
    ]);

    // A sale made before split payments existed has no tender rows; its single
    // method and paid_amount stand in for them.
    if ($tenders->isEmpty() && ((float) $sale->paid_amount > 0 || $change > 0)) {
        $tenders = collect([[
            'label' => ucfirst((string) $sale->payment_method),
            'amount' => round((float) $sale->paid_amount + $change, 2),
            'reference' => null,
        ]]);
    }
@endphp
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ setting('store_name') ?: 'SalesPoint' }} - Receipt {{ $sale->invoice_number }}</title>
    <style>
        body {
            font-family: ui-monospace, "Courier New", monospace;
            width: {{ $width }};
            margin: 0 auto;
            padding: 10px;
            background: #fff;
            color: #000;
            font-size: {{ $width === '58mm' ? '10px' : '12px' }};
            line-height: 1.35;
        }

        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .big { font-size: 1.2em; }
        .muted { font-size: 0.9em; }

        .line {
            border-top: 1px dashed #000;
            margin: 6px 0;
        }

        img.logo {
            max-width: 60px;
            margin-bottom: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        td { vertical-align: top; padding: 1px 0; }
        td.amount { text-align: right; white-space: nowrap; padding-left: 6px; }

        @media print {
            @page { margin: 0; }
            body { width: auto; }
        }
    </style>
</head>

<body>

    {{-- Header --}}
    <div class="center">
        <img src="/logo.png" alt="" class="logo">
        <div class="bold big">{{ setting('store_name', 'SalesPoint POS') }}</div>
        <div class="muted">{{ setting('store_motto', 'Simple POS. Smart Business.') }}</div>
        <div class="muted">{{ setting('store_address', '123 Business Street, Nigeria') }}</div>
        <div class="muted">Phone: {{ setting('store_phone', '+234 800 000 0000') }}</div>
        @if ($taxNumber)
            <div class="muted">VAT / TIN: {{ $taxNumber }}</div>
        @endif
    </div>

    @if ($sale->status === 'voided')
        <p class="center bold big">*** VOIDED ***</p>
    @elseif ($sale->refunded_amount > 0)
        <p class="center bold">Refunded: {{ money($sale->refunded_amount) }}</p>
    @endif

    <div class="line"></div>

    {{-- Items --}}
    <table>
        @foreach($sale->items as $item)
            <tr>
                <td>{{ $item->product->name ?? 'Deleted product' }} (x{{ $item->quantity }})</td>
                <td class="amount">{{ money($item->price * $item->quantity) }}</td>
            </tr>
            @if ($item->discount_amount > 0)
                <tr>
                    <td class="muted">&nbsp;&nbsp;less discount</td>
                    <td class="amount muted">− {{ money($item->discount_amount) }}</td>
                </tr>
            @endif
        @endforeach
    </table>

    <div class="line"></div>

    {{-- Totals --}}
    <table>
        <tr>
            <td>Subtotal:</td>
            <td class="amount">{{ money($sale->total_amount) }}</td>
        </tr>
        @if ($sale->discount_amount > 0)
            <tr>
                <td>Discount:</td>
                <td class="amount">− {{ money($sale->discount_amount) }}</td>
            </tr>
        @endif
        @if ($sale->tax_amount > 0)
            <tr>
                <td>Tax{{ $sale->tax_rate > 0 ? ' (' . rtrim(rtrim(number_format($sale->tax_rate, 2), '0'), '.') . '%)' : '' }}:</td>
                <td class="amount">{{ money($sale->tax_amount) }}</td>
            </tr>
        @endif
        @if ($serviceCharge > 0)
            {{--
                Named and itemised, never folded silently into the total: the
                label is the one quoted when the charge was agreed.
            --}}
            <tr>
                <td>{{ $sale->service_charge_label ?: 'Service charge' }}:</td>
                <td class="amount">{{ money($serviceCharge) }}</td>
            </tr>
        @endif
        <tr class="bold big">
            <td>Total:</td>
            <td class="amount">{{ money($sale->grand_total) }}</td>
        </tr>
    </table>

    <div class="line"></div>

    {{-- What was handed over, per tender --}}
    <table>
        @forelse ($tenders as $tender)
            <tr>
                <td>{{ $tender['label'] }}:</td>
                <td class="amount">{{ money($tender['amount']) }}</td>
            </tr>
            @if ($tender['reference'])
                <tr>
                    <td colspan="2" class="muted">&nbsp;&nbsp;ref {{ $tender['reference'] }}</td>
                </tr>
            @endif
        @empty
            <tr>
                <td>Paid:</td>
                <td class="amount">{{ money(0) }}</td>
            </tr>
        @endforelse

        @if ($change > 0)
            <tr>
                <td>Change:</td>
                <td class="amount">{{ money($change) }}</td>
            </tr>
        @endif
    </table>

    {{-- Credit: what is still owed, and when it is due --}}
    @if ($sale->isCredit())
        <div class="line"></div>

        <table>
            <tr class="bold">
                <td>BALANCE DUE:</td>
                <td class="amount">{{ money($sale->balance_due) }}</td>
            </tr>
            @if ($sale->due_date)
                <tr>
                    <td>Due by:</td>
                    <td class="amount">{{ $sale->due_date->format('d M Y') }}</td>
                </tr>
            @endif
        </table>

        <p class="muted">
            {{ $sale->payment_status === \App\Models\Sale::PAYMENT_UNPAID ? 'On account' : 'Part paid' }}
            @if ($sale->customer)
                — {{ $sale->customer->name }}
            @endif
        </p>
    @endif

    <div class="line"></div>

    {{-- Who, what and when --}}
    <p>Invoice: <strong>{{ $sale->invoice_number }}</strong></p>
    @if ($sale->customer)
        <p>{{ term('customer') }}: <strong>{{ $sale->customer->name }}</strong>
            @if ($sale->customer->phone)
                <span class="muted">({{ $sale->customer->phone }})</span>
            @endif
        </p>
    @endif
    <p>Served by: {{ $sale->user->name ?? '-' }}</p>
    <p>Date: {{ ($sale->sale_date ? \Illuminate\Support\Carbon::parse($sale->sale_date) : $sale->created_at)->format('d M Y h:i A') }}</p>

    <div class="line"></div>

    {{-- Footer --}}
    <div class="center">
        <div>{{ $footer ?: 'Thank you for your purchase!' }}</div>
        <div class="muted" style="margin-top: 4px;">Powered by <strong>SalesPoint</strong></div>
    </div>

</body>
<script>
    // After a fresh sale: print, then go back to the till.
    // A reprint from the sales history stays on the page.
    const backTo = "{{ ($reprint ?? false) ? route('sales.show', $sale->id) : route('pos.index') }}";

    window.onload = function () {
        window.print();
    };

    window.onafterprint = function () {
        window.location.href = backTo;
    };

    window.addEventListener('focus', function () {
        if (window.printed === undefined) {
            window.printed = true;
        } else {
            window.location.href = backTo;
        }
    });
</script>

</html>
