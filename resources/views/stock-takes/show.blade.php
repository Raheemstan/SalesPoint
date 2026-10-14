@extends('layouts.app')

@section('title', 'Count Sheet ' . $stockTake->reference)

@php
    $decimals = (int) setting('currency_decimals', config('sectors.currency.decimals'));
    $editable = $stockTake->isEditable() && auth()->user()->can('update', $stockTake);

    // The lines the browser needs to show a running variance while staff type.
    $lines = $stockTake->items->map(fn($item) => [
        'id' => $item->id,
        'system' => (int) $item->system_quantity,
        'cost' => (float) $item->cost_price,
        'counted' => $item->counted_quantity === null ? '' : (string) $item->counted_quantity,
    ])->values();

    // money() puts the symbol first, so a bare negative would read "<symbol>-100".
    $amount = fn($value) => ($value < 0 ? '-' : '') . money(abs($value));

    /*
    | Three of the tiles below count up live as staff type, which x-stat has no
    | prop for. x-stat prints its value with {{ }}, and e() hands an Htmlable
    | straight through, so the Alpine-bound figure can sit inside the component's
    | own styling instead of a hand-rolled copy of it.
    */
    $countedTile = new \Illuminate\Support\HtmlString(
        '<span x-text="countedLines()">' . e($summary['counted']) . '</span> / ' . e($summary['lines'])
    );

    $unitsTile = new \Illuminate\Support\HtmlString(
        '<span x-text="totalUnits()">' . e($summary['variance_units']) . '</span>'
    );

    // Red for a loss, green for a gain - the same colouring as the Variance column.
    $valueTile = new \Illuminate\Support\HtmlString(
        '<span :class="totalValue() < 0 ? \'text-red-700 dark:text-red-400\''
        . ' : (totalValue() > 0 ? \'text-green-700 dark:text-green-400\' : \'\')"'
        . ' x-text="money(totalValue())">' . e($amount($summary['variance_value'])) . '</span>'
    );

    $shrinkageTile = new \Illuminate\Support\HtmlString(
        '<span class="text-red-700 dark:text-red-400">' . e($amount($summary['shrinkage_value'])) . '</span>'
    );

    $heading = collect([
        'Counted by ' . ($stockTake->user->name ?? 'unknown')
            . ', started ' . $stockTake->created_at->format('d M Y H:i'),
        $stockTake->approved_at
            ? 'approved by ' . ($stockTake->approver->name ?? 'unknown')
                . ' on ' . $stockTake->approved_at->format('d M Y H:i')
            : null,
    ])->filter()->implode(' · ');
@endphp

