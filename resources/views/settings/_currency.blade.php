@php
    $symbol = (string) setting('currency_symbol', config('sectors.currency.symbol'));
    $decimals = (int) setting('currency_decimals', config('sectors.currency.decimals'));
@endphp

{{--
    The whole app reads these through currency() and money(); no view anywhere
    hardcodes a symbol, because the same install is sold into different
    countries. The live example below is formatted in JavaScript from whatever
    is in the boxes, so the shop can see the format before saving it.
--}}
<div x-data="{
    symbol: @js($symbol),
    decimals: {{ $decimals }},
    example(amount) {
        const places = Math.max(0, Math.min(4, Number(this.decimals) || 0));
        return this.symbol + amount.toLocaleString(undefined, {
            minimumFractionDigits: places,
            maximumFractionDigits: places,
        });
    },
}">
    <x-section title="Currency">
        <div class="grid md:grid-cols-3 gap-4">
            <x-input name="currency_symbol" label="Symbol" :value="$symbol" x-model="symbol" maxlength="8" />
            <x-input name="currency_code" label="Code (3 letters, e.g. NGN, KES, GHS)"
                :value="setting('currency_code', config('sectors.currency.code'))" maxlength="3" />
            <x-input name="currency_decimals" label="Decimal places" type="number" min="0" max="4"
                :value="$decimals" x-model="decimals" />
        </div>

        <div class="rounded border border-gray-200 bg-gray-50 p-3 dark:border-gray-600 dark:bg-gray-700/50">
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Example</p>
            <p class="text-lg font-semibold text-gray-800 dark:text-gray-100">
                <span x-text="example(1250.5)"></span>
                <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                    and <span x-text="example(99)"></span>
                </span>
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Saved, this reads as {{ money(1250.5) }} everywhere in the app - receipts, reports and
                the till. The code is what appears on exports and invoices where a symbol is ambiguous.
            </p>
        </div>
    </x-section>
</div>
