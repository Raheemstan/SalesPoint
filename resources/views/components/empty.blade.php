@props([
    'icon' => 'inbox',
    'title' => 'Nothing here yet',
    'message' => null,
])

{{--
|--------------------------------------------------------------------------
| x-empty  -  empty state
|--------------------------------------------------------------------------
|
|   <x-empty title="No sales today" message="Sales will appear here as they are rung up.">
|       <x-slot:action>
|           <a href="{{ route('pos.index') }}" class="...">Open the till</a>
|       </x-slot:action>
|   </x-empty>
|
| Use it in place of a bare "No records found." row: an empty screen should say
| what would be here and offer the one thing the user most likely wants next.
| Inside a table, put it in a full-width cell:
|
|   <tr><td colspan="7"><x-empty ... /></td></tr>
--}}

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-12 text-center']) }}>
    <span class="mb-3 rounded-full bg-gray-100 p-3 text-gray-400 dark:bg-gray-700 dark:text-gray-500">
        <x-icon :name="$icon" size="8" />
    </span>

    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $title }}</h3>

    @if ($message)
        <p class="mt-1 max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ $message }}</p>
    @endif

    @isset($action)
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">{{ $action }}</div>
    @endisset

    {{ $slot }}
</div>
