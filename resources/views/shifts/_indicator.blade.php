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
     *
     * It sits in the header bar, so it stays a single compact chip: no panel, no
     * page-level layout, and a fingertip-high tap target like its neighbours.
     */
    $shifts = app(\App\Services\ShiftService::class);
    $openShift = auth()->check() ? $shifts->currentFor(auth()->user()) : null;
    $shiftRequired = $shifts->requiredToSell();

    // The shape is shared; the colours are written out per state, because an
    // interpolated class name compiles to nothing in the Tailwind build.
    $chip = 'inline-flex min-h-touch items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset transition';
@endphp

@if ($openShift)
    @php
        // The live figure, not the frozen one: a drawer that is still open has no
        // signed-off expectation yet.
        $expectedCash = $shifts->expectedByMethod($openShift)['cash']['expected'] ?? 0;
    @endphp

    <a href="{{ route('shifts.show', $openShift) }}"
       class="{{ $chip }} bg-green-100 text-green-800 ring-green-200 hover:bg-green-200 dark:bg-green-900/50 dark:text-green-200 dark:ring-green-800 dark:hover:bg-green-900/70"
       title="{{ $openShift->user->name }} opened this drawer {{ $openShift->opened_at->diffForHumans() }}">
        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-green-500" aria-hidden="true"></span>
        <span class="hidden whitespace-nowrap tabular-nums sm:inline">{{ money($expectedCash) }} expected</span>
        <span class="whitespace-nowrap sm:hidden">Shift open</span>
    </a>
@elseif ($shiftRequired)
    <a href="{{ route('shifts.index') }}"
       class="{{ $chip }} bg-amber-100 text-amber-800 ring-amber-200 hover:bg-amber-200 dark:bg-amber-900/50 dark:text-amber-200 dark:ring-amber-800 dark:hover:bg-amber-900/70">
        <x-icon name="alert-triangle" size="4" />
        <span class="hidden whitespace-nowrap sm:inline">No shift open</span>
        <span class="whitespace-nowrap sm:hidden">No shift</span>
    </a>
@endif
