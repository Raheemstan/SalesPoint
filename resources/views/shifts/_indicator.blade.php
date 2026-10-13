@php
    /**
     * The open-shift chip in the app header.
     *
     * Included by layouts/partials/header.blade.php on every page, so it has to
     * look itself up and render nothing when there is nothing to say. Two states
     * only: a drawer is open (show whose, and what it should hold), or selling is
     * gated on a shift and none is open (say so, and link to where it is opened).
     *
     * A shop that does not run shifts sees nothing at all, which is why the
     * "no shift" case is tied to require_open_shift rather than shown to everyone.
     */
    $shifts = app(\App\Services\ShiftService::class);
    $openShift = auth()->check() ? $shifts->currentFor(auth()->user()) : null;
    $shiftRequired = $shifts->requiredToSell();
@endphp

@if ($openShift)
    @php
        // The live figure, not the frozen one: a drawer that is still open has no
        // signed-off expectation yet.
        $expectedCash = $shifts->expectedByMethod($openShift)['cash']['expected'] ?? 0;
    @endphp

    <a href="{{ route('shifts.show', $openShift) }}"
       class="inline-flex min-h-touch items-center gap-2 rounded-md border border-green-300 bg-green-50 px-3 py-1.5 text-sm text-green-800 transition hover:bg-green-100 dark:border-green-700 dark:bg-green-900/40 dark:text-green-200 dark:hover:bg-green-900/70"
       title="{{ $openShift->user->name }} opened this drawer {{ $openShift->opened_at->diffForHumans() }}">
        <span class="relative flex h-2 w-2 shrink-0">
            <span class="absolute inline-flex h-full w-full rounded-full bg-green-500 opacity-75"></span>
        </span>
        <span class="hidden sm:inline">{{ money($expectedCash) }} expected</span>
        <span class="sm:hidden">Shift open</span>
    </a>
@elseif ($shiftRequired)
    <a href="{{ route('shifts.index') }}"
       class="inline-flex min-h-touch items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-sm text-amber-900 transition hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-900/40 dark:text-amber-100 dark:hover:bg-amber-900/70">
        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 9v3.75m0 3.75h.008M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span class="hidden sm:inline">No shift open</span>
        <span class="sm:hidden">No shift</span>
    </a>
@endif
