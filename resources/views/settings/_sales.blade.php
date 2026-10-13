{{-- Sales tab: the rules the till enforces. --}}
<x-section title="Tax and discounts">
    <div class="grid md:grid-cols-2 gap-4">
        <x-input name="tax_rate" label="Sales tax / VAT rate (%)"
            type="number" step="0.01" min="0" max="100" :value="setting('tax_rate', 0)" />
        <x-input name="max_cashier_discount" label="Largest discount a cashier can give (% of the sale)"
            type="number" step="0.01" min="0" max="100" :value="setting('max_cashier_discount', 10)" />
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400">
        Tax is added on top of prices and charged after discounts; 0 means no tax. The discount limit
        covers line and whole-sale discounts together and is checked again at checkout - managers and
        admins are not limited.
    </p>
</x-section>

<x-section title="Stock warnings">
    <div class="grid md:grid-cols-2 gap-4 items-start">
        <x-input name="low_stock_threshold" label="Warn when stock reaches" type="number" min="1"
            :value="setting('low_stock_threshold', 5)" />
        <div class="pt-6">
            <x-checkbox name="enable_low_stock_warning" label="Show low stock warnings"
                :checked="setting('enable_low_stock_warning')" />
        </div>
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400">
        Counted per {{ strtolower(term('product')) }}. Only applies where stock is tracked at all - see
        the <strong>stock</strong> feature on the Business tab.
    </p>
</x-section>
