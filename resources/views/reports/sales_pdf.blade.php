{{--
|--------------------------------------------------------------------------
| The sales report as a PDF
|--------------------------------------------------------------------------
|
| A standalone document, not a page of the app. It used to @extends the app
| layout, which meant DomPDF was handed the whole shell - sidebar, theme
| switcher, a date-range form nobody can fill in on paper, and a @vite tag
| whose stylesheet DomPDF cannot fetch or understand. None of the Tailwind
| classes it carried had any effect on the output.
|
| So: one <style> block of the plain CSS DomPDF actually supports (no flexbox,
| no grid, no custom properties), and a table. The shop's name and currency come
| from the settings, because the same install is sold in different countries.
--}}

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ term('sale', true) }} report {{ $startDate }} to {{ $endDate }}</title>
    <style>
        @page { margin: 18mm 14mm; }

        body {
            font-family: DejaVu Sans, sans-serif;   /* DomPDF's own font: has the currency glyphs */
            font-size: 10px;
            color: #111827;
        }

        h1 { font-size: 17px; margin: 0 0 2px; }
        .muted { color: #6b7280; }
        .head { border-bottom: 1.5px solid #111827; padding-bottom: 8px; margin-bottom: 12px; }
        .head .right { float: right; text-align: right; }

        /* A table, because DomPDF has no flexbox to lay tiles out with. */
        table { width: 100%; border-collapse: collapse; }
        .summary { margin-bottom: 14px; }
        .summary td {
            border: 1px solid #e5e7eb;
            padding: 6px 8px;
            width: 16.6%;
            vertical-align: top;
        }
        .summary .label { color: #6b7280; font-size: 9px; text-transform: uppercase; }
        .summary .figure { font-size: 12px; font-weight: bold; padding-top: 2px; }

        .rows th {
            background: #f3f4f6;
            border-bottom: 1px solid #d1d5db;
            padding: 5px 6px;
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
        }
        .rows td { border-bottom: 1px solid #f3f4f6; padding: 5px 6px; vertical-align: top; }
        .rows tfoot td { border-top: 1.5px solid #111827; border-bottom: 0; font-weight: bold; }
        .num { text-align: right; }
        .items { margin: 0; padding-left: 12px; color: #374151; }

        .empty { padding: 24px; text-align: center; color: #6b7280; }
        .foot { margin-top: 14px; font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>

<div class="head">
    <div class="right muted">
        Printed {{ now()->format('j M Y, H:i') }}<br>
        {{ setting('store_phone') ?: '' }}
    </div>

    <h1>{{ setting('store_name') ?: 'SalesPoint' }}</h1>
    <div class="muted">
        {{ term('sale', true) }} report &middot;
        {{ \Carbon\Carbon::parse($startDate)->format('j M Y') }} to
        {{ \Carbon\Carbon::parse($endDate)->format('j M Y') }}
        @if (request('payment_method'))
            &middot; {{ ucfirst(request('payment_method')) }} only
        @endif
    </div>
</div>

<table class="summary">
    <tr>
        <td>
            <div class="label">Taken</div>
            <div class="figure">{{ money($summary['total_sales']) }}</div>
        </td>
        <td>
            <div class="label">Refunded</div>
            <div class="figure">{{ money($summary['total_refunds']) }}</div>
        </td>
        <td>
            <div class="label">Net sales</div>
            <div class="figure">{{ money($summary['net_sales']) }}</div>
        </td>
        <td>
            <div class="label">Tax</div>
            <div class="figure">{{ money($summary['total_tax']) }}</div>
        </td>
        <td>
            <div class="label">Discounts</div>
            <div class="figure">{{ money($summary['total_discount']) }}</div>
        </td>
        <td>
            <div class="label">{{ term('sale', true) }}</div>
            <div class="figure">{{ number_format($summary['total_transactions']) }}</div>
        </td>
    </tr>
</table>

<table class="rows">
    <thead>
        <tr>
            <th>Date</th>
            <th>Invoice</th>
            <th>Items</th>
            <th class="num">Total</th>
            <th class="num">Tax</th>
            <th class="num">Discount</th>
            <th class="num">Refunded</th>
            <th>Status</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($sales as $sale)
            <tr>
                <td>{{ $sale->created_at->format('d M Y') }}</td>
                <td>{{ $sale->invoice_number }}</td>
                <td>
                    <ul class="items">
                        @foreach ($sale->items as $item)
                            <li>{{ $item->product->name ?? 'Deleted ' . strtolower(term('product')) }}
                                (x{{ $item->quantity }})</li>
                        @endforeach
                    </ul>
                </td>
                <td class="num">{{ money($sale->grand_total) }}</td>
                <td class="num">{{ money($sale->tax_amount) }}</td>
                <td class="num">{{ money($sale->discount_amount) }}</td>
                <td class="num">{{ (float) $sale->refunded_amount > 0 ? money($sale->refunded_amount) : '-' }}</td>
                <td>{{ ucfirst(str_replace('_', ' ', $sale->status)) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="8" class="empty">
                    No {{ strtolower(term('sale', true)) }} in this period.
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($sales->isNotEmpty())
        <tfoot>
            <tr>
                <td colspan="3">{{ number_format($sales->count()) }} shown</td>
                <td class="num">{{ money($summary['total_sales']) }}</td>
                <td class="num">{{ money($summary['total_tax']) }}</td>
                <td class="num">{{ money($summary['total_discount']) }}</td>
                <td class="num">{{ money($summary['total_refunds']) }}</td>
                <td></td>
            </tr>
        </tfoot>
    @endif
</table>

<p class="foot">
    Net sales are what was taken less anything handed back in the same period. Tax collected is
    held for the tax authority and is not revenue. A voided sale is reversed in full and is not
    counted as a transaction.
</p>

</body>
</html>
