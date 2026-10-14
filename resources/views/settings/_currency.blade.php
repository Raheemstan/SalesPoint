@php
    $symbol = (string) setting('currency_symbol', config('sectors.currency.symbol'));
    $decimals = (int) setting('currency_decimals', config('sectors.currency.decimals'));

    $label = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
    $control = 'w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm'
        . ' focus:border-primary-500 focus:ring-primary-500'
        . ' dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
    $hint = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $error = 'mt-1 text-xs text-red-600 dark:text-red-400';
@endphp

{{--
    The whole app reads these through currency() and money(); no view anywhere
    hardcodes a symbol, because the same install is sold into different
    countries. The two inputs below are the one place the symbol is typed at
    all, and the live example is formatted in JavaScript from whatever is in
    the boxes, so the shop can see the format before saving it.
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
    <x-card title="Currency" subtitle="How money is written everywhere in the app.">
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="currency_symbol" class="{{ $label }}">Symbol</label>
                <input type="text" id="currency_symbol" name="currency_symbol"
                       value="{{ old('currency_symbol', $symbol) }}" maxlength="8"
                       x-model="symbol" class="{{ $control }}">
                <p class="{{ $hint }}">Shown in front of every amount.</p>
                @error('currency_symbol')
                    <p class="{{ $error }}">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="currency_code" class="{{ $label }}">Code</label>
                <input type="text" id="currency_code" name="currency_code"
                       value="{{ old('currency_code', setting('currency_code', config('sectors.currency.code'))) }}"
                       maxlength="3" class="{{ $control }}">
                <p class="{{ $hint }}">Three letters, e.g. NGN, KES, GHS.</p>
                @error('currency_code')
                    <p class="{{ $error }}">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="currency_decimals" class="{{ $label }}">Decimal places</label>
                <input type="number" id="currency_decimals" name="currency_decimals" min="0" max="4"
                       value="{{ old('currency_decimals', $decimals) }}"
                       x-model="decimals" class="{{ $control }} tabular-nums">
                <p class="{{ $hint }}">0 for whole units only.</p>
                @error('currency_decimals')
                    <p class="{{ $error }}">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="mt-4 rounded-lg bg-gray-50 p-3 ring-1 ring-gray-200 dark:bg-gray-700/50 dark:ring-gray-600">
            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Example
            </p>
            <p class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                <span x-text="example(1250.5)">{{ money(1250.5) }}</span>
                <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                    and <span x-text="example(99)">{{ money(99) }}</span>
                </span>
            </p>
            <p class="{{ $hint }}">
                Saved, this reads as {{ money(1250.5) }} everywhere in the app - receipts, reports and
                the till. The code is what appears on exports and invoices where a symbol is ambiguous.
            </p>
        </div>
    </x-card>
</div>
