@php
    /*
    | Security tab. Admin-only links stay guarded: managers reach this tab too,
    | and a test asserts that a manager never sees the audit log URL.
    */
    $label = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200';
    $control = 'w-full min-h-touch rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm tabular-nums'
        . ' focus:border-primary-500 focus:ring-primary-500'
        . ' dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
    $hint = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $error = 'mt-1 text-xs text-red-600 dark:text-red-400';
    $check = 'mt-0.5 h-5 w-5 shrink-0 rounded border-gray-300 text-primary-600'
        . ' focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700';

    $cashiers = Str::lower(term('cashier', true));
    $sales = Str::lower(term('sale', true));
@endphp

<x-card title="Sessions" subtitle="A till left unattended on a shop counter.">
    <div class="sm:max-w-sm">
        <label for="auto_logout_time" class="{{ $label }}">Log out after this many idle minutes</label>
        <input type="number" id="auto_logout_time" name="auto_logout_time" min="1" max="1440"
               value="{{ old('auto_logout_time', setting('auto_logout_time')) }}" class="{{ $control }}">
        <p class="{{ $hint }}">
            Blank or 0 never logs anyone out. Counted from the last click or keypress.
        </p>
        @error('auto_logout_time')
            <p class="{{ $error }}">{{ $message }}</p>
        @enderror
    </div>
</x-card>

<x-card title="Record keeping" subtitle="What the app writes down about what people do.">
    <div class="divide-y divide-gray-200 dark:divide-gray-700">
        <div class="flex items-start gap-3 pb-3">
            <input type="checkbox" id="enable_audit_log" name="enable_audit_log" value="1"
                   @checked(old('enable_audit_log', setting('enable_audit_log', '1'))) class="{{ $check }}">
            <div class="min-w-0">
                <label for="enable_audit_log" class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                    Keep an audit log of who did what
                </label>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    Records logins, price changes, refunds, voids, stock adjustments, user changes and
                    settings changes. Switching it off stops new entries; it does not delete the ones
                    already recorded.
                </p>
            </div>
        </div>

        <div class="flex items-start gap-3 pt-3">
            <input type="checkbox" id="require_open_shift" name="require_open_shift" value="1"
                   @checked(old('require_open_shift', setting('require_open_shift'))) class="{{ $check }}">
            <div class="min-w-0">
                <label for="require_open_shift" class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                    Require an open shift before selling
                </label>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    Stops {{ $cashiers }} ringing up {{ $sales }} outside a counted drawer session, so
                    every {{ Str::lower(term('sale')) }} belongs to a shift that can be cashed up. Off by
                    default.
                </p>
            </div>
        </div>
    </div>
</x-card>

<x-card title="Two-factor authentication" subtitle="A code from a phone app on top of a password.">
    <div class="flex items-start gap-3">
        <input type="checkbox" id="require_2fa_admins" name="require_2fa_admins" value="1"
               @checked(old('require_2fa_admins', setting('require_2fa_admins'))) class="{{ $check }}">
        <div class="min-w-0">
            <label for="require_2fa_admins" class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                Require two-factor authentication for administrators
            </label>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                Admins without it set up are taken to the setup screen when they sign in, and cannot
                switch it off again while this is on. Everyone else can still turn it on for themselves.
            </p>
        </div>
    </div>

    <x-slot:footer>
        <a href="{{ route('two-factor.show') }}"
           class="inline-flex min-h-touch items-center gap-1.5 font-medium text-primary-600 hover:underline dark:text-primary-400">
            <x-icon name="key" size="4" />
            Set up two-factor authentication on my own account
            <x-icon name="chevron-right" size="4" />
        </a>
    </x-slot:footer>
</x-card>

@role('admin')
    <x-card title="Audit log" subtitle="Every recorded action, with the IP address it came from. Admins only.">
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
            Filterable by person, action, table and date, and exportable as a spreadsheet.
        </p>

        <a href="{{ route('settings.audit') }}"
           class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
            <x-icon name="shield" size="4" />
            Open the audit log
        </a>
    </x-card>
@endrole
