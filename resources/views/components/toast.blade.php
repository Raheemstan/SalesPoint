@props([
    'variant' => 'info',
    'title' => null,
    'timeout' => 4000,
    'dismissible' => true,
])

@php
    /*
    |----------------------------------------------------------------------
    | x-toast  -  a single auto-dismissing message
    |----------------------------------------------------------------------
    |
    |   <x-toast variant="success">Sale saved.</x-toast>
    |   <x-toast variant="error" :timeout="0">Printer not responding.</x-toast>
    |
    | A timeout of 0 means it stays until dismissed.
    |
    | This renders the toast itself, not where it sits. The layout provides the
    | fixed stack (#toast-stack) that positions them; flash messages and
    | window.toast() both go through that stack, so they all look the same.
    | Dropped on a page on its own it simply renders inline, like a notice.
    |
    | Classes are written out per variant - an interpolated "bg-{$variant}-50"
    | would not survive Tailwind's build-time scan.
    */
    $variants = [
        'success' => [
            'icon' => 'check-circle',
            'class' => 'bg-green-50 text-green-900 ring-green-200 dark:bg-green-950 dark:text-green-100 dark:ring-green-800',
            'iconClass' => 'text-green-600 dark:text-green-400',
        ],
        'error' => [
            'icon' => 'x-circle',
            'class' => 'bg-red-50 text-red-900 ring-red-200 dark:bg-red-950 dark:text-red-100 dark:ring-red-800',
            'iconClass' => 'text-red-600 dark:text-red-400',
        ],
        'warning' => [
            'icon' => 'alert-triangle',
            'class' => 'bg-amber-50 text-amber-900 ring-amber-200 dark:bg-amber-950 dark:text-amber-100 dark:ring-amber-800',
            'iconClass' => 'text-amber-600 dark:text-amber-400',
        ],
        'info' => [
            'icon' => 'info',
            'class' => 'bg-white text-gray-900 ring-gray-200 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700',
            'iconClass' => 'text-primary-600 dark:text-primary-400',
        ],
    ];

    $v = $variants[$variant] ?? $variants['info'];
@endphp

<div
    x-data="{ show: false }"
    x-init="$nextTick(() => show = true); @if ((int) $timeout > 0) setTimeout(() => show = false, {{ (int) $timeout }}) @endif"
    x-show="show"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 translate-y-2 sm:translate-x-2 sm:translate-y-0"
    x-transition:enter-end="opacity-100 translate-y-0 sm:translate-x-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0 translate-y-2 sm:translate-x-2 sm:translate-y-0"
    role="{{ $variant === 'error' ? 'alert' : 'status' }}"
    aria-live="{{ $variant === 'error' ? 'assertive' : 'polite' }}"
    {{ $attributes->merge([
        'class' => 'pointer-events-auto flex w-full items-start gap-3 rounded-xl p-3.5 shadow-lg ring-1 ' . $v['class'],
    ]) }}
>
    <span class="{{ $v['iconClass'] }} mt-0.5">
        <x-icon :name="$v['icon']" size="5" />
    </span>

    <div class="min-w-0 flex-1 text-sm">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div class="{{ $title ? 'mt-0.5' : '' }} break-words">{{ $slot }}</div>
    </div>

    @if ($dismissible)
        {{-- min-h/w-touch: a toast is dismissed with a fingertip on a till screen. --}}
        <button type="button" @click="show = false"
                class="-m-1.5 flex h-touch w-touch shrink-0 items-center justify-center rounded-lg opacity-60 transition hover:opacity-100 hover:bg-black/5 dark:hover:bg-white/10"
                aria-label="Dismiss">
            <x-icon name="x" size="4" />
        </button>
    @endif
</div>
