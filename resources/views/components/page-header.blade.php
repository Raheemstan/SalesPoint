@props([
    'title',
    'subtitle' => null,
    'back' => null,
    'backLabel' => 'Back',
])

{{--
|--------------------------------------------------------------------------
| x-page-header  -  the title row at the top of a page body
|--------------------------------------------------------------------------
|
|   <x-page-header title="Products" subtitle="238 in stock">
|       <x-slot:actions>
|           <button ...>Add product</button>
|       </x-slot:actions>
|   </x-page-header>
|
| The shell already prints the page title in the header bar; this is for the
| in-page heading, which is where the actions for the page belong. Actions wrap
| onto their own line on a narrow screen rather than squeezing the title.
--}}

<div {{ $attributes->merge(['class' => 'mb-5 flex flex-wrap items-end justify-between gap-3']) }}>
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back }}"
               class="mb-1 inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                <x-icon name="chevron-left" size="4" />
                {{ $backLabel }}
            </a>
        @endif

        <h1 class="truncate text-xl font-semibold text-gray-900 dark:text-gray-100 sm:text-2xl">
            {{ $title }}
        </h1>

        @if ($subtitle)
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        {{-- min-h-touch: these are the page's main buttons and get tapped with a finger. --}}
        <div class="flex min-h-touch flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
