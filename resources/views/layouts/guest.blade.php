<!DOCTYPE html>
@php
    $currentTheme = setting('theme') ?? 'system';
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="h-full {{ $currentTheme === 'dark' ? 'dark' : '' }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ setting('store_name') ?: config('app.name', 'SalesPoint') }}</title>

    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#111827" media="(prefers-color-scheme: dark)">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">

    {{--
        No webfont is fetched here. This used to pull Figtree from
        fonts.bunny.net, which meant the login screen - the first thing a till
        shows - waited on the internet to render its text. tailwind.config.js
        still asks for Figtree first and falls back to the system stack, so an
        install that has the font gets it and one that does not loses nothing.
    --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- "system" has to be resolved before paint, or a dark till flashes white. --}}
    <script>
        (function () {
            var saved = @json($currentTheme);
            if (saved === 'dark' || (saved !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
</head>

<body class="h-full bg-gray-100 font-sans text-gray-900 antialiased dark:bg-gray-900 dark:text-gray-100">
    <div class="flex min-h-full flex-col items-center justify-center px-4 py-8">
        <a href="/" class="mb-6">
            {{-- The 192px icon, not the 1.2MB logo.png. --}}
            <img src="{{ asset('icons/icon-192.png') }}" alt="{{ setting('store_name') ?: 'SalesPoint' }}"
                 width="80" height="80" class="h-20 w-20 rounded-2xl shadow-sm">
        </a>

        <div class="w-full overflow-hidden rounded-xl bg-white px-6 py-5 shadow-md ring-1 ring-gray-200 sm:max-w-md dark:bg-gray-800 dark:ring-gray-700">
            {{ $slot }}
        </div>

        <p class="mt-6 text-xs text-gray-500 dark:text-gray-400">
            {{ setting('store_motto') ?: 'Simple POS. Smart Business.' }}
        </p>
    </div>
</body>

</html>
