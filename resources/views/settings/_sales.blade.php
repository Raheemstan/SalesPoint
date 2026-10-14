@php
    /*
    | Sales tab: the rules the till enforces.
    */
    $label = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
    $control = 'w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm tabular-nums'
        . ' focus:border-primary-500 focus:ring-primary-500'
        . ' dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
    $hint = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $error = 'mt-1 text-xs text-red-600 dark:text-red-400';
    $check = 'mt-0.5 h-5 w-5 shrink-0 rounded border-gray-300 text-primary-600'
        . ' focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700';

    $cashier = Str::lower(term('cashier'));
    $product = Str::lower(term('product'));
    $sale = Str::lower(term('sale'));
@endphp

<x-card title="Tax and discounts" subtitle="What the till adds on, and what it lets staff take off.">
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="tax_rate" class="{{ $label }}">Sales tax / VAT rate (%)</label>
            <input type="number" id="tax_rate" name="tax_rate" step="0.01" min="0" max="100"
                   value="{{ old('tax_rate', setting('tax_rate', 0)) }}" class="{{ $control }}">
            <p class="{{ $hint }}">Added on top of prices and charged after discounts. 0 means no tax.</p>
            @error('tax_rate')
                <p class="{{ $error }}">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="max_cashier_discount" class="{{ $label }}">
                Largest discount a {{ $cashier }} can give (% of the {{ $sale }})
            </label>
            <input type="number" id="max_cashier_discount" name="max_cashier_discount" step="0.01" min="0" max="100"
                   value="{{ old('max_cashier_discount', setting('max_cashier_discount', 10)) }}"
                   class="{{ $control }}">
            <p class="{{ $hint }}">
                Covers line and whole-{{ $sale }} discounts together, and is checked again at checkout.
                Managers and admins are not limited.
            </p>
            @error('max_cashier_discount')
                <p class="{{ $error }}">{{ $message }}</p>
            @enderror
        </div>
    </div>
</x-card>

<x-card title="Stock warnings" subtitle="When the till should say something is running low.">
    <div class="grid gap-4 sm:grid-cols-2 sm:items-start">
        <div>
            <label for="low_stock_threshold" class="{{ $label }}">Warn when stock reaches</label>
            <input type="number" id="low_stock_threshold" name="low_stock_threshold" min="1"
                   value="{{ old('low_stock_threshold', setting('low_stock_threshold', 5)) }}"
                   class="{{ $control }}">
            <p class="{{ $hint }}">Counted per {{ $product }}.</p>
            @error('low_stock_threshold')
                <p class="{{ $error }}">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-start gap-3 sm:pt-7">
            <input type="checkbox" id="enable_low_stock_warning" name="enable_low_stock_warning" value="1"
                   @checked(old('enable_low_stock_warning', setting('enable_low_stock_warning'))) class="{{ $check }}">
            <div class="min-w-0">
                <label for="enable_low_stock_warning"
                       class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                    Show low stock warnings
                </label>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    Only applies where stock is tracked at all - see the <strong>stock</strong> feature on
                    the Business tab.
                </p>
            </div>
        </div>
    </div>
</x-card>
