{{--
    Included from the app layout. Guard every reference: the shared `$pilotWarning`
    variable only exists on requests where EnforcePilotLock actually set it (the
    last 10 days of an active pilot), so this renders nothing at all otherwise -
    including on every normal paid install, where $pilotWarning is never defined.
--}}
@isset($pilotWarning)
    <div class="bg-amber-50 border-b border-amber-300 text-amber-900 text-sm px-4 py-2 text-center dark:bg-amber-900/40 dark:border-amber-700 dark:text-amber-200">
        <strong>Pilot ends in {{ $pilotWarning->daysLeft }} {{ Str::plural('day', $pilotWarning->daysLeft) }}</strong>
        ({{ $pilotWarning->expiresAt->toFormattedDateString() }}).
        Contact support to continue using without interruption.
    </div>
@endisset
