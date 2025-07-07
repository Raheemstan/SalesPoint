@php
    $currentTheme = setting('theme') ?? 'system';
@endphp

<!DOCTYPE html>
<html lang="en" class="{{ $currentTheme === 'dark' || ($currentTheme === 'system' && request()->header('User-Agent') && str_contains(strtolower(request()->header('User-Agent')), 'dark')) ? 'dark' : '' }}"
      x-data="themeSwitcher" x-init="init()" >
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SalesPoint - @yield('title', 'Dashboard')</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#ffffff">
    <meta name="description" content="SalesPoint - Simple POS. Smart Business.">

    {{-- Tailwind CSS & Plugins --}}
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="h-full bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-100">

<div class="flex h-screen overflow-hidden">
    {{-- Sidebar --}}
    <aside class="w-64 bg-white dark:bg-gray-800 flex flex-col shadow-md overflow-y-auto">
        <div class="p-4 border-b dark:border-gray-700 text-center">
            <a href="{{ route('dashboard') }}">
                <img src="{{ asset('logo.png') }}" alt="Logo" class="mx-auto h-32">
            </a>
        </div>

        <nav class="flex-1 p-4 space-y-2">
            @foreach([
                ['label' => '🛒 POS', 'route' => 'pos.index'],
                ['label' => '📦 Products', 'route' => 'products.index'],
                ['label' => '📂 Categories', 'route' => 'categories.index'],
                ['label' => '💰 Expenses', 'route' => 'expenses.index'],
                ['label' => '🛍️ Purchases', 'route' => 'purchases.index'],
            ] as $item)
                <a href="{{ route($item['route']) }}"
                   class="block px-4 py-2 rounded hover:bg-blue-100 dark:hover:bg-blue-900 {{ request()->routeIs(Str::before($item['route'], '.') . '.*') ? 'bg-blue-50 dark:bg-blue-900 font-semibold' : '' }}">
                    {{ $item['label'] }}
                </a>
            @endforeach

            {{-- Reports Dropdown --}}
            <div x-data="{ open: false }" class="relative">
                <button @click="open = !open"
                        class="w-full px-4 py-2 rounded flex justify-between items-center hover:bg-blue-100 dark:hover:bg-blue-900 {{ request()->routeIs('reports.*') ? 'bg-blue-50 dark:bg-blue-900 font-semibold' : '' }}">
                    📊 Reports
                    <svg :class="{ 'rotate-180': open }" class="w-4 h-4 ml-2 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="open" @click.away="open = false" x-transition
                     class="absolute left-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded shadow z-10 border dark:border-gray-700">
                    <a href="{{ route('reports.sales') }}" class="block px-4 py-2 hover:bg-blue-100 dark:hover:bg-blue-900">Sales Report</a>
                    <a href="{{ route('reports.daily') }}" class="block px-4 py-2 hover:bg-blue-100 dark:hover:bg-blue-900">Daily Summary</a>
                    <a href="{{ route('reports.profit_loss') }}" class="block px-4 py-2 hover:bg-blue-100 dark:hover:bg-blue-900">Profit & Loss</a>
                </div>
            </div>

            <a href="{{ route('settings.index') }}"
               class="block px-4 py-2 rounded hover:bg-blue-100 dark:hover:bg-blue-900 {{ request()->routeIs('settings.*') ? 'bg-blue-50 dark:bg-blue-900 font-semibold' : '' }}">
                ⚙️ Settings
            </a>
        </nav>

        <form method="POST" action="{{ route('logout') }}" class="p-4 border-t dark:border-gray-700">
            @csrf
            <button class="w-full text-left px-4 py-2 rounded hover:bg-red-100 dark:hover:bg-red-900 text-red-600">
                🔒 Logout
            </button>
        </form>
    </aside>

    {{-- Main --}}
    <div class="flex-1 flex flex-col">
        <header class="bg-white dark:bg-gray-800 shadow px-6 py-4 flex justify-between items-center">
            <h1 class="text-xl font-bold">@yield('title', 'Dashboard')</h1>
            <div class="flex items-center gap-4">
                <span class="hidden md:inline text-gray-600 dark:text-gray-300">Hi, {{ Auth::user()->name }}</span>
                <select x-model="theme" @change="updateTheme" class="border px-2 py-1 rounded text-sm bg-white dark:bg-gray-700">
                    <option value="system">🌓 System</option>
                    <option value="light">☀️ Light</option>
                    <option value="dark">🌙 Dark</option>
                </select>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-6">
            {{-- Flash Messages --}}
            @foreach (['success' => 'green', 'error' => 'red'] as $type => $color)
                @if (session($type))
                    <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
                         class="p-3 mb-4 rounded border bg-{{ $color }}-100 text-{{ $color }}-800 border-{{ $color }}-300">
                        {{ session($type) }}
                    </div>
                @endif
            @endforeach

            {{-- Validation Errors --}}
            @if ($errors->any())
                <div class="p-3 mb-4 rounded border bg-red-100 text-red-800 border-red-300">
                    <ul class="list-disc pl-5 text-sm">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('themeSwitcher', () => ({
            theme: '{{ $currentTheme }}',

            init() {
                this.applyTheme(this.theme);
            },

            applyTheme(theme) {
                const html = document.documentElement;
                if (theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    html.classList.add('dark');
                } else {
                    html.classList.remove('dark');
                }
            },

            async updateTheme() {
                this.applyTheme(this.theme);
                try {
                    await fetch("{{ route('settings.update') }}", {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ theme: this.theme })
                    });
                } catch (err) {
                    console.error('Theme save failed:', err);
                }
            }
        }));
    });
</script>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
@yield('scripts')
</body>
</html>
