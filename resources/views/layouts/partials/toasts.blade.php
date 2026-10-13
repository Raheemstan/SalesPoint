@php
    /*
    |--------------------------------------------------------------------------
    | Flash messages and validation errors, as toasts
    |--------------------------------------------------------------------------
    |
    | These used to be coloured boxes pushed into the top of the page body, with
    | classes built as "bg-{$color}-100". That is invisible to Tailwind's
    | build-time scanner, so the moment the CDN build went they rendered
    | unstyled. They are now a fixed stack of x-toast components whose classes
    | are written out literally, per variant.
    |
    | Everything that raises a message goes through the same stack: session
    | flashes and the validation errors below, plus anything a page raises with
    | window.toast('...', { variant: 'success' }).
    |
    */
    $flashes = collect([
        'success' => 'success',
        'status' => 'success',
        'error' => 'error',
        'warning' => 'warning',
        'info' => 'info',
        'message' => 'info',
    ])->map(fn ($variant, $key) => ['key' => $key, 'variant' => $variant])
        ->filter(fn ($flash) => filled(session($flash['key'])) && is_string(session($flash['key'])))
        ->map(fn ($flash) => [
            'message' => (string) session($flash['key']),
            'variant' => $flash['variant'],
            'timeout' => $flash['variant'] === 'error' ? 8000 : 4000,
        ])
        ->values();
@endphp

{{--
    pointer-events-none on the stack, auto on each toast, so a toast sitting
    over the page does not block the button underneath it.

    Full width at the bottom on a phone (where the thumb is), top right from sm
    up (where it does not cover the till's cart).
--}}
<div
    id="toast-stack"
    x-data="toastStack(@js($flashes))"
    class="pointer-events-none fixed inset-x-3 bottom-3 z-toast flex flex-col gap-2
           sm:inset-x-auto sm:bottom-auto sm:right-4 sm:top-20 sm:w-96"
>
    <template x-for="item in toasts" :key="item.id">
        <div
            x-show="item.show"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-2 sm:translate-x-3 sm:translate-y-0"
            x-transition:enter-end="opacity-100 translate-y-0 sm:translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0 translate-y-2 sm:translate-x-3 sm:translate-y-0"
            :role="item.variant === 'error' ? 'alert' : 'status'"
            :aria-live="liveness(item.variant)"
            class="pointer-events-auto flex w-full items-start gap-3 rounded-xl p-3.5 shadow-lg ring-1"
            {{-- Written out per variant: an interpolated class name would not
                 survive Tailwind's build. --}}
            :class="{
                'bg-green-50 text-green-900 ring-green-200 dark:bg-green-950 dark:text-green-100 dark:ring-green-800': item.variant === 'success',
                'bg-red-50 text-red-900 ring-red-200 dark:bg-red-950 dark:text-red-100 dark:ring-red-800': item.variant === 'error',
                'bg-amber-50 text-amber-900 ring-amber-200 dark:bg-amber-950 dark:text-amber-100 dark:ring-amber-800': item.variant === 'warning',
                'bg-white text-gray-900 ring-gray-200 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700': ! ['success', 'error', 'warning'].includes(item.variant),
            }"
        >
            <span class="mt-0.5"
                  :class="{
                      'text-green-600 dark:text-green-400': item.variant === 'success',
                      'text-red-600 dark:text-red-400': item.variant === 'error',
                      'text-amber-600 dark:text-amber-400': item.variant === 'warning',
                      'text-primary-600 dark:text-primary-400': ! ['success', 'error', 'warning'].includes(item.variant),
                  }">
                {{-- All four icons are in the markup; only the matching one is shown. --}}
                <span x-show="item.variant === 'success'"><x-icon name="check-circle" size="5" /></span>
                <span x-show="item.variant === 'error'"><x-icon name="x-circle" size="5" /></span>
                <span x-show="item.variant === 'warning'"><x-icon name="alert-triangle" size="5" /></span>
                <span x-show="! ['success', 'error', 'warning'].includes(item.variant)"><x-icon name="info" size="5" /></span>
            </span>

            <div class="min-w-0 flex-1 text-sm">
                <p x-show="item.title" x-text="item.title" class="font-semibold"></p>
                <div class="break-words" x-text="item.message"></div>
            </div>

            <button type="button" @click="dismiss(item.id)"
                    class="-m-1.5 flex h-touch w-touch shrink-0 items-center justify-center rounded-lg opacity-60 transition hover:bg-black/5 hover:opacity-100 dark:hover:bg-white/10"
                    aria-label="Dismiss">
                <x-icon name="x" size="4" />
            </button>
        </div>
    </template>
</div>

{{--
    Validation errors.

    A list of eight field errors does not belong in a toast that disappears
    after four seconds, so they stay on the page, above the content, and are
    dismissible. aria-live so a screen reader hears them after a failed submit.
--}}
@if ($errors->any())
    <div x-data="{ show: true }" x-show="show" role="alert" aria-live="assertive"
         class="mb-4 rounded-xl bg-red-50 p-4 ring-1 ring-red-200 dark:bg-red-950 dark:ring-red-800">
        <div class="flex items-start gap-3">
            <span class="mt-0.5 text-red-600 dark:text-red-400">
                <x-icon name="x-circle" size="5" />
            </span>

            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-red-900 dark:text-red-100">
                    {{ trans_choice('{1} There is a problem with one field.|[2,*] There are problems with :count fields.', $errors->count()) }}
                </p>
                <ul class="mt-1.5 list-disc space-y-0.5 pl-5 text-sm text-red-800 dark:text-red-200">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>

            <button type="button" @click="show = false"
                    class="-m-1.5 flex h-touch w-touch shrink-0 items-center justify-center rounded-lg text-red-700 opacity-70 hover:bg-red-100 hover:opacity-100 dark:text-red-300 dark:hover:bg-red-900/50"
                    aria-label="Dismiss">
                <x-icon name="x" size="4" />
            </button>
        </div>
    </div>
@endif
