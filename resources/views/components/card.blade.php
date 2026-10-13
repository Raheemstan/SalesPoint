@props([
    'title' => null,
    'subtitle' => null,
    'padding' => true,
])

{{--
|--------------------------------------------------------------------------
| x-card  -  the standard panel
|--------------------------------------------------------------------------
|
|   <x-card title="Today" subtitle="So far">
|       <x-slot:actions><a href="...">Export</a></x-slot:actions>
|       ...
|       <x-slot:footer>12 of 340</x-slot:footer>
|   </x-card>
|
| Pass `:padding="false"` when the body is a table that should run edge to edge.
--}}

<div {{ $attributes->merge([
    'class' => 'bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-200 dark:ring-gray-700',
]) }}>
    @if ($title || isset($actions))
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 dark:border-gray-700 px-4 py-3 sm:px-5">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="truncate text-base font-semibold text-gray-900 dark:text-gray-100">{{ $title }}</h2>
                @endif
                @if ($subtitle)
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div class="{{ $padding ? 'p-4 sm:p-5' : '' }}">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-gray-200 dark:border-gray-700 px-4 py-3 text-sm text-gray-600 dark:text-gray-400 sm:px-5">
            {{ $footer }}
        </div>
    @endisset
</div>
