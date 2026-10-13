@extends('layouts.app')

@section('title', 'Count Sheet ' . $stockTake->reference)

@section('content')
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
    @endphp

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

    <div class="space-y-4"
         x-data="{
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
                 return this.symbol + Number(value).toLocaleString(undefined, {
                     minimumFractionDigits: this.decimals, maximumFractionDigits: this.decimals
                 });
             },
         }">

        {{-- Header --}}
        <div class="bg-white dark:bg-gray-800 rounded shadow p-4 print-sheet">
            <div class="flex flex-wrap justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <h2 class="text-lg font-semibold font-mono">{{ $stockTake->reference }}</h2>
                        @include('stock-takes._status', ['status' => $stockTake->status])
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">
                        Counted by {{ $stockTake->user->name ?? 'unknown' }},
                        started {{ $stockTake->created_at->format('d M Y H:i') }}
                        @if ($stockTake->approved_at)
                            &middot; approved by {{ $stockTake->approver->name ?? 'unknown' }}
                            on {{ $stockTake->approved_at->format('d M Y H:i') }}
                        @endif
                    </p>
                    @if ($stockTake->note)
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $stockTake->note }}</p>
                    @endif
                </div>

                <div class="flex flex-wrap items-start gap-2 no-print">
                    <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300 px-2 py-2">
                        <input type="checkbox" x-model="blind" class="rounded border-gray-300 dark:border-gray-600">
                        Blind count
                    </label>
                    <button type="button" onclick="window.print()"
                            class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                        Print sheet
                    </button>

                    @can('approve', $stockTake)
                        <form method="POST" action="{{ route('stock-takes.approve', $stockTake) }}"
                              onsubmit="return confirm('Approve this count? Stock will be corrected and cannot be un-approved.')">
                            @csrf
                            <button type="submit" class="px-4 py-2 rounded bg-green-600 hover:bg-green-700 text-white text-sm">
                                Approve &amp; correct stock
                            </button>
                        </form>
                    @endcan

                    @can('delete', $stockTake)
                        <form method="POST" action="{{ route('stock-takes.destroy', $stockTake) }}"
                              onsubmit="return confirm('Cancel this count sheet?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="px-4 py-2 rounded border border-red-300 text-red-600 dark:border-red-800 dark:text-red-400 text-sm">
                                Cancel sheet
                            </button>
                        </form>
                    @endcan
                </div>
            </div>

            {{-- Why the Approve button may be missing: say it rather than leave a gap. --}}
            @if ($stockTake->isEditable() && !$canApprove)
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400 no-print">
                    A count has to be approved by somebody other than the person who counted it.
                    Ask another manager or an admin to approve this sheet.
                </p>
            @endif
        </div>

        {{-- Summary: the figures a manager is actually asked about. --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Lines counted</div>
                <div class="text-xl font-semibold">
                    <span x-text="countedLines()">{{ $summary['counted'] }}</span> / {{ $summary['lines'] }}
                </div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Variance (units)</div>
                <div class="text-xl font-semibold" x-text="totalUnits()">{{ $summary['variance_units'] }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Variance value</div>
                <div class="text-xl font-semibold"
                     :class="totalValue() < 0 ? 'text-red-600 dark:text-red-400' : (totalValue() > 0 ? 'text-green-600 dark:text-green-400' : '')"
                     x-text="money(totalValue())">{{ money($summary['variance_value']) }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4">
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400">Shrinkage (losses only)</div>
                <div class="text-xl font-semibold text-red-600 dark:text-red-400">{{ money($summary['shrinkage_value']) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $summary['short_lines'] }} short, {{ $summary['over_lines'] }} over</div>
            </div>
        </div>

        {{-- The sheet --}}
        <form method="POST" action="{{ route('stock-takes.update', $stockTake) }}"
              class="bg-white dark:bg-gray-800 rounded shadow p-4 print-sheet">
            @csrf @method('PUT')

            <div class="overflow-x-auto">
                <table class="w-full table-auto text-sm">
                    <thead class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                        <tr>
                            <th class="p-2 text-left">{{ term('product') }}</th>
                            <th class="p-2 text-left">SKU</th>
                            <th class="p-2 text-right">System</th>
                            <th class="p-2 text-right">Counted</th>
                            <th class="p-2 text-right">Variance</th>
                            <th class="p-2 text-right">Unit cost</th>
                            <th class="p-2 text-right">Variance value</th>
                            <th class="p-2 text-left">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stockTake->items as $index => $item)
                            <tr class="border-t dark:border-gray-700">
                                <td class="p-2">
                                    {{ $item->product->name ?? 'Deleted item' }}
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $item->product->unit ?? '' }}</span>
                                </td>
                                <td class="p-2 font-mono text-xs">{{ $item->product->sku ?? '-' }}</td>
                                <td class="p-2 text-right" :class="blind ? 'print:invisible' : ''">
                                    {{ $item->system_quantity }}
                                </td>
                                <td class="p-2 text-right">
                                    @if ($editable)
                                        <input type="number" min="0" step="1"
                                               name="counts[{{ $item->id }}]"
                                               x-model="lines[{{ $index }}].counted"
                                               class="count-box w-24 px-2 py-1 text-right border rounded dark:bg-gray-900 dark:border-gray-600">
                                    @else
                                        {{ $item->counted_quantity ?? '-' }}
                                    @endif
                                </td>
                                <td class="p-2 text-right font-medium"
                                    :class="{
                                        'text-red-600 dark:text-red-400': variance(lines[{{ $index }}]) < 0,
                                        'text-green-600 dark:text-green-400': variance(lines[{{ $index }}]) > 0,
                                    }"
                                    x-text="varianceText(lines[{{ $index }}])">{{ $item->isCounted() ? $item->variance : '-' }}</td>
                                <td class="p-2 text-right">{{ money($item->cost_price) }}</td>
                                <td class="p-2 text-right" x-text="money(varianceValue(lines[{{ $index }}]))">
                                    {{ money($item->isCounted() ? $item->variance_value : 0) }}
                                </td>
                                <td class="p-2">
                                    @if ($editable)
                                        <input type="text" maxlength="255" name="notes[{{ $item->id }}]"
                                               value="{{ $item->note }}" placeholder="e.g. 2 broken"
                                               class="w-40 px-2 py-1 border rounded dark:bg-gray-900 dark:border-gray-600">
                                    @else
                                        {{ $item->note }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($editable)
                <div class="flex flex-wrap justify-end gap-2 mt-4 no-print">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mr-auto self-center">
                        A blank box means "not counted" - which is not the same as counting zero.
                        Nothing moves until the sheet is approved.
                    </p>
                    <button type="submit" name="action" value="save"
                            class="px-4 py-2 rounded border border-gray-300 dark:border-gray-600 text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                        Save counts
                    </button>
                    <button type="submit" name="action" value="submit"
                            class="px-4 py-2 rounded bg-blue-600 hover:bg-blue-700 text-white text-sm">
                        Submit for approval
                    </button>
                </div>
            @endif
        </form>

        @if ($stockTake->isApproved())
            <div class="bg-white dark:bg-gray-800 rounded shadow p-4 no-print">
                <h3 class="font-semibold mb-2">Corrections made by this count</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $stockTake->adjustments()->count() }} adjustment(s) were written, each with its own inventory
                    movement. <a href="{{ route('adjustments.index', ['reason' => 'count']) }}"
                                 class="text-blue-600 dark:text-blue-400 hover:underline">See the adjustment history</a>.
                </p>
            </div>
        @endif
    </div>
@endsection
