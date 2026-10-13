{{--
    The emailed receipt. Plain HTML with inline styles on purpose: mail clients
    strip <style> blocks and know nothing about Tailwind, and this file is the
    one piece of the app that is rendered somewhere we do not control.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $sale->invoice_number }}</title>
</head>
<body style="margin:0; padding:24px; background:#f3f4f6; font-family:Arial, Helvetica, sans-serif; color:#111827;">

<div style="max-width:560px; margin:0 auto; background:#ffffff; border-radius:6px; padding:24px;">

    <h1 style="margin:0 0 4px; font-size:20px;">{{ $shop['name'] }}</h1>

    @if ($shop['address'])
        <p style="margin:0; font-size:12px; color:#6b7280;">{{ $shop['address'] }}</p>
    @endif
    @if ($shop['phone'] || $shop['email'])
        <p style="margin:0; font-size:12px; color:#6b7280;">
            {{ collect([$shop['phone'], $shop['email']])->filter()->implode(' · ') }}
        </p>
    @endif
    @if ($shop['taxNumber'])
        <p style="margin:4px 0 0; font-size:12px; color:#6b7280;">Tax no. {{ $shop['taxNumber'] }}</p>
    @endif

    <hr style="border:none; border-top:1px solid #e5e7eb; margin:16px 0;">

    <p style="margin:0 0 16px; font-size:13px;">
        <strong>Receipt {{ $sale->invoice_number }}</strong><br>
        {{ ($sale->created_at ?? now())->format('d M Y, H:i') }}
        @if ($sale->customer)
            <br>{{ term('customer') }}: {{ $sale->customer->name }}
        @endif
    </p>

    <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
            <tr>
                <th align="left" style="padding:6px 0; border-bottom:1px solid #e5e7eb;">{{ term('product') }}</th>
                <th align="right" style="padding:6px 0; border-bottom:1px solid #e5e7eb;">Qty</th>
                <th align="right" style="padding:6px 0; border-bottom:1px solid #e5e7eb;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sale->items as $item)
                <tr>
                    <td style="padding:6px 0; border-bottom:1px solid #f3f4f6;">
                        {{ $item->product->name ?? 'Item #' . $item->product_id }}
                    </td>
                    <td align="right" style="padding:6px 0; border-bottom:1px solid #f3f4f6;">{{ $item->quantity }}</td>
                    <td align="right" style="padding:6px 0; border-bottom:1px solid #f3f4f6;">{{ money($item->total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="width:100%; border-collapse:collapse; font-size:13px; margin-top:12px;">
        @if ((float) $sale->discount_amount > 0)
            <tr>
                <td style="padding:2px 0;">Discount</td>
                <td align="right" style="padding:2px 0;">-{{ money($sale->discount_amount) }}</td>
            </tr>
        @endif
        @if ((float) $sale->tax_amount > 0)
            <tr>
                <td style="padding:2px 0;">Tax</td>
                <td align="right" style="padding:2px 0;">{{ money($sale->tax_amount) }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding:6px 0; font-weight:bold; border-top:1px solid #e5e7eb;">Total</td>
            <td align="right" style="padding:6px 0; font-weight:bold; border-top:1px solid #e5e7eb;">
                {{ money($sale->grand_total) }}
            </td>
        </tr>
        <tr>
            <td style="padding:2px 0; color:#6b7280;">Paid by</td>
            <td align="right" style="padding:2px 0; color:#6b7280;">{{ ucfirst((string) $sale->payment_method) }}</td>
        </tr>
        @if ((float) $sale->balance_due > 0)
            <tr>
                <td style="padding:2px 0; color:#b45309;">Balance due</td>
                <td align="right" style="padding:2px 0; color:#b45309;">{{ money($sale->balance_due) }}</td>
            </tr>
        @endif
    </table>

    @if ($shop['footer'])
        <p style="margin:20px 0 0; font-size:12px; color:#6b7280; text-align:center;">{{ $shop['footer'] }}</p>
    @endif

</div>

</body>
</html>
