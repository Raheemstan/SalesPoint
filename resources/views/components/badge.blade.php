@props([
    'variant' => 'gray',
    'size' => 'md',
    'dot' => false,
])

@php
    /*
    |----------------------------------------------------------------------
    | x-badge  -  a status pill
    |----------------------------------------------------------------------
    |
    |   <x-badge variant="green">Completed</x-badge>
    |   <x-badge variant="red" dot>Voided</x-badge>
    |
    | Every variant's classes are written out in full. Building them as
    | "bg-{$variant}-100" would compile to nothing: Tailwind's scanner only
    | sees literal text, so the pill would come out unstyled.
    |
    | Variants are named after what they mean as well as by colour, so a page
    | can say `variant="success"` and not have to decide that success is green.
    |
    */
    $variants = [
        'gray' => 'bg-gray-100 text-gray-700 ring-gray-200 dark:bg-gray-700/50 dark:text-gray-200 dark:ring-gray-600',
        'green' => 'bg-green-100 text-green-800 ring-green-200 dark:bg-green-900/50 dark:text-green-200 dark:ring-green-800',
        'red' => 'bg-red-100 text-red-800 ring-red-200 dark:bg-red-900/50 dark:text-red-200 dark:ring-red-800',
        'amber' => 'bg-amber-100 text-amber-800 ring-amber-200 dark:bg-amber-900/50 dark:text-amber-200 dark:ring-amber-800',
        'orange' => 'bg-orange-100 text-orange-800 ring-orange-200 dark:bg-orange-900/50 dark:text-orange-200 dark:ring-orange-800',
        'blue' => 'bg-blue-100 text-blue-800 ring-blue-200 dark:bg-blue-900/50 dark:text-blue-200 dark:ring-blue-800',
        'indigo' => 'bg-indigo-100 text-indigo-800 ring-indigo-200 dark:bg-indigo-900/50 dark:text-indigo-200 dark:ring-indigo-800',
        'purple' => 'bg-purple-100 text-purple-800 ring-purple-200 dark:bg-purple-900/50 dark:text-purple-200 dark:ring-purple-800',
        'teal' => 'bg-teal-100 text-teal-800 ring-teal-200 dark:bg-teal-900/50 dark:text-teal-200 dark:ring-teal-800',
    ];

    // Meaning-based aliases.
    $aliases = [
        'success' => 'green',
        'error' => 'red',
        'danger' => 'red',
        'warning' => 'amber',
        'info' => 'blue',
        'neutral' => 'gray',
    ];

    $key = $aliases[$variant] ?? $variant;
    $colour = $variants[$key] ?? $variants['gray'];

    $dots = [
        'gray' => 'bg-gray-500',
        'green' => 'bg-green-500',
        'red' => 'bg-red-500',
        'amber' => 'bg-amber-500',
        'orange' => 'bg-orange-500',
        'blue' => 'bg-blue-500',
        'indigo' => 'bg-indigo-500',
        'purple' => 'bg-purple-500',
        'teal' => 'bg-teal-500',
    ];

    $sizes = [
        'sm' => 'px-1.5 py-0.5 text-[11px] gap-1',
        'md' => 'px-2 py-0.5 text-xs gap-1.5',
        'lg' => 'px-2.5 py-1 text-sm gap-1.5',
    ];
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex items-center rounded-full font-medium ring-1 ring-inset whitespace-nowrap '
        . $colour . ' ' . ($sizes[$size] ?? $sizes['md']),
]) }}>
    @if ($dot)
        <span class="h-1.5 w-1.5 rounded-full {{ $dots[$key] ?? $dots['gray'] }}" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
