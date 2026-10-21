@php
    $currentTheme = setting('theme') ?? 'system';

    /*
    | A token that changes whenever the assets are rebuilt.
    |
    | The service worker is registered as /sw.js?v=<token>: a changed URL means
    | a new worker, which names its caches after the new token and deletes the
    | old ones. That is what stops a deploy serving yesterday's CSS, and it
    | means public/sw.js never has to be edited by hand.
    |
    | Read from the file rather than through the Vite facade so it also works
    | under withoutVite() in the test suite, and falls back to "dev" before the
    | first `npm run build`.
    */
    $manifest = collect([
        public_path('build/manifest.json'),
        public_path('build/.vite/manifest.json'),
    ])->first(fn ($path) => is_file($path));

    $shellVersion = $manifest ? substr(md5_file($manifest), 0, 12) : 'dev';
@endphp

<!DOCTYPE html>
{{--
    themeSwitcher stays on <html> so it can add and remove the `dark` class on
    the element Tailwind's darkMode: 'class' looks at. The class is also printed
    server-side, so a dark till does not flash white before Alpine boots.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="h-full {{ $currentTheme === 'dark' ? 'dark' : '' }}"
      x-data="themeSwitcher"
      x-init="init()"
      data-theme="{{ $currentTheme }}"
      data-theme-url="{{ route('settings.theme') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@hasSection('title')@yield('title') - @endif{{ setting('store_name') ?: 'SalesPoint' }}</title>
    <meta name="description" content="SalesPoint - Simple POS. Smart Business.">

    {{-- Progressive web app --}}
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="sw-version" content="{{ $shellVersion }}">
    <meta name="theme-color" content="{{ $currentTheme === 'dark' ? '#111827' : '#ffffff' }}">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#111827" media="(prefers-color-scheme: dark)">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="SalesPoint">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" href="{{ asset('icons/icon-192.png') }}" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">

    {{--
        Everything is bundled and served from this install. Nothing is fetched
        from a CDN: Tailwind, Alpine, Chart.js and axios all come out of
        resources/ through Vite, because this till has to work with the internet
        unplugged. jQuery and select2 are gone - the one thing select2 was
        loaded for is now x-searchable-select.
    --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{--
        The only script that has to run before first paint: it restores the
        folded/unfolded sidebar so the layout does not jump. CSS reads it
        (html[data-sidebar='rail'] in resources/css/app.css) and appShell writes
        it back when the user toggles.
    --}}
    <script>
        (function () {
            var html = document.documentElement;
            try {
                var mode = localStorage.getItem('salespoint.sidebar');
                // Nothing remembered yet: fold it on a smaller screen, where
                // the labels cost more width than they are worth.
                if (mode !== 'rail' && mode !== 'full') {
                    mode = window.matchMedia('(min-width: 1280px)').matches ? 'full' : 'rail';
                }
                html.dataset.sidebar = mode;
            } catch (e) {
                html.dataset.sidebar = 'full';
            }
        })();
    </script>
</head>

<body class="h-full bg-gray-100 text-gray-800 antialiased dark:bg-gray-900 dark:text-gray-100">
    @include('pilot._warning_banner')

{{-- First stop for a keyboard or screen-reader user: skip the nav. --}}
<a href="#main-content"
   class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-toast focus:rounded-lg
          focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:shadow-lg
          dark:focus:bg-gray-800">
    Skip to main content
</a>

<div x-data="appShell" class="flex h-full overflow-hidden" @keydown.escape.window="closeDrawer()">

    @include('layouts.partials.sidebar')

    {{-- Drawer backdrop. Only exists below lg, where the sidebar slides over the page. --}}
    <div x-show="drawer" x-cloak x-transition.opacity.duration.200ms @click="closeDrawer()"
         class="fixed inset-0 z-30 bg-gray-900/50 lg:hidden" aria-hidden="true"></div>

    <div class="flex min-w-0 flex-1 flex-col">
        @include('layouts.partials.header')

        <main id="main-content" class="app-main flex-1 overflow-y-auto p-3 sm:p-5" tabindex="-1">
            @include('layouts.partials.toasts')

            {{--
                @yield for the pages that @extends this layout, and $slot for
                the handful that use <x-app-layout> (the profile screens).
                Before this, those pages rendered the whole shell with an empty
                body, because the layout only ever yielded a section.
            --}}
            @yield('content')
            {{ $slot ?? '' }}
        </main>
    </div>

    @include('layouts.partials.search-palette')
</div>

@if ((int) setting('auto_logout_time', 0) > 0)
    {{-- Idle logout, unchanged: after N minutes with no input the form submits. --}}
    <form id="idle-logout-form" method="POST" action="{{ route('logout') }}" class="hidden">@csrf</form>
    <script>
        (function () {
            const limitMs = {{ (int) setting('auto_logout_time') }} * 60 * 1000;
            let timer;
            const reset = () => {
                clearTimeout(timer);
                timer = setTimeout(() => document.getElementById('idle-logout-form').submit(), limitMs);
            };
            ['click', 'keydown', 'mousemove', 'touchstart'].forEach(e => document.addEventListener(e, reset, { passive: true }));
            reset();
        })();
    </script>
@endif

{{-- Page scripts: @section('scripts') as before, or @push('scripts') for new pages. --}}
@yield('scripts')
@stack('scripts')
</body>
</html>
