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
--}}
@php
    $openShift = $openShift
        ?? (auth()->check()
            ? \App\Models\Shift::open()->where('user_id', auth()->id())->first()
            : null);
@endphp

@if (auth()->check() && !$openShift)
    <div class="bg-white dark:bg-gray-800 rounded shadow p-4 border-l-4 border-yellow-500">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="font-semibold text-gray-800 dark:text-gray-100">No shift is open</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Open one with the cash already in the drawer, so the end-of-day count has something to balance against.
                </p>
            </div>

            <form method="POST" action="{{ route('shifts.open') }}" class="flex items-end gap-2">
                @csrf
                <label class="text-xs text-gray-500 dark:text-gray-400">
                    Opening float ({{ currency() }})
                    <input type="number" name="opening_float" step="0.01" min="0" value="0" required
                           class="mt-1 block w-32 rounded-md border border-gray-300 dark:border-gray-600 px-2 py-1 bg-white dark:bg-gray-700">
                </label>
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded">Open shift</button>
            </form>
        </div>
    </div>
@endif
