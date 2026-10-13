<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| The application shell
|--------------------------------------------------------------------------
|
| Covers the things that are easy to break and expensive to notice: a CDN tag
| creeping back into the layout (which makes the whole app unstyled on a till
| with no internet), the nav showing a cashier pages they cannot open, and the
| PWA files going missing or malformed.
|
| Note the suite runs withoutVite() (see tests/Pest.php), so none of this
| depends on `npm run build` having been run.
|
*/

/** Point the sector helpers at a business type without touching the real settings cache. */
function useBusinessType(string $type): void
{
    Setting::updateOrCreate(['key' => 'business_type'], ['value' => $type]);
    Cache::forget('settings_cache');
}

test('the nav shows a manager the Stock group and hides it from a cashier', function () {
    $stockLinks = ['Stock takes', 'Adjustments', 'Reorder list'];

    $manager = $this->actingAs(User::factory()->manager()->create())
        ->get(route('dashboard'))
        ->assertOk();

    foreach ($stockLinks as $link) {
        $manager->assertSee($link);
    }

    $manager->assertSee(route('stock-takes.index'))
        ->assertSee(route('adjustments.index'))
        ->assertSee(route('reorder.index'));

    $cashier = $this->actingAs(User::factory()->cashier()->create())
        ->get(route('dashboard'))
        ->assertOk();

    foreach ($stockLinks as $link) {
        $cashier->assertDontSee($link);
    }

    // Not merely the labels: the whole group, links and all, must be absent.
    $cashier->assertDontSee(route('stock-takes.index'))
        ->assertDontSee(route('adjustments.index'))
        ->assertDontSee(route('reorder.index'));
});

test('a cashier only gets the groups they can reach', function () {
    $response = $this->actingAs(User::factory()->cashier()->create())
        ->get(route('dashboard'))
        ->assertOk();

    // Till and Sales are theirs.
    $response->assertSee(route('pos.index'))
        ->assertSee(route('pos.parked'))
        ->assertSee(route('shifts.index'))
        ->assertSee(route('sales.index'));

    // Everything behind role:admin|manager is gone, group headings included.
    foreach (['Catalogue', 'Reports', 'Supplier returns', 'Receivables', 'Payables'] as $hidden) {
        $response->assertDontSee($hidden);
    }

    /*
    | Matched as a full href. A bare URL would not do for settings.index:
    | "http://localhost/settings" is a prefix of the theme-save URL the layout
    | legitimately prints on every page.
    */
    foreach (['products.index', 'categories.index', 'customers.index', 'suppliers.index',
              'reports.sales', 'reports.valuation', 'settings.index'] as $route) {
        $response->assertDontSee('href="' . route($route) . '"', false);
    }
});

test('the nav links to the shift and customer screens', function () {
    // Both landed recently and were reachable only by typing the URL.
    $this->actingAs(User::factory()->manager()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('shifts.index'))
        ->assertSee('My shift')
        ->assertSee(route('customers.index'))
        ->assertSee(route('suppliers.index'));
});

test('admin-only pages are only in an admin nav', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('settings.audit'))
        ->assertSee(route('settings.backups'))
        ->assertSee('Audit log');

    $this->actingAs(User::factory()->manager()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('settings.index'))
        ->assertDontSee(route('settings.audit'))
        ->assertDontSee('Audit log');
});

test('every nav route in the sidebar actually resolves', function () {
    // A typo in a route name would otherwise only show up as a 500 for whoever
    // happens to be the first manager to load a page.
    $html = $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    preg_match_all('/data-nav-link\s+data-nav-label="([^"]*)"/', $html, $matches);

    expect($matches[1])->not->toBeEmpty()
        ->and(count($matches[1]))->toBeGreaterThan(20);
});

test('the nav uses the trade\'s own nouns', function () {
    useBusinessType('pharmacy');

    $this->actingAs(User::factory()->manager()->create())
        ->get(route('dashboard'))
        ->assertOk()
        // config/sectors.php: a pharmacy sells Drugs to Patients, by Drug class.
        ->assertSee('Drugs')
        ->assertSee('Patients')
        ->assertSee('Drug classes')
        ->assertDontSee('>Products<', false);
});

test('a sector that keeps no stock does not get stock screens', function () {
    useBusinessType('services');

    $response = $this->actingAs(User::factory()->manager()->create())
        ->get(route('dashboard'))
        ->assertOk();

    // feature('stock') is false for the services preset.
    $response->assertDontSee(route('stock-takes.index'))
        ->assertDontSee(route('reorder.index'))
        // Purchases are still kept, so the group itself survives.
        ->assertSee(route('purchases.index'));
});

