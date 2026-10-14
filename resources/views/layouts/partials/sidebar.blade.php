@php
    /*
    |--------------------------------------------------------------------------
    | The navigation
    |--------------------------------------------------------------------------
    |
    | The one place the nav is defined. The header's search palette builds its
    | list by reading the rendered links (data-nav-label / data-nav-group), so
    | it can never drift from this.
    |
    | Each item is:
    |   label   what the user sees - run trade-specific nouns through term()
    |   icon    a name from resources/views/components/icon.blade.php
    |   route   a route name; every one below is registered in routes/web.php
    |   active  route patterns that mean "you are on this page"
    |   roles   who may see it (the same test @role('admin|manager') applies);
    |           filtering here rather than with the directive is what lets a
    |           group that a user can reach nothing in disappear entirely
    |   when    an extra condition, used for sector features: a salon has no
    |           stock, so a stock-take link would only lead somewhere useless
    |
    | Some of these screens are still being built. A link to a page that is not
    | finished is expected.
    |
    */
    $user = auth()->user();

    $manager = ['admin', 'manager'];
    $adminOnly = ['admin'];

    $allowed = fn (array $roles) => $roles === [] || ($user && $user->hasAnyRole($roles));

    // Products, drugs, menu items, services - whatever this trade calls them.
    $products = term('product', true);
    $sales = term('sale', true);

    $pinned = [
        'label' => 'Dashboard',
        'icon' => 'chart-bar',
        'route' => 'dashboard',
        'active' => ['dashboard'],
    ];

    $groups = [
        [
            'label' => 'Till',
            'icon' => 'cart',
            'items' => [
                [
                    // The floor view is the home screen of a restaurant install, so
                    // it sits above the till rather than beside it. Hidden entirely
                    // for a trade with no tables, and open to all staff: runners and
                    // bar staff add to tables they did not open.
                    'label' => term('table', true, 'Tables'),
                    'icon' => 'building',
                    'route' => 'tables.index',
                    'active' => ['tables.*', 'table-orders.*'],
                    'when' => feature('tables'),
                ],
                [
                    'label' => 'New ' . \Illuminate\Support\Str::lower(term('sale')),
                    'icon' => 'cart',
                    'route' => 'pos.index',
                    'active' => ['pos.index'],
                ],
                [
                    'label' => 'Held ' . \Illuminate\Support\Str::lower($sales),
                    'icon' => 'pause',
                    'route' => 'pos.parked',
                    'active' => ['pos.parked', 'pos.parked.*'],
                ],
                [
                    'label' => 'My shift',
                    'icon' => 'clock',
                    'route' => 'shifts.index',
                    'active' => ['shifts.*'],
                ],
            ],
        ],

        [
            'label' => 'Sales',
            'icon' => 'receipt',
            'items' => [
                [
                    'label' => $sales,
                    'icon' => 'receipt',
                    'route' => 'sales.index',
                    'active' => ['sales.index', 'sales.show'],
                ],
                [
                    // Looking a serial / IMEI up to find which sale it went out on
                    // is what recording them is for: warranty claims.
                    'label' => 'Serial lookup',
                    'icon' => 'search',
                    'route' => 'serials.index',
                    'active' => ['serials.*'],
                    'roles' => $manager,
                    'when' => feature('serials'),
                ],
                [
                    'label' => 'Supplier returns',
                    'icon' => 'undo',
                    'route' => 'purchases.returns.index',
                    'active' => ['purchases.returns.*'],
                    'roles' => $manager,
                ],
                [
                    'label' => 'Expenses',
                    'icon' => 'coins',
                    'route' => 'expenses.index',
                    'active' => ['expenses.*'],
                    'roles' => $manager,
                ],
            ],
        ],

        [
            'label' => 'Catalogue',
            'icon' => 'box',
            'roles' => $manager,
            'items' => [
                [
                    'label' => $products,
                    'icon' => 'box',
                    'route' => 'products.index',
                    'active' => ['products.index'],
                ],
                [
                    'label' => term('category', true),
                    'icon' => 'folder',
                    'route' => 'categories.index',
                    'active' => ['categories.*'],
                ],
                [
                    'label' => 'Import ' . \Illuminate\Support\Str::lower($products),
                    'icon' => 'upload',
                    'route' => 'products.import.form',
                    'active' => ['products.import.*', 'products.export'],
                ],
            ],
        ],

        [
            'label' => 'Stock',
            'icon' => 'clipboard-check',
            'roles' => $manager,
            'items' => [
                [
                    'label' => 'Stock takes',
                    'icon' => 'clipboard-check',
                    'route' => 'stock-takes.index',
                    'active' => ['stock-takes.*'],
                    'when' => feature('stock'),
                ],
                [
                    'label' => 'Adjustments',
                    'icon' => 'sliders',
                    'route' => 'adjustments.index',
                    'active' => ['adjustments.*'],
                    'when' => feature('stock'),
                ],
                [
                    'label' => 'Reorder list',
                    'icon' => 'refresh',
                    'route' => 'reorder.index',
                    'active' => ['reorder.*'],
                    'when' => feature('stock'),
                ],
                [
                    'label' => 'Purchases',
                    'icon' => 'truck',
                    'route' => 'purchases.index',
                    // Not purchases.* - that would also match purchases.returns.*,
                    // which lives under Sales.
                    'active' => [
                        'purchases.index', 'purchases.create', 'purchases.show',
                        'purchases.edit', 'purchases.store', 'purchases.update',
                    ],
                ],
            ],
        ],

        [
            'label' => 'People',
            'icon' => 'users',
            'roles' => $manager,
            'items' => [
                [
                    'label' => term('customer', true),
                    'icon' => 'users',
                    'route' => 'customers.index',
                    'active' => ['customers.index', 'customers.show', 'customers.payments.*'],
                ],
                [
                    'label' => term('supplier', true),
                    'icon' => 'building',
                    'route' => 'suppliers.index',
                    'active' => ['suppliers.*'],
                ],
                [
                    // Only present for a group running more than one shop.
                    'label' => 'Branches',
                    'icon' => 'building',
                    'route' => 'branches.index',
                    'active' => ['branches.*'],
                    'when' => feature('branches'),
                ],
                [
                    'label' => 'Stock transfers',
                    'icon' => 'truck',
                    'route' => 'transfers.index',
                    'active' => ['transfers.*'],
                    'when' => feature('branches'),
                ],
            ],
        ],

        [
            'label' => 'Reports',
            'icon' => 'chart-bar',
            'roles' => $manager,
            'items' => [
                [
                    'label' => $sales,
                    'icon' => 'chart-line',
                    'route' => 'reports.sales',
                    'active' => ['reports.sales', 'reports.sales.*'],
                ],
                [
                    'label' => 'Daily summary',
                    'icon' => 'calendar',
                    'route' => 'reports.daily',
                    'active' => ['reports.daily'],
                ],
                [
                    'label' => 'Stock movement',
                    'icon' => 'refresh',
                    'route' => 'reports.movement',
                    'active' => ['reports.movement'],
                    'when' => feature('stock'),
                ],
                [
                    'label' => 'Profit & loss',
                    'icon' => 'coins',
                    'route' => 'reports.profit_loss',
                    'active' => ['reports.profit_loss'],
                ],
                [
                    'label' => 'By ' . \Illuminate\Support\Str::lower(term('cashier', true)),
                    'icon' => 'user',
                    'route' => 'reports.cashiers',
                    'active' => ['reports.cashiers'],
                ],
                [
                    'label' => 'By ' . \Illuminate\Support\Str::lower(term('category', true)),
                    'icon' => 'folder',
                    'route' => 'reports.categories',
                    'active' => ['reports.categories'],
                ],
                [
                    'label' => 'By ' . \Illuminate\Support\Str::lower($products),
                    'icon' => 'box',
                    'route' => 'reports.products',
                    'active' => ['reports.products'],
                ],
                [
                    'label' => 'Stock valuation',
                    'icon' => 'scale',
                    'route' => 'reports.valuation',
                    'active' => ['reports.valuation'],
                    'when' => feature('stock'),
                ],
                [
                    'label' => 'Receivables',
                    'icon' => 'trend-up',
                    'route' => 'reports.receivables',
                    'active' => ['reports.receivables'],
                ],
                [
                    'label' => 'Payables',
                    'icon' => 'trend-down',
                    'route' => 'reports.payables',
                    'active' => ['reports.payables'],
                ],
                [
                    'label' => 'Expiring stock',
                    'icon' => 'alert-triangle',
                    'route' => 'reports.expiry',
                    'active' => ['reports.expiry'],
                    // Only trades that track batches and expiry dates.
                    'when' => feature('expiry'),
                ],
            ],
        ],

        [
            'label' => 'Settings',
            'icon' => 'cog',
            'roles' => $manager,
            'items' => [
                [
                    'label' => 'Settings',
                    'icon' => 'cog',
                    'route' => 'settings.index',
                    'active' => ['settings.index', 'settings.update'],
                ],
                [
                    'label' => 'Audit log',
                    'icon' => 'shield',
                    'route' => 'settings.audit',
                    'active' => ['settings.audit', 'settings.audit.*'],
                    'roles' => $adminOnly,
                ],
                [
                    'label' => 'Backups',
                    'icon' => 'database',
                    'route' => 'settings.backups',
                    'active' => ['settings.backups', 'settings.backups.*', 'settings.backup.*'],
                    'roles' => $adminOnly,
                ],
            ],
        ],
    ];

    // Drop what this user cannot reach, then drop any group left with nothing.
    $groups = collect($groups)
        ->filter(fn ($group) => $allowed($group['roles'] ?? []))
        ->map(function ($group) use ($allowed) {
            $group['items'] = collect($group['items'])
                ->filter(fn ($item) => ($item['when'] ?? true) && $allowed($item['roles'] ?? $group['roles'] ?? []))
                ->values()
                ->all();

            return $group;
        })
        ->filter(fn ($group) => count($group['items']) > 0)
        ->values();

    $isActive = fn (array $patterns) => request()->routeIs(...$patterns);

    $rowBase = 'nav-row group flex min-h-touch items-center gap-3 rounded-lg px-3 py-2 text-sm transition';
    $rowIdle = 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700/60';
    $rowOn = 'bg-primary-50 font-semibold text-primary-700 dark:bg-primary-900/40 dark:text-primary-200';