@section('content')
    {{-- Staff count on paper, so this page has to print as a usable sheet. --}}
    <style>
        @media print {
            aside, header, .no-print { display: none !important; }
            main { overflow: visible !important; padding: 0 !important; }
            body { background: #fff !important; color: #000 !important; }
            .print-sheet { box-shadow: none !important; border: 0 !important; }
            table { font-size: 11px; }
            .count-box { border: 1px solid #999 !important; background: #fff !important; min-width: 70px; }
        }
    </style>

    <x-page-header :title="'Count sheet ' . $stockTake->reference" :subtitle="$heading"
                   :back="route('stock-takes.index')" back-label="Stock takes">
        <x-slot:actions>
            @include('stock-takes._status', ['status' => $stockTake->status, 'size' => 'md'])

            <span class="no-print flex flex-wrap items-center gap-2">
                <button type="button" onclick="window.print()"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                    <x-icon name="download" size="4" />
                    Print sheet
                </button>

                @can('approve', $stockTake)
                    <form method="POST" action="{{ route('stock-takes.approve', $stockTake) }}"
                          onsubmit="return confirm('Approve this count? Stock will be corrected and cannot be un-approved.')">
                        @csrf
                        <button type="submit"
                                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                            <x-icon name="check-circle" size="4" />
                            Approve &amp; correct stock
                        </button>
                    </form>
                @endcan

                @can('delete', $stockTake)
                    <form method="POST" action="{{ route('stock-takes.destroy', $stockTake) }}"
                          onsubmit="return confirm('Cancel this count sheet?')">
                        @csrf @method('DELETE')
                        <button type="submit"
                                class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                            <x-icon name="x" size="4" />
                            Cancel sheet
                        </button>
                    </form>
                @endcan
            </span>
        </x-slot:actions>
    </x-page-header>

    {{-- Why the Approve button may be missing: say it rather than leave a gap. --}}
    @if ($stockTake->isEditable() && !$canApprove)
        <div class="no-print mb-4 flex items-start gap-2.5 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <span class="mt-0.5 shrink-0 text-primary-600 dark:text-primary-400">
                <x-icon name="info" size="5" />
            </span>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                A count has to be approved by somebody other than the person who counted it.
                Ask another manager or an admin to approve this sheet.
            </p>
        </div>
    @endif

    <div x-data="{
             blind: false,
             lines: @js($lines),
             symbol: @js(currency()),
             decimals: {{ $decimals }},
             variance(line) {
                 return line.counted === '' || line.counted === null ? null : (parseInt(line.counted) - line.system);
             },
             varianceText(line) {
                 const v = this.variance(line);
                 return v === null || isNaN(v) ? '-' : (v > 0 ? '+' + v : String(v));
             },
             varianceValue(line) {
                 const v = this.variance(line);
                 return v === null || isNaN(v) ? 0 : v * line.cost;
             },
             totalUnits() {
                 return this.lines.reduce((sum, l) => sum + (this.variance(l) || 0), 0);
             },
             totalValue() {
                 return this.lines.reduce((sum, l) => sum + this.varianceValue(l), 0);
             },
             countedLines() {
                 return this.lines.filter(l => l.counted !== '' &amp;&amp; l.counted !== null).length;
             },
             money(value) {
                 return (value < 0 ? '-' : '') + this.symbol + Math.abs(Number(value)).toLocaleString(undefined, {
                     minimumFractionDigits: this.decimals, maximumFractionDigits: this.decimals
                 });
             },
         }">

        {{-- Summary: the figures a manager is actually asked about. --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-stat label="Lines counted" :value="$countedTile" icon="clipboard-check" />
            <x-stat label="Variance (units)" :value="$unitsTile" icon="scale" />
            <x-stat label="Variance value" :value="$valueTile" icon="coins" />
            <x-stat label="Shrinkage (losses only)" :value="$shrinkageTile" icon="alert-triangle"
                    :hint="$summary['short_lines'] . ' short, ' . $summary['over_lines'] . ' over'" />
        </div>

        {{-- The sheet --}}
        <form method="POST" action="{{ route('stock-takes.update', $stockTake) }}">
            @csrf @method('PUT')

            <x-card :title="number_format($summary['lines']) . ' ' . Str::plural('line', $summary['lines'])"
                    :subtitle="$stockTake->note" :padding="false" class="print-sheet mt-4">
                <x-slot:actions>
                    <label class="no-print flex min-h-touch cursor-pointer items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <input type="checkbox" x-model="blind"
                               class="rounded border-gray-300 text-primary-600 dark:border-gray-600 dark:bg-gray-900">
                        Blind count
                    </label>
                </x-slot:actions>

                <x-table>
                    <x-slot:head>
                        <tr>
                            <th>{{ term('product') }}</th>
                            <th>SKU</th>
                            <th class="text-right">System</th>
                            <th class="text-right">Counted</th>
                            <th class="text-right">Variance</th>
                            <th class="text-right">Unit cost</th>
                            <th class="text-right">Variance value</th>
                            <th>Note</th>
                        </tr>
                    </x-slot:head>

                    @foreach ($stockTake->items as $index => $item)
                        @php $name = $item->product->name ?? 'Deleted item'; @endphp
                        <tr>
                            <td>
                                <span class="font-medium">{{ $name }}</span>
                                @if ($item->product->unit ?? null)
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $item->product->unit }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap font-mono text-xs text-gray-500 dark:text-gray-400">
                                {{ $item->product->sku ?? '-' }}
                            </td>
                            <td class="text-right tabular-nums" :class="blind ? 'print:invisible' : ''">
                                {{ $item->system_quantity }}
                            </td>
                            <td class="text-right tabular-nums">
                                @if ($editable)
                                    {{-- A long data-entry column on a tablet: the box has to be tappable. --}}
                                    <input type="number" min="0" step="1"
                                           name="counts[{{ $item->id }}]"
                                           x-model="lines[{{ $index }}].counted"
                                           aria-label="Counted quantity for {{ $name }}"
                                           class="count-box min-h-touch w-24 rounded-md border-gray-300 bg-white px-2 py-1.5 text-right text-sm tabular-nums shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                @else
                                    {{ $item->counted_quantity ?? '-' }}
                                @endif
                            </td>
                            <td class="text-right font-medium tabular-nums"
                                :class="{
                                    'text-red-700 dark:text-red-400': variance(lines[{{ $index }}]) < 0,
                                    'text-green-700 dark:text-green-400': variance(lines[{{ $index }}]) > 0,
                                }"
                                x-text="varianceText(lines[{{ $index }}])">{{ $item->isCounted() ? $item->variance : '-' }}</td>
                            <td class="text-right tabular-nums text-gray-500 dark:text-gray-400">
                                {{ money($item->cost_price) }}
                            </td>
                            <td class="text-right tabular-nums"
                                x-text="money(varianceValue(lines[{{ $index }}]))">
                                {{ $amount($item->isCounted() ? $item->variance_value : 0) }}
                            </td>
                            <td>
                                @if ($editable)
                                    <input type="text" maxlength="255" name="notes[{{ $item->id }}]"
                                           value="{{ $item->note }}" placeholder="e.g. 2 broken"
                                           aria-label="Note for {{ $name }}"
                                           class="min-h-touch w-40 rounded-md border-gray-300 bg-white px-2 py-1.5 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                @else
                                    <span class="text-gray-500 dark:text-gray-400">{{ $item->note }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-table>

                @if ($editable)
                    <x-slot:footer>
                        <div class="no-print flex flex-wrap items-center justify-end gap-2">
                            <p class="mr-auto text-xs text-gray-500 dark:text-gray-400">
                                A blank box means "not counted" - which is not the same as counting zero.
                                Nothing moves until the sheet is approved.
                            </p>

                            <button type="submit" name="action" value="save"
                                    class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700">
                                Save counts
                            </button>

                            <button type="submit" name="action" value="submit"
                                    class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                                <x-icon name="upload" size="4" />
                                Submit for approval
                            </button>
                        </div>
                    </x-slot:footer>
                @endif
            </x-card>
        </form>

        @if ($stockTake->isApproved())
            <x-card title="Corrections made by this count" class="no-print mt-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $stockTake->adjustments()->count() }} adjustment(s) were written, each with its own inventory
                    movement. <a href="{{ route('adjustments.index', ['reason' => 'count']) }}"
                                 class="font-medium text-primary-600 hover:underline dark:text-primary-400">See the adjustment history</a>.
                </p>
            </x-card>
        @endif
    </div>
@endsection