/*
| The regression this guards: Tailwind, Alpine, Chart.js, jQuery and select2
| were all loaded over the internet, so the app was unstyled and
| non-functional at a till with no connection.
*/
function expectNoRemoteAssets(string $html): void
{
    /*
    | asset() and route() print absolute URLs on this install's own host, so
    | those are stripped before looking for anything that points off-site.
    */
    $offSite = str_replace(url('/'), '', $html);

    expect($offSite)->not->toMatch('/<script[^>]+src=["\']https?:/i')
        ->and($offSite)->not->toMatch('/<link[^>]+href=["\']https?:/i')
        ->and($offSite)->not->toMatch('/@import\s+url\(["\']?https?:/i');

    foreach ([
        'cdn.tailwindcss.com',
        'unpkg.com',
        'cdn.jsdelivr.net',
        'code.jquery.com',
        'fonts.bunny.net',
        'fonts.googleapis.com',
        'select2',
        'jquery',
    ] as $cdn) {
        expect($html)->not->toContain($cdn);
    }
}

test('the app layout loads no asset from a CDN', function () {
    $html = $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expectNoRemoteAssets($html);
});

test('the guest layout loads no asset from a CDN', function () {
    // The login screen is the first thing a till shows; it used to wait on a
    // font CDN before it could render its own labels.
    $html = $this->get(route('login'))->assertOk()->getContent();

    expectNoRemoteAssets($html);
});

