<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>SalesPoint - Receipt</title>
    <style>
        body {
            font-family: monospace;
            width: 80mm;
            margin: 0;
            padding: 10px;
            background: #fff;
            color: #000;
        }

        .center {
            text-align: center;
        }

        .bold {
            font-weight: bold;
        }

        .line {
            border-top: 1px dashed #000;
            margin: 8px 0;
        }

        table {
            width: 100%;
            font-size: 12px;
        }
    </style>
</head>

<body onload="window.print()">

    <div class="center bold">SalesPoint POS</div>
    <div class="center">Simple POS. Smart Business.</div>
    <div class="line"></div>

    <table>
        @foreach($sale->items as $item)
        <tr>
            <td>{{ $item->product->name }} (x{{ $item->quantity }})</td>
            <td style="text-align: right;">₦{{ number_format($item->price * $item->quantity, 2) }}</td>
        </tr>
        @endforeach
    </table>

    <div class="line"></div>

    <table>
        <tr>
            <td>Subtotal:</td>
            <td style="text-align: right;">₦{{ number_format($sale->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td>Tax:</td>
            <td style="text-align: right;">₦{{ number_format($sale->tax, 2) }}</td>
        </tr>
        <tr>
            <td>Discount:</td>
            <td style="text-align: right;">₦{{ number_format($sale->discount, 2) }}</td>
        </tr>
        <tr class="bold">
            <td>Total:</td>
            <td style="text-align: right;">₦{{ number_format($sale->total, 2) }}</td>
        </tr>
    </table>

    <div class="line"></div>

    <p>Payment Method: {{ ucfirst($sale->payment_method) }}</p>
    <p>Date: {{ $sale->created_at->format('d M Y, h:i A') }}</p>

    <div class="line"></div>
    <div class="center">Thank you for your purchase!</div>

</body>

</html>