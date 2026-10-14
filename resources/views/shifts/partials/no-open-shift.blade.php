{{--
    "No shift is open" prompt.

    Self-contained on purpose: it is meant to be dropped into the till header (or
    anywhere else) with a bare @include and no data passed in. It looks up the
    signed-in user's open shift itself and renders *nothing at all* when one is
    open, so the host page does not have to guard the include.

        @include('shifts.partials.no-open-shift')

    Pass $openShift explicitly if the host page has already loaded it and wants to
    save the query:

        @include('shifts.partials.no-open-shift', ['openShift' => $open])

    It appears on both the till and the shifts screen, so it is styled as a
    notice panel in the house style rather than as a page section: the host
    page owns the heading above it.
--}}
@php
    $openShift = $openShift
        ?? (auth()->check()
            ? \App\Models\Shift::open()->where('user_id', auth()->id())->first()
            : null);
@endphp

@if (auth()->check() && !$openShift)
    <div class="rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200 dark:bg-amber-950 dark:ring-amber-800">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 items-start gap-3">
                <span class="mt-0.5 shrink-0 text-amber-600 dark:text-amber-400">
                    <x-icon name="clock" size="5" />
                </span>
                <div class="min-w-0">
                    <p class="font-semibold text-amber-900 dark:text-amber-100">No shift is open</p>
                    <p class="mt-0.5 text-sm text-amber-800 dark:text-amber-200">
                        Open one with the cash already in the drawer, so the end-of-day count has something to balance against.
                    </p>
                </div>
            </div>

            <form method="POST" action="{{ route('shifts.open') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <div>
                    <label for="no-open-shift-float" class="mb-1 block text-xs font-medium text-amber-800 dark:text-amber-200">
                        Opening float ({{ currency() }})
                    </label>
                    <input id="no-open-shift-float" type="number" name="opening_float" step="0.01" min="0"
                           value="0" required inputmode="decimal"
                           class="min-h-touch w-32 rounded-md border-gray-300 bg-white text-right tabular-nums shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                </div>

                <button type="submit"
                        class="inline-flex min-h-touch items-center gap-1.5 rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-700">
                    Open shift
                </button>
            </form>
        </div>
    </div>
@endif
