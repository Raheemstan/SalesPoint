{{--
    Receipt tab: what goes on the receipt, not how it is drawn. The on-screen
    receipt and the thermal printer path read these settings; both live
    elsewhere in the app and neither is edited from here.
--}}
<x-section title="Paper and printing">
    <div class="grid md:grid-cols-2 gap-4">
        <x-select name="receipt_width" label="Paper width"
            :options="\App\Http\Controllers\SettingController::RECEIPT_WIDTHS"
            :value="setting('receipt_width', '80mm')" />

        <x-select name="print_method" label="Print method"
            :options="['dom' => 'Browser print dialog', 'escpos' => 'Thermal printer (ESC/POS)']"
            :value="setting('print_method', 'dom')" />

        <x-input name="printer_name" label="Printer name (ESC/POS only)"
            :value="setting('printer_name', 'POS-58')" />
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400">
        The printer name is the Windows share name of the thermal printer, and is only used when the
        print method is ESC/POS. The browser dialog works anywhere.
    </p>
</x-section>

<x-section title="What appears on the receipt">
    <div class="grid md:grid-cols-2 gap-x-8">
        <div>
            <x-checkbox name="show_logo" label="Show the logo on screen receipts" :checked="setting('show_logo')" />
            <x-checkbox name="show_business_info" label="Show the shop's name, address and phone"
                :checked="setting('show_business_info')" />
            <x-checkbox name="receipt_show_logo" label="Print the logo on the thermal receipt"
                :checked="setting('receipt_show_logo')" />
        </div>
        <div>
            <x-checkbox name="receipt_show_tax_number" label="Print the tax registration number"
                :checked="setting('receipt_show_tax_number')" />
            <x-input name="receipt_tax_number" label="Tax registration number"
                :value="setting('receipt_tax_number')" maxlength="50" />
        </div>
    </div>

    <x-input name="receipt_footer" label="Footer line (thank-you message, returns policy)"
        :value="setting('receipt_footer')" maxlength="255" />
    <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">
        Printed at the bottom of every receipt, and included when a receipt is emailed or shared on
        WhatsApp. Keep it to one line - a 58 mm roll is about 32 characters wide.
    </p>
</x-section>

<x-section title="Sharing a receipt">
    <div class="md:w-1/2">
        <x-input name="whatsapp_country_code" label="WhatsApp country code (digits only, e.g. 234)"
            :value="setting('whatsapp_country_code')" maxlength="4" />
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">
        Used to turn a local number like 080... into the full international number WhatsApp needs.
        Leave it empty if customer numbers are already stored in full.
    </p>
</x-section>