test('class names in the layout are literal, not built at runtime', function () {
    /*
    | The old layout wrote bg-{{ $color }}-100 for its flash messages. Tailwind
    | only emits classes it can read as literal text, so those compiled to
    | nothing the moment the runtime CDN build was dropped.
    */
    $html = $this->actingAs(User::factory()->admin()->create())
        ->withSession(['success' => 'Saved the thing.'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Saved the thing.')
        ->getContent();

    // A half-interpolated utility ("bg--100", "text-{") is the symptom.
    expect($html)->not->toMatch('/\b(bg|text|border|ring)-(-|\{)/');

    // And the real thing is present, spelled out.
    expect($html)->toContain('bg-green-50');
});

test('flash messages and validation errors are rendered as dismissible notices', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->withSession(['error' => 'The printer is not responding.'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('The printer is not responding.')
        // The toast host, listening for both server flashes and window.toast().
        ->assertSee('id="toast-stack"', false)
        ->assertSee('toastStack(', false);
});

test('the shell leaves a named hole for the open-shift indicator', function () {
    $this->actingAs(User::factory()->cashier()->create())
        ->get(route('dashboard'))
        ->assertOk()
        // Another agent fills this with @section('shift-indicator') or by
        // creating the shifts._indicator view.
        ->assertSee('id="shift-indicator"', false);
});

test('the shell is reachable by keyboard and labelled for screen readers', function () {
    $this->actingAs(User::factory()->manager()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Skip to main content')
        ->assertSee('id="main-content"', false)
        ->assertSee('aria-label="Main navigation"', false)
        ->assertSee('aria-controls="app-sidebar"', false)
        ->assertSee('aria-current="page"', false);
});

test('the manifest is present and valid JSON', function () {
    $path = public_path('manifest.json');

    expect($path)->toBeReadableFile();

    $manifest = json_decode(file_get_contents($path), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($manifest)->toBeArray()
        ->and($manifest)->toHaveKeys(['name', 'short_name', 'start_url', 'scope', 'display', 'icons'])
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['theme_color'])->toBeString();

    // Every icon the manifest promises has to actually be on disk, at the size
    // it claims - a lying manifest is why installs silently fall back to a
    // screenshot of the page.
    $sizes = [];

    foreach ($manifest['icons'] as $icon) {
        expect($icon)->toHaveKeys(['src', 'sizes', 'type']);

        $file = public_path(ltrim($icon['src'], '/'));
        expect($file)->toBeReadableFile();

        [$width, $height] = getimagesize($file);
        [$claimedWidth, $claimedHeight] = array_map('intval', explode('x', $icon['sizes']));

        expect([$width, $height])->toBe([$claimedWidth, $claimedHeight]);

        $sizes[] = $icon['sizes'];
    }

    expect($sizes)->toContain('192x192')->toContain('512x512');

    // Installable on Android needs a maskable icon, or the launcher crops it badly.
    expect(collect($manifest['icons'])->pluck('purpose')->all())->toContain('maskable');
});

test('the service worker is present and parses as JavaScript', function () {
    $path = public_path('sw.js');

    expect($path)->toBeReadableFile();

    $source = file_get_contents($path);

    expect($source)->not->toBeEmpty()
        // The three handlers that make it a shell cache.
        ->and($source)->toContain("addEventListener('install'")
        ->and($source)->toContain("addEventListener('activate'")
        ->and($source)->toContain("addEventListener('fetch'")
        // The offline fallback, and the versioned cache names that stop a
        // deploy serving stale assets.
        ->and($source)->toContain('offline.html')
        ->and($source)->toContain('salespoint-shell-')
        // Scope limit: non-GET requests are never touched, so a checkout can
        // never be answered or queued from the cache.
        ->and($source)->toContain("request.method !== 'GET'");

    expect(public_path('offline.html'))->toBeReadableFile();

    // Really parse it, when there is a node to parse it with.
    $node = (new Symfony\Component\Process\ExecutableFinder)->find('node');

    if (! $node) {
        $this->markTestSkipped('node is not on PATH, so sw.js syntax was not parsed.');
    }

    $check = new Symfony\Component\Process\Process([$node, '--check', $path]);
    $check->run();

    expect($check->isSuccessful())->toBeTrue($check->getErrorOutput());
});

test('the shell registers the service worker and links the manifest', function () {
    $html = $this->actingAs(User::factory()->cashier()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('rel="manifest"')
        ->toContain('manifest.json')
        // The build token the worker names its caches after.
        ->toMatch('/<meta name="sw-version" content="[^"]+"/');
});

test('the offline page needs nothing from the network', function () {
    $html = file_get_contents(public_path('offline.html'));

    // It is what the worker serves when there is no connection, so a
    // stylesheet, script or font it had to fetch would leave it blank.
    expect($html)->not->toMatch('/<script[^>]+src=/i')
        ->and($html)->not->toMatch('/<link[^>]+rel=["\']stylesheet/i')
        ->and($html)->not->toContain('/build/')
        ->and($html)->toContain('You are offline');
});

test('the dead CDN copies are gone from public/', function () {
    // Vendored copies of the Tailwind and Alpine CDN builds, referenced by
    // nothing once the layout stopped loading them.
    expect(file_exists(public_path('tailwindcdn.js')))->toBeFalse()
        ->and(file_exists(public_path('alpine.js')))->toBeFalse();
});

test('the shared UI components render', function () {
    /*
    | A smoke test for the components other pages are meant to adopt: a compile
    | error in one of these would otherwise only surface when an agent first
    | used it.
    |
    | The error bag has to be shared by hand - in a real request the web
    | middleware does it, but $this->blade() renders outside that.
    */
    Illuminate\Support\Facades\View::share('errors', new Illuminate\Support\ViewErrorBag);

    $html = $this->blade(<<<'BLADE'
            <x-page-header title="Products" subtitle="238 in stock">
                <x-slot:actions><button>Add</button></x-slot:actions>
            </x-page-header>

            <x-card title="Takings" subtitle="Today">
                <x-stat label="Sales" value="N4,300" delta="+12%" trend="up" icon="coins" />
                <x-badge variant="green" dot>Completed</x-badge>
                <x-badge variant="danger">Voided</x-badge>

                <x-table>
                    <x-slot:head><tr><th>Invoice</th></tr></x-slot:head>
                    <tr><td>INV-1</td></tr>
                </x-table>

                <x-empty title="No sales yet" message="They will show up here.">
                    <x-slot:action><a href="/pos">Open the till</a></x-slot:action>
                </x-empty>

                <x-toast variant="success">Saved.</x-toast>

                <x-searchable-select name="product_id" label="Product"
                    :options="[['value' => 1, 'label' => 'Paracetamol']]" />

                <x-select name="supplier_id" label="Supplier" searchable
                    :options="[['value' => 7, 'label' => 'Acme Ltd']]" />
            </x-card>
        BLADE);

    $html = (string) $html;

    expect($html)->toContain('Products')
        ->toContain('238 in stock')
        ->toContain('Completed')
        ->toContain('No sales yet')
        ->toContain('Paracetamol')
        ->toContain('Acme Ltd')
        // The searchable select keeps a real <select> in the form, so the field
        // still posts, still validates and still fires change events.
        ->toContain('name="product_id"')
        ->toContain('searchableSelect')
        // ...and the one for the role-colour variants being spelled out.
        ->toContain('bg-green-100')
        ->toContain('bg-red-100');
});

test('the existing modal, input and select components still work', function () {
    // Pages already use these; the shell rebuild must not have moved them.
    Illuminate\Support\Facades\View::share('errors', new Illuminate\Support\ViewErrorBag);

    $html = $this->blade(<<<'BLADE'
        <x-modal name="confirm"><p>Sure?</p></x-modal>
        <x-input name="store_name" label="Store Name" value="Corner Shop" />
        <x-select name="role" label="Role" :options="['cashier' => 'Cashier']" value="cashier" />
        <x-textarea name="note" label="Note" />
        <x-checkbox name="show_logo" label="Show Logo" />
        <x-section title="Business Info"><p>...</p></x-section>
    BLADE);

    $html = (string) $html;

    expect($html)->toContain('Sure?')
        ->toContain('Corner Shop')
        ->toContain('name="role"')
        ->toContain('Business Info')
        // The native select, not the searchable one, unless asked for.
        ->not->toContain('searchableSelect');
});
