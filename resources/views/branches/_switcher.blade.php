{{--
    The branch switcher, beside the open-shift pill in the header.

    IT RENDERS NOTHING unless there is a real choice to make: no multi-branch
    feature, or one branch, or a cashier tied to a single shop, and this file
    outputs not one byte. That is what keeps a single-site shop's interface
    exactly as it was.

    Posting it clears the cart (see BranchController::switch): a half-rung-up
    sale belongs to the floor it was started on.
--}}
@php
    $switchable = feature('branches') && auth()->check()
        ? auth()->user()->availableBranches()
        : collect();
    $activeBranch = $switchable->count() > 1 ? app(\App\Services\BranchContext::class)->current() : null;
@endphp

@if ($activeBranch && $switchable->count() > 1)
    <form method="POST" action="{{ route('branches.switch') }}" class="flex items-center">
        @csrf
        <label for="active-branch" class="sr-only">Branch</label>
        <select id="active-branch" name="branch_id" onchange="this.form.submit()"
                title="The branch you are working at"
                class="min-h-touch max-w-[9rem] truncate rounded-lg border-gray-300 bg-white py-0 pl-2 pr-7 text-sm
                       font-medium dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
            @foreach ($switchable as $option)
                <option value="{{ $option->id }}" @selected($option->id === $activeBranch->id)>
                    {{ $option->code }} &middot; {{ $option->name }}
                </option>
            @endforeach
        </select>

        {{-- Still submits with JavaScript off, and for anyone navigating by keyboard. --}}
        <noscript>
            <button type="submit" class="ml-1 min-h-touch rounded-lg border border-gray-300 px-2 text-sm dark:border-gray-600">
                Go
            </button>
        </noscript>
    </form>
@endif