@endphp

{{--
    One sidebar element for every screen size:
      - below lg it is an off-canvas drawer, slid in by appShell.drawer
      - at lg and above it is fixed in place, and can be folded down to an icon
        rail (see .app-sidebar in resources/css/app.css)
--}}
<aside
    id="app-sidebar"
    x-ref="sidebar"
    class="app-sidebar fixed inset-y-0 left-0 z-drawer flex flex-col border-r border-gray-200 bg-white
           transition-transform duration-200 dark:border-gray-700 dark:bg-gray-800
           lg:static lg:translate-x-0"
    :class="drawer ? 'translate-x-0 shadow-2xl' : '-translate-x-full lg:translate-x-0'"
    {{-- A closed drawer is only slid off-screen, so without this its links stay
         in the tab order and screen readers still read them out. --}}
    x-effect="$el.inert = ! drawer && ! isWide"
    aria-label="Main navigation"
>
    {{-- Brand --}}
    <div class="flex h-16 shrink-0 items-center gap-2 border-b border-gray-200 px-3 dark:border-gray-700">
        <a href="{{ route('dashboard') }}"
           class="flex min-h-touch flex-1 items-center gap-2.5 rounded-lg px-1 text-left">
            {{-- The 192px icon, not the 1.2MB logo.png: this is 40px on screen. --}}
            <img src="{{ asset('icons/icon-192.png') }}" alt="" width="40" height="40"
                 class="h-10 w-10 shrink-0 rounded-lg">
            <span data-rail-hide class="min-w-0">
                <span class="block truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ setting('store_name') ?: 'SalesPoint' }}
                </span>
                <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                    {{ setting('store_motto') ?: sector('label') }}
                </span>
            </span>
        </a>

        {{-- Closes the drawer; pointless once the sidebar is fixed in place. --}}
        <button type="button" @click="closeDrawer()"
                class="flex h-touch w-touch items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700 lg:hidden"
                aria-label="Close navigation">
            <x-icon name="x" size="5" />
        </button>
    </div>

    <nav class="scroll-thin flex-1 overflow-y-auto p-2" aria-label="Sections">
        @php $pinnedOn = $isActive($pinned['active']); @endphp
        <a href="{{ route($pinned['route']) }}"
           data-nav-link
           data-nav-label="{{ $pinned['label'] }}"
           data-nav-group=""
           title="{{ $pinned['label'] }}"
           @if ($pinnedOn) aria-current="page" @endif
           class="{{ $rowBase }} {{ $pinnedOn ? $rowOn : $rowIdle }}">
            <x-icon :name="$pinned['icon']" size="5" />
            <span data-rail-hide class="truncate">{{ $pinned['label'] }}</span>
        </a>

        @foreach ($groups as $i => $group)
            @php
                $groupOn = collect($group['items'])->contains(fn ($item) => $isActive($item['active']));
            @endphp

            {{--
                Open because the current page is inside it, decided on the
                server. Doing it in Alpine instead would mean every group
                flashing open on each page load.
            --}}
            <div x-data="{ open: @js($groupOn) }" class="mt-1">
                <h2 class="sr-only">{{ $group['label'] }}</h2>

                <button
                    type="button"
                    @click="open = ! open"
                    title="{{ $group['label'] }}"
                    class="nav-group-toggle {{ $rowBase }} w-full text-xs font-semibold uppercase tracking-wide
                           {{ $groupOn ? 'text-primary-700 dark:text-primary-300' : 'text-gray-500 dark:text-gray-400' }}
                           hover:bg-gray-100 dark:hover:bg-gray-700/60"
                    :aria-expanded="open ? 'true' : 'false'"
                    aria-controls="nav-group-{{ $i }}"
                >
                    <x-icon :name="$group['icon']" size="5" />
                    <span data-rail-hide class="flex-1 text-left">{{ $group['label'] }}</span>
                    <x-icon name="chevron-down" size="4" data-rail-hide
                            class="transition-transform" ::class="open && 'rotate-180'" />
                </button>

                {{--
                    `style="display: none"` when it should start closed: Alpine
                    runs after first paint, so without it a closed group would
                    be visible for a moment on every page load.
                --}}
                <div id="nav-group-{{ $i }}" class="nav-group-items mt-0.5 space-y-0.5 pl-2"
                     x-show="open" x-collapse.duration.150ms
                     @if (! $groupOn) style="display: none;" @endif>
                    @foreach ($group['items'] as $item)
                        @php $on = $isActive($item['active']); @endphp
                        <a href="{{ route($item['route']) }}"
                           data-nav-link
                           data-nav-label="{{ $item['label'] }}"
                           data-nav-group="{{ $group['label'] }}"
                           title="{{ $group['label'] }}: {{ $item['label'] }}"
                           @if ($on) aria-current="page" @endif
                           class="{{ $rowBase }} {{ $on ? $rowOn : $rowIdle }}">
                            <x-icon :name="$item['icon']" size="5" />
                            <span data-rail-hide class="truncate">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    <div class="shrink-0 space-y-1 border-t border-gray-200 p-2 dark:border-gray-700">
        {{-- Only offered when the browser says the app can be installed. --}}
        <button type="button" x-show="installable" x-cloak @click="install()"
                class="{{ $rowBase }} w-full {{ $rowIdle }}">
            <x-icon name="download" size="5" />
            <span data-rail-hide>Install app</span>
        </button>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit"
                    class="{{ $rowBase }} w-full text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">
                <x-icon name="logout" size="5" />
                <span data-rail-hide>Log out</span>
            </button>
        </form>

        {{-- Folds the sidebar down to icons. Only meaningful once it is fixed in place. --}}
        <button type="button" @click="toggleRail()"
                class="{{ $rowBase }} hidden w-full {{ $rowIdle }} lg:flex"
                :aria-pressed="rail ? 'true' : 'false'">
            <x-icon name="chevron-left" size="5" ::class="rail && 'rotate-180'" class="transition-transform" />
            <span data-rail-hide>Collapse menu</span>
        </button>
    </div>
</aside>
