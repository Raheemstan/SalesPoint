@php
    $userTheme = auth()->user()->theme ?? 'system'; // Fetch from user or default
    $systemPref = "(window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')";

    // Determine class
    $themeClass = match ($userTheme) {
        'dark' => 'dark',
        'light' => '',
        'system' => '',
        default => '',
    };
@endphp

<!DOCTYPE html>
<html lang="en" x-data="{ theme: '{{ $userTheme }}' }"
    :class="{ 'dark': theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches) }">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#ffffff">
    <meta name="description" content="SalesPoint - Simple POS. Smart Business.">
    <meta name="author" content="Raheemstan Industries">
    <meta name="keywords" content="sales, management, POS, products, categories, reports, settings">
    <title>SalesPoint - @yield('title', 'Dashboard')</title>


    <script defer src="{{ asset('alpine.js') }}"></script>
    <script defer src="{{ asset('tailwindcdn.js') }}"></script>
    
</head>

<body class="bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100">
    <div class="flex h-screen">
        {{-- Sidebar --}}
        <aside class="bg-white dark:bg-gray-800 w-64 shadow-md flex flex-col">
            <div class="p-4 text-center border-b dark:border-gray-700">
                <img src="{{ asset('logo.png') }}" alt="Company Logo" class="mx-auto mb-2">
            </div>

            <nav class="flex-1 p-4">
                <ul class="space-y-2">
                    <li>
                        <a href="{{ route('pos.index') }}"
                            class="block px-4 py-2 rounded hover:bg-blue-100 dark:hover:bg-blue-900 {{ request()->routeIs('pos.*') ? 'bg-blue-50 dark:bg-blue-900 font-semibold' : '' }}">
                            🛒 POS
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('products.index') }}"
                            class="block px-4 py-2 rounded hover:bg-blue-100 dark:hover:bg-blue-900 {{ request()->routeIs('products.*') ? 'bg-blue-50 dark:bg-blue-900 font-semibold' : '' }}">
                            📦 Products
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('categories.index') }}"
                            class="block px-4 py-2 rounded hover:bg-blue-100 dark:hover:bg-blue-900 {{ request()->routeIs('categories.*') ? 'bg-blue-50 dark:bg-blue-900 font-semibold' : '' }}">
                            📂 Categories
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('reports.sales') }}"
                            class="block px-4 py-2 rounded hover:bg-blue-100 dark:hover:bg-blue-900 {{ request()->routeIs('reports.*') ? 'bg-blue-50 dark:bg-blue-900 font-semibold' : '' }}">
                            📊 Reports
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('settings.index') }}"
                            class="block px-4 py-2 rounded hover:bg-blue-100 dark:hover:bg-blue-900 {{ request()->routeIs('settings.*') ? 'bg-blue-50 dark:bg-blue-900 font-semibold' : '' }}">
                            ⚙️ Settings
                        </a>
                    </li>
                </ul>
            </nav>

            <div class="p-4 border-t dark:border-gray-700">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button
                        class="w-full text-left px-4 py-2 rounded hover:bg-red-100 dark:hover:bg-red-900 text-red-600">
                        🔒 Logout
                    </button>
                </form>
            </div>
        </aside>

        {{-- Main --}}
        <div class="flex-1 flex flex-col">
            <header class="bg-white dark:bg-gray-800 shadow p-4 flex justify-between items-center">
                <h2 class="text-xl font-semibold">@yield('title', 'Dashboard')</h2>

                {{-- Theme Switcher (Optional Quick Toggle) --}}
                <div class="flex items-center gap-4">
                    <span class="hidden md:inline text-gray-600 dark:text-gray-300">
                        Hello, {{ Auth::user()->name }}
                    </span>

                    {{-- Quick Theme Toggle --}}
                    <select x-model="theme" class="border rounded px-2 py-1 bg-white dark:bg-gray-700">
                        <option value="system">System</option>
                        <option value="light">Light</option>
                        <option value="dark">Dark</option>
                    </select>
                </div>
            </header>

            <main class="flex-1 p-6 overflow-y-auto">
                @yield('content')
            </main>
        </div>
    </div>

</body>

<footer>
    @yield('scripts')
</footer>

</html>