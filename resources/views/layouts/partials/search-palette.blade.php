@php
    /*
    | The palette is a navigation finder, not a server search: it filters the
    | links the sidebar rendered, so it needs no route of its own and can never
    | offer a page the user is not allowed to open.
    |
    | Managers and admins also get a last row that searches the catalogue for
    | whatever was typed, which is the one text search the app already has.
    | __QUERY__ is substituted in the browser.
    */
    $catalogueSearch = auth()->user()?->hasAnyRole(['admin', 'manager'])
        ? route('products.index', ['search' => '__QUERY__'])
        : null;
@endphp

<div x-data="commandPalette"
     data-search-url="{{ $catalogueSearch }}"
     @open-palette.window="show()"
     class="relative">

    <div x-show="open" x-cloak class="fixed inset-0 z-modal" role="dialog" aria-modal="true"
         aria-label="Search">
        {{-- Backdrop --}}
        <div x-show="open" x-transition.opacity.duration.150ms
             class="fixed inset-0 bg-gray-900/50 backdrop-blur-sm" @click="hide()"></div>

        <div class="fixed inset-x-3 top-16 mx-auto max-w-xl sm:inset-x-0">
            <div x-show="open"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 -translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 @keydown.escape="hide()"
                 class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-800">

                <div class="flex items-center gap-2 border-b border-gray-200 px-3 dark:border-gray-700">
                    <x-icon name="search" size="5" class="text-gray-400" />
                    <input
                        type="text"
                        x-ref="input"
                        x-model="query"
                        @keydown.down.prevent="move(1)"
                        @keydown.up.prevent="move(-1)"
                        @keydown.enter.prevent="choose()"
                        @input="index = 0"
                        placeholder="Jump to a page..."
                        aria-label="Search pages"
                        autocomplete="off"
                        class="min-h-touch w-full border-0 bg-transparent px-0 text-sm focus:ring-0 dark:text-gray-100"
                    >
                    <kbd class="hidden rounded border border-gray-300 px-1 text-[11px] text-gray-500 dark:border-gray-600 sm:inline">Esc</kbd>
                </div>

                <ul class="scroll-thin max-h-80 overflow-y-auto py-1 text-sm" role="listbox">
                    <template x-for="(item, i) in results" :key="item.href + i">
                        <li role="option" :aria-selected="index === i ? 'true' : 'false'">
                            <a :href="item.href"
                               @mouseenter="index = i"
                               class="flex min-h-touch items-center justify-between gap-3 px-3"
                               :class="index === i
                                   ? 'bg-primary-50 text-primary-800 dark:bg-primary-900/40 dark:text-primary-100'
                                   : 'text-gray-700 dark:text-gray-200'">
                                <span class="truncate" x-text="item.label"></span>
                                <span class="shrink-0 text-xs text-gray-400" x-text="item.group"></span>
                            </a>
                        </li>
                    </template>

                    @if ($catalogueSearch)
                        <li x-show="query.trim()" role="option" aria-selected="false"
                            class="border-t border-gray-200 dark:border-gray-700">
                            <a :href="catalogueUrl"
                               class="flex min-h-touch items-center gap-2 px-3 text-gray-600 dark:text-gray-300">
                                <x-icon name="box" size="4" class="text-gray-400" />
                                <span class="truncate">
                                    Search {{ \Illuminate\Support\Str::lower(term('product', true)) }} for
                                    "<span x-text="query.trim()"></span>"
                                </span>
                            </a>
                        </li>
                    @endif

                    <li x-show="! results.length && ! query.trim()"
                        class="px-3 py-3 text-center text-gray-500 dark:text-gray-400">
                        Nothing to jump to.
                    </li>
                </ul>

                <p class="border-t border-gray-200 px-3 py-2 text-[11px] text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    Up/Down to move, Enter to open. Ctrl+K or / opens this from anywhere.
                </p>
            </div>
        </div>
    </div>
</div>
