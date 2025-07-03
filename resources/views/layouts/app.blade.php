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


    {{-- Tailwind CSS CDN (Replace with Laravel Mix if preferred) --}}
    <script src="https://cdn.tailwindcss.com"></script>

    {{-- Optional: AlpineJS for interactivity --}}
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script defer src="{{ asset('alpine.js') }}"></script>
    <script defer src="{{ asset('tailwindcdn.js') }}"></script>
    
</head>

<body class="bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100">
    <div class="flex h-screen">
        {{-- Sidebar --}}
        <aside class="bg-white dark:bg-gray-800 w-64 shadow-md flex flex-col">
            <div class="p-4 text-center border-b dark:border-gray-700">
                <a href="{{ route('dashboard') }}">
                    <img src="{{ asset('logo.png') }}" alt="Company Logo" class="mx-auto mb-2">
                </a>
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
                    <li x-data="{ open: false }" class="relative">
                        <button @click="open = !open"
                            class="flex items-center justify-between w-full px-4 py-2 rounded hover:bg-blue-100 dark:hover:bg-blue-900 {{ request()->routeIs('reports.*') ? 'bg-blue-50 dark:bg-blue-900 font-semibold' : '' }}">
                            📊 Reports
                            <svg class="w-4 h-4 ml-2 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <ul x-show="open" @click.away="open = false" x-transition
                            class="absolute left-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded shadow-lg z-10 border dark:border-gray-700">
                            <li>
                                <a href="{{ route('reports.sales') }}"
                                    class="block px-4 py-2 hover:bg-blue-100 dark:hover:bg-blue-900 {{ request()->routeIs('reports.sales') ? 'bg-blue-50 dark:bg-blue-900 font-semibold' : '' }}">
                                    Sales Report
                                </a>
                            </li>
                            <li>
                                <a href="{{ route('reports.daily') }}"
                                    class="block px-4 py-2 hover:bg-blue-100 dark:hover:bg-blue-900 {{ request()->routeIs('reports.daily') ? 'bg-blue-50 dark:bg-blue-900 font-semibold' : '' }}">
                                    Daily Summary
                                </a>
                            </li>
                            {{-- <li>
                                <a href="{{ route('reports.expenses') }}"
                                    class="block px-4 py-2 hover:bg-blue-100 dark:hover:bg-blue-900 {{ request()->routeIs('reports.expenses') ? 'bg-blue-50 dark:bg-blue-900 font-semibold' : '' }}">
                                    Expense Report
                                </a>
                            </li> --}}
                        </ul>
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