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

        img.logo {
            max-width: 60px;
            margin-bottom: 5px;
        }

        table {
            width: 100%;
            font-size: 12px;
        }

        td {
            vertical-align: top;
        }
    </style>
</head>

<body>

    {{-- Header --}}
    <div class="center">
        <img src="/logo.png" alt="Logo" class="logo">
        <div class="bold" style="font-size: 14px;">SalesPoint POS</div>
        <div style="font-size: 11px;">Simple POS. Smart Business.</div>
        <div style="font-size: 11px;">123 Business Street, Nigeria</div>
        <div style="font-size: 11px;">Phone: +234 800 000 0000</div>
    </div>

    <div class="line"></div>

    {{-- Items --}}
    <table>
        @foreach($sale->items as $item)
            <tr>
                <td>
                    {{ $item->product->name }} (x{{ $item->quantity }})
                </td>
                <td style="text-align: right;">
                    ₦{{ number_format($item->price * $item->quantity, 2) }}
                </td>
            </tr>
        @endforeach
    </table>

    <div class="line"></div>

    {{-- Totals --}}
    <table>
        <tr>
            <td>Subtotal:</td>
            <td style="text-align: right;">₦{{ number_format($sale->total_amount, 2) }}</td>
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
            <td style="text-align: right;">₦{{ number_format($sale->grand_total, 2) }}</td>
        </tr>
    </table>

    <div class="line"></div>

    {{-- Payment Info --}}
    <p>Payment Method: <strong>{{ ucfirst($sale->payment_method) }}</strong></p>
    <p>Date: {{ $sale->created_at->format('d M Y, h:i A') }}</p>

    <div class="line"></div>

    {{-- Footer --}}
    <div class="center">
        <div>Thank you for your purchase!</div>
        <div style="margin-top: 5px;">Powered by <strong>SalesPoint</strong></div>
    </div>

</body>
<script>
    window.onload = function () {
        window.print();
    };

    window.onafterprint = function () {
        window.location.href = "{{ route('pos.index') }}";
    };

    // Fallback for browsers where onafterprint doesn't work reliably
    window.addEventListener('focus', function () {
        // Check if print dialog was opened and now user is back to page
        if (window.printed === undefined) {
            window.printed = true;
        } else {
            window.location.href = "{{ route('pos.index') }}";
        }
    });
</script>


</html>