@php
    $user = auth()->user();
    $pageTitle = trim($__env->yieldContent('title', ''));
@endphp

{{--
    The shell's top bar. Everything in it is reachable with the keyboard, and
    nothing in it is smaller than a fingertip: this runs on a touchscreen till.
--}}
<header class="app-header sticky top-0 z-30 flex h-16 shrink-0 items-center gap-2 border-b border-gray-200
               bg-white/95 px-2 backdrop-blur sm:px-4 dark:border-gray-700 dark:bg-gray-800/95">

    {{-- Drawer toggle: only below lg, where the sidebar is off-canvas. --}}
    <button type="button"
            x-ref="drawerToggle"
            @click="toggleDrawer()"
            class="flex h-touch w-touch items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 lg:hidden"
            :aria-expanded="drawer ? 'true' : 'false'"
            aria-controls="app-sidebar"
            aria-label="Toggle navigation">
        <x-icon name="menu" size="6" />
    </button>

    {{-- Page title. @extends pages set @section('title'); the x-app-layout
         pages (profile) pass a header slot instead. --}}
    <div class="min-w-0 flex-1">
        @if ($pageTitle !== '')
            <h1 class="truncate text-base font-semibold text-gray-900 dark:text-gray-100 sm:text-lg">
                {{ $pageTitle }}
            </h1>
        @elseif (isset($header))
            <div class="min-w-0 truncate">{{ $header }}</div>
        @endif
    </div>

    {{--
        Open-shift indicator.

        Shifts are another agent's job, so this is left as a named hole rather
        than guessed at. Two ways to fill it, in this order:

          1. a page can set @section('shift-indicator') for its own screen;
          2. otherwise, if the view shifts._indicator exists it is rendered on
             every page - that is the hook for the global "till open since
             08:12, N4,300 counted in" pill.

        Neither needs this file to change.
    --}}
    <div id="shift-indicator" class="flex items-center">
        @hasSection('shift-indicator')
            @yield('shift-indicator')
        @else
            @includeIf('shifts._indicator')
        @endif
    </div>

    {{-- Global search. Ctrl/Cmd+K or "/" from anywhere. --}}
    <button type="button"
            @click="$dispatch('open-palette')"
            class="flex min-h-touch items-center gap-2 rounded-lg border border-gray-300 px-2.5 text-sm text-gray-500
                   hover:bg-gray-50 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700 sm:px-3"
            aria-label="Search">
        <x-icon name="search" size="5" />
        <span class="hidden lg:inline">Search</span>
        <kbd class="hidden rounded border border-gray-300 px-1 text-[11px] dark:border-gray-600 lg:inline">Ctrl K</kbd>
    </button>

    {{-- Theme. Keeps the original themeSwitcher behaviour, including saving the choice. --}}
    <div class="flex items-center">
        <label for="theme-select" class="sr-only">Theme</label>
        <select id="theme-select" x-model="theme" @change="updateTheme"
                class="min-h-touch rounded-lg border-gray-300 bg-white py-0 pl-2 pr-7 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
            <option value="system">System</option>
            <option value="light">Light</option>
            <option value="dark">Dark</option>
        </select>
    </div>

    {{-- Who is signed in, and their own pages. --}}
    <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false" class="relative">
        <button type="button" @click="open = ! open"
                class="flex min-h-touch items-center gap-2 rounded-lg px-2 hover:bg-gray-100 dark:hover:bg-gray-700"
                :aria-expanded="open ? 'true' : 'false'"
                aria-haspopup="menu">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-600 text-sm font-semibold text-white">
                {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($user->name, 0, 1)) }}
            </span>
            <span class="hidden min-w-0 text-left sm:block">
                <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</span>
                <span class="block truncate text-xs capitalize text-gray-500 dark:text-gray-400">{{ $user->role }}</span>
            </span>
            <x-icon name="chevron-down" size="4" class="hidden text-gray-400 sm:block" />
        </button>

        <div x-show="open" x-cloak x-transition.opacity.duration.100ms role="menu"
             class="absolute right-0 mt-1 w-56 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
            <div class="border-b border-gray-200 px-3 py-2 dark:border-gray-700 sm:hidden">
                <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</p>
                <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $user->email }}</p>
            </div>

            <a href="{{ route('profile.edit') }}" role="menuitem"
               class="flex min-h-touch items-center gap-2.5 px-3 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                <x-icon name="user" size="5" /> My profile
            </a>

            <a href="{{ route('two-factor.show') }}" role="menuitem"
               class="flex min-h-touch items-center gap-2.5 px-3 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                <x-icon name="key" size="5" /> Two-factor login
            </a>

            <button type="button" x-show="installable" x-cloak @click="install()" role="menuitem"
                    class="flex min-h-touch w-full items-center gap-2.5 px-3 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700">
                <x-icon name="download" size="5" /> Install app
            </button>

            <form method="POST" action="{{ route('logout') }}" class="border-t border-gray-200 dark:border-gray-700">
                @csrf
                <button type="submit" role="menuitem"
                        class="flex min-h-touch w-full items-center gap-2.5 px-3 text-left text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">
                    <x-icon name="logout" size="5" /> Log out
                </button>
            </form>
        </div>
    </div>
</header>
