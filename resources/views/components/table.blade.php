@props([
    'sticky' => true,
    'zebra' => true,
    'dense' => false,
])

{{--
|--------------------------------------------------------------------------
| x-table  -  responsive table wrapper
|--------------------------------------------------------------------------
|
|   <x-table>
|       <x-slot:head>
|           <tr><th>Invoice</th><th class="text-right">Total</th></tr>
|       </x-slot:head>
|
|       @foreach (...)
|           <tr><td>...</td><td class="text-right">...</td></tr>
|       @endforeach
|
|       <x-slot:foot>...</x-slot:foot>
|   </x-table>
|
| The rows belong to the page, so zebra striping and the sticky header come
| from classes on the wrapper (defined in resources/css/app.css) rather than
| from utilities the page would have to repeat on every <tr>.
|
| On a narrow screen the table scrolls sideways inside its own box instead of
| stretching the page, and the header stays put while the body scrolls - which
| is what makes a 300-row stock list usable on a till screen.
--}}

<div {{ $attributes->merge(['class' => 'relative overflow-x-auto overscroll-x-contain rounded-lg']) }}>
    <table class="w-full min-w-full border-collapse text-left text-sm
                  {{ $zebra ? 'table-zebra' : '' }} {{ $sticky ? 'table-sticky' : '' }}">
        @isset($head)
            <thead class="bg-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-600
                          dark:bg-gray-700 dark:text-gray-300
                          [&_th]:whitespace-nowrap [&_th]:bg-gray-100 [&_th]:px-3 [&_th]:py-2.5 dark:[&_th]:bg-gray-700">
                {{ $head }}
            </thead>
        @endisset

        <tbody class="divide-y divide-gray-200 text-gray-800 dark:divide-gray-700 dark:text-gray-100
                      {{ $dense ? '[&_td]:px-3 [&_td]:py-1.5' : '[&_td]:px-3 [&_td]:py-2.5' }}">
            {{ $slot }}
        </tbody>

        @isset($foot)
            <tfoot class="border-t-2 border-gray-300 font-semibold dark:border-gray-600
                          [&_td]:px-3 [&_td]:py-2.5 [&_th]:px-3 [&_th]:py-2.5">
                {{ $foot }}
            </tfoot>
        @endisset
    </table>
</div>
