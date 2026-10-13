<!DOCTYPE html>
{{--
|--------------------------------------------------------------------------
| The kitchen prep ticket
|--------------------------------------------------------------------------
|
| A standalone page, not the app shell: it is paper, and a sidebar does not
| print. It is also deliberately not the receipt - the kitchen does not care
| what anything costs, so there are no prices on it at all. What the kitchen
| needs is which table, who to give it back to, what time it was ordered, how
| many are eating, and exactly what to cook including every special request.
|
| Only the lines this send covers are on it. A plate is never printed twice:
| see TableOrderService::sendToKitchen().
|
| NOT wired to the thermal printer. POSController owns the ESC/POS connector and
| a kitchen printer is usually a second device on a second queue anyway, so this
| prints through the browser. To add a real thermal kitchen ticket later, the
| cleanest seam is a small KitchenTicketPrinter service called from
| TableOrderController::sendToKitchen() with ($order, $items), reading its own
| "kitchen_printer_name" setting - the data it needs is exactly what this view
| is handed.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kitchen ticket · {{ $order->table->name }}</title>

    <style>
        /*
         * Written out rather than using Tailwind: this page has to print
         * identically whether or not the asset build has been run, and a ticket
         * is the last thing that should depend on a Vite manifest.
         *
         * 80mm is the common thermal roll width; it degrades to a narrow column
         * on A4 if the shop prints to a normal printer.
         */
        * { box-sizing: border-box; }

        body {
            font-family: ui-monospace, "Cascadia Mono", Consolas, "Courier New", monospace;
            width: 80mm;
            margin: 0 auto;
            padding: 8px;
            background: #fff;
            color: #000;
            font-size: 13px;
            line-height: 1.35;
        }

        .center { text-align: center; }
        .rule { border-top: 1px dashed #000; margin: 6px 0; }

        h1 {
            font-size: 26px;
            margin: 0;
            letter-spacing: 1px;
        }

        .meta {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            font-size: 12px;
        }

        /* The kitchen reads this across the room, so the quantity is big. */
        .line { margin: 7px 0; display: flex; gap: 8px; align-items: baseline; }
        .qty { font-size: 20px; font-weight: 700; min-width: 2.2em; }
        .what { font-size: 15px; font-weight: 700; text-transform: uppercase; }

        /* A special request that is missed is a plate sent back. */
        .note {
            margin-top: 2px;
            font-size: 13px;
            font-weight: 700;
            border-left: 3px solid #000;
            padding-left: 5px;
        }

        .course {
            margin: 10px 0 2px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid #000;
        }

        .actions { margin-top: 14px; text-align: center; }

        .actions a, .actions button {
            display: inline-block;
            font: inherit;
            padding: 10px 14px;
            margin: 0 3px;
            border: 1px solid #000;
            border-radius: 6px;
            background: #fff;
            color: #000;
            text-decoration: none;
            cursor: pointer;
        }

        /* Paper gets the ticket and nothing else. */
        @media print {
            body { width: auto; padding: 0; }
            .actions { display: none; }
        }
    </style>
</head>

<body>

    <div class="center">
        <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 2px;">Kitchen</div>
        <h1>{{ $order->table->name }}</h1>
    </div>

    <div class="rule"></div>

    <div class="meta">
        <span>{{ term('cashier') }}: <strong>{{ $order->user->name ?? '-' }}</strong></span>
        <span><strong>{{ $printedAt->format('H:i') }}</strong></span>
    </div>
    <div class="meta">
        <span>{{ $order->guests }} {{ lcfirst(term('customer', $order->guests !== 1)) }}</span>
        <span>{{ $order->reference }}</span>
    </div>
    @if ($order->note)
        <div class="note">{{ $order->note }}</div>
    @endif

    <div class="rule"></div>

    {{--
        Grouped by course so the kitchen can fire starters before mains. Lines
        with no course set come first, under no heading, because an unlabelled
        heading is worse than none.
    --}}
    @php
        $byCourse = $items->groupBy(fn ($item) => $item->course ?? '');
        $order_of_courses = collect(\App\Models\TableOrderItem::COURSES)->prepend('')->values();
        $byCourse = $byCourse->sortBy(fn ($lines, $course) => $order_of_courses->search($course));
    @endphp

    @foreach ($byCourse as $course => $lines)
        @if ($course !== '')
            <div class="course">{{ $course }}</div>
        @endif

        @foreach ($lines as $item)
            <div class="line">
                <span class="qty">{{ $item->quantity }}&times;</span>
                <span>
                    <span class="what">{{ $item->product->name ?? 'Removed item' }}</span>
                    @if ($item->note)
                        <div class="note">{{ $item->note }}</div>
                    @endif
                </span>
            </div>
        @endforeach
    @endforeach

    <div class="rule"></div>

    <div class="center" style="font-size: 11px;">
        {{ $items->sum('quantity') }} item(s) · {{ $printedAt->format('d M Y H:i') }}
    </div>

    <div class="actions">
        <button type="button" onclick="window.print()">Print again</button>
        <a href="{{ route('table-orders.show', $order) }}">Back to {{ $order->table->name }}</a>
    </div>

    <script>
        // Print on arrival. Nothing is navigated afterwards: the server taps
        // "Back" when the ticket is on the rail, because a kitchen printer that
        // jams should leave this page on screen to be printed again.
        window.addEventListener('load', function () {
            window.print();
        });
    </script>

</body>

</html>
