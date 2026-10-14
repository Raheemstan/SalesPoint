@php
    /*
    | Receipt tab: what goes on the receipt, not how it is drawn. The on-screen
    | receipt and the thermal printer path read these settings; both live
    | elsewhere in the app and neither is edited from here.
    */
    $label = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
    $control = 'w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm'
        . ' focus:border-primary-500 focus:ring-primary-500'
        . ' dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
    $hint = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $error = 'mt-1 text-xs text-red-600 dark:text-red-400';
    $check = 'mt-0.5 h-5 w-5 shrink-0 rounded border-gray-300 text-primary-600'
        . ' focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700';

    // What goes on the receipt, as [name => [label, hint]].
    $flags = [
        'show_logo' => ['Show the logo on screen receipts', 'The receipt shown on this screen after a sale.'],
        'show_business_info' => ["Show the shop's name, address and phone", 'Taken from the Store tab.'],
        'receipt_show_logo' => ['Print the logo on the thermal receipt', 'Slower to print, and faint on some rolls.'],
    ];
@endphp

<x-card title="Paper and printing" subtitle="The roll this till prints on, and how it prints.">
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="receipt_width" class="{{ $label }}">Paper width</label>
            <select id="receipt_width" name="receipt_width" class="{{ $control }}">
                @foreach (\App\Http\Controllers\SettingController::RECEIPT_WIDTHS as $value => $text)
                    <option value="{{ $value }}"
                        @selected(old('receipt_width', setting('receipt_width', '80mm')) === $value)>{{ $text }}</option>
                @endforeach
            </select>
            @error('receipt_width')
                <p class="{{ $error }}">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="print_method" class="{{ $label }}">Print method</label>
            <select id="print_method" name="print_method" class="{{ $control }}">
                @foreach (['dom' => 'Browser print dialog', 'escpos' => 'Thermal printer (ESC/POS)'] as $value => $text)
                    <option value="{{ $value }}"
                        @selected(old('print_method', setting('print_method', 'dom')) === $value)>{{ $text }}</option>
                @endforeach
            </select>
            @error('print_method')
                <p class="{{ $error }}">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="printer_name" class="{{ $label }}">Printer name</label>
            <input type="text" id="printer_name" name="printer_name"
                   value="{{ old('printer_name', setting('printer_name', 'POS-58')) }}"
                   class="{{ $control }}">
            <p class="{{ $hint }}">
                The Windows share name of the thermal printer. Only used when the print method is
                ESC/POS - the browser dialog works anywhere.
            </p>
            @error('printer_name')
                <p class="{{ $error }}">{{ $message }}</p>
            @enderror
        </div>
    </div>
</x-card>

<x-card title="What appears on the receipt" subtitle="The same choices apply to a printed, emailed or shared receipt.">
    <div class="grid gap-x-8 sm:grid-cols-2">
        <div class="divide-y divide-gray-200 dark:divide-gray-700">
            @foreach ($flags as $name => [$text, $note])
                <div class="flex items-start gap-3 py-3">
                    <input type="checkbox" id="{{ $name }}" name="{{ $name }}" value="1"
                           @checked(old($name, setting($name))) class="{{ $check }}">
                    <div class="min-w-0">
                        <label for="{{ $name }}" class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ $text }}
                        </label>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $note }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="divide-y divide-gray-200 dark:divide-gray-700">
            <div class="flex items-start gap-3 py-3">
                <input type="checkbox" id="receipt_show_tax_number" name="receipt_show_tax_number" value="1"
                       @checked(old('receipt_show_tax_number', setting('receipt_show_tax_number'))) class="{{ $check }}">
                <div class="min-w-0">
                    <label for="receipt_show_tax_number"
                           class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                        Print the tax registration number
                    </label>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        Required on a receipt in some countries.
                    </p>
                </div>
            </div>

            <div class="py-3">
                <label for="receipt_tax_number" class="{{ $label }}">Tax registration number</label>
                <input type="text" id="receipt_tax_number" name="receipt_tax_number"
                       value="{{ old('receipt_tax_number', setting('receipt_tax_number')) }}" maxlength="50"
                       class="{{ $control }}">
                @error('receipt_tax_number')
                    <p class="{{ $error }}">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>

    <div class="mt-2 border-t border-gray-200 pt-4 dark:border-gray-700">
        <label for="receipt_footer" class="{{ $label }}">Footer line</label>
        <input type="text" id="receipt_footer" name="receipt_footer"
               value="{{ old('receipt_footer', setting('receipt_footer')) }}" maxlength="255"
               placeholder="Thank you. No refunds after 7 days." class="{{ $control }}">
        <p class="{{ $hint }}">
            Printed at the bottom of every receipt, and included when a receipt is emailed or shared on
            WhatsApp. Keep it to one line - a 58 mm roll is about 32 characters wide.
        </p>
        @error('receipt_footer')
            <p class="{{ $error }}">{{ $message }}</p>
        @enderror
    </div>
</x-card>

<x-card title="Sharing a receipt" subtitle="Sending a copy to a {{ Str::lower(term('customer')) }} on WhatsApp.">
    <div class="sm:max-w-sm">
        <label for="whatsapp_country_code" class="{{ $label }}">WhatsApp country code</label>
        <input type="text" id="whatsapp_country_code" name="whatsapp_country_code"
               value="{{ old('whatsapp_country_code', setting('whatsapp_country_code')) }}" maxlength="4"
               inputmode="numeric" placeholder="234" class="{{ $control }} tabular-nums">
        <p class="{{ $hint }}">
            Digits only, e.g. 234. Used to turn a local number like 080... into the full international
            number WhatsApp needs. Leave it empty if {{ Str::lower(term('customer', true)) }} numbers are
            already stored in full.
        </p>
        @error('whatsapp_country_code')
            <p class="{{ $error }}">{{ $message }}</p>
        @enderror
    </div>
</x-card>
