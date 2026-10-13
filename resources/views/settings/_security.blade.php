{{-- Security tab. Admin-only links are guarded: managers reach this tab too. --}}
<x-section title="Sessions">
    <div class="md:w-1/2">
        <x-input name="auto_logout_time" label="Log out after this many idle minutes (blank or 0 = never)"
            type="number" min="1" max="1440" :value="setting('auto_logout_time')" />
    </div>
    <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">
        Counted from the last click or keypress. Worth setting on a till that sits on a shop counter.
    </p>
</x-section>

<x-section title="Record keeping">
    <x-checkbox name="enable_audit_log" label="Keep an audit log of who did what"
        :checked="setting('enable_audit_log', '1')" />
    <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2 mb-4">
        Records logins, price changes, refunds, voids, stock adjustments, user changes and settings
        changes. Switching it off stops new entries; it does not delete the ones already recorded.
    </p>

    <x-checkbox name="require_open_shift" label="Require an open shift before selling"
        :checked="setting('require_open_shift')" />
    <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">
        Stops cashiers ringing up sales outside a counted drawer session, so every sale belongs to a
        shift that can be cashed up. Off by default.
    </p>
</x-section>

<x-section title="Two-factor authentication">
    <x-checkbox name="require_2fa_admins" label="Require two-factor authentication for administrators"
        :checked="setting('require_2fa_admins')" />
    <p class="text-xs text-gray-500 dark:text-gray-400 -mt-2">
        Admins without it set up are taken to the setup screen when they sign in, and cannot switch it
        off again while this is on. Everyone else can still turn it on for themselves.
    </p>

    <a href="{{ route('two-factor.show') }}"
        class="inline-block mt-3 text-blue-600 dark:text-blue-400 hover:underline">
        Set up two-factor authentication on my own account &rarr;
    </a>
</x-section>

@role('admin')
    <x-section title="Audit log">
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">
            Every recorded action, filterable by person, action, table and date, with the IP address it
            came from. Admins only.
        </p>
        <a href="{{ route('settings.audit') }}"
            class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
            Open the audit log
        </a>
    </x-section>
@endrole
