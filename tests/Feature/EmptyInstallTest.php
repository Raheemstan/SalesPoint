<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\BranchContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Day one of a brand-new shop
|--------------------------------------------------------------------------
|
| A real install produced two user-visible failures that the whole suite
| missed, because every other test seeds the rows it needs first:
|
|   - no public/storage symlink, so every product image was broken or a 403;
|   - a 500 on saving a product, because inventory_logs had no branch_id - the
|     code was ahead of the schema.
|
| The common thread is that nothing loaded the app as a shopkeeper does on the
| first morning: no sales, no customers, no suppliers, no stock movements, no
| second branch. An empty table is where a divide-by-zero, an "average over
| zero rows", a `->first()->name` and a missing empty-state live.
|
| So this walks every screen on an empty database, as each role, and asks only
| that the app answers rather than breaks. It is deliberately driven off
| Route::getRoutes() rather than a hand-typed list of URLs, so a screen added
| next month is covered without anyone remembering to add it here.
|
*/

beforeEach(function () {
    // Nothing here may touch real storage or send mail: several screens render
    // image URLs, and the receipt screens build mailables.
    Storage::fake('public');
    Storage::fake('local');
    Mail::fake();

    /*
    | Every optional feature on.
    |
    | Not because a real shop runs them all, but because a screen behind a
    | switched-off flag answers 404 and is then not tested at all - and the
    | branch, serial, table and transfer screens are the newest code in the app,
    | so they are the likeliest to assume a row exists. With every flag on,
    | "404" stops being an acceptable answer and the sweep below can insist on
    | 200 or 403.
    |
    | Through Setting::set() because that is how a shop does it: a per-install
    | "feature_<name>" beats the business type's preset, and saving a setting
    | flushes the settings cache so feature() sees it at once.
    */
    foreach (array_keys(config('sectors.features')) as $flag) {
        Setting::set('feature_' . $flag, '1');
    }

    // The context caches the branch it resolved, and that answer changes when
    // multi-branch is switched on.
    app(BranchContext::class)->forget();
});

/**
 * Every GET screen worth loading: HTML, no required parameters.
 *
 * Skipped, with the reason for each:
 *
 *   - CSV / PDF / export / download routes stream a file, so "the body has no
 *     PHP notice in it" is meaningless and the assertions below would read a
 *     spreadsheet.
 *   - _debugbar and sanctum are vendor routes, not this app's screens.
 *   - storage/{path} serves an uploaded file.
 *   - the guest-only auth pages (login, password reset, the 2FA challenge) are
 *     reached while signed *out*; the `guest` middleware redirects a signed-in
 *     user away from them, which is correct behaviour and not a screen.
 *   - "/" is a bare redirect to the till, so it has no body to inspect.
 *
 * @return array<string, string>  route name (or URI) => URL
 */
function emptyInstallScreens(): array
{
    $skipNamePatterns = [
        '/^debugbar\./', '/^sanctum\./', '/^storage\./',
        '/^login$/', '/^register$/', '/^logout$/',
        '/^password\./', '/^verification\./', '/^two-factor\.(challenge|verify)$/',
        '/\.csv$/', '/\.pdf$/', '/export/', '/download/', '/template$/',
    ];

    $skipUriPatterns = [
        '#^/?$#', '#^_debugbar#', '#^sanctum#', '#^storage/#',
        '#/(csv|pdf)$#', '#export#', '#download#',
    ];

    $screens = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = $route->uri();
        $name = $route->getName() ?? $uri;

        // A required parameter needs a row to point at, which an empty install
        // has not got. `{param?}` is fine: it simply goes unsupplied.
        if (preg_match('/\{[^}?]+\}/', $uri)) {
            continue;
        }

        foreach ($skipNamePatterns as $pattern) {
            if (preg_match($pattern, $name)) {
                continue 2;
            }
        }

        foreach ($skipUriPatterns as $pattern) {
            if (preg_match($pattern, $uri)) {
                continue 2;
            }
        }

        $screens[$name] = '/' . ltrim($uri, '/');
    }

    ksort($screens);

    return $screens;
}

/**
 * The fragments that say a page rendered but its PHP went wrong.
 *
 * These are the shapes an empty table produces: a variable the controller only
 * assigns inside `if ($rows->count())`, an array offset read off an empty
 * aggregate row, an average divided by a zero count, and a null passed where a
 * string was expected.
 */
function brokenPageFragments(): array
{
    return [
        'Undefined variable',
        'Trying to access array offset',
        'Division by zero',
        'htmlspecialchars(): Argument',
    ];
}

dataset('every role', [
    'admin' => ['admin'],
    'manager' => ['manager'],
    'cashier' => ['cashier'],
]);

test('every screen loads on a completely empty database', function (string $role) {
    $this->actingAs(User::factory()->state(['role' => $role])->create());

    $failures = [];

    foreach (emptyInstallScreens() as $name => $url) {
        $response = $this->get($url);
        $status = $response->status();

        /*
        | 200 means it rendered; 403 means this role may not see it, which is
        | also a working app. Nothing else passes - and that is deliberate:
        | every optional feature is on, so a 404 means a screen has gone
        | missing, a 302 means a role is being bounced somewhere instead of
        | being told no, and a 500 is the app failing to cope with having no
        | data yet. All three are worth a human looking.
        */
        if (! in_array($status, [200, 403], true)) {
            $failures[] = "{$name} ({$url}) answered {$status}";

            continue;
        }

        if ($status !== 200) {
            continue;
        }

        $body = $response->getContent();

        foreach (brokenPageFragments() as $fragment) {
            if (str_contains($body, $fragment)) {
                $failures[] = "{$name} ({$url}) rendered \"{$fragment}\"";
            }
        }
    }

    expect($failures)->toBe([], "Empty-install failures as {$role}:\n" . implode("\n", $failures));
})->with('every role');

test('the route sweep actually covers the main screens', function () {
    /*
    | The sweep above is only worth anything if it is loading real screens. A
    | typo in a skip pattern would quietly reduce it to nothing while still
    | passing, so the screens that matter most are named here. This list is a
    | floor, not the list under test - new routes still arrive automatically.
    */
    $covered = array_keys(emptyInstallScreens());

    foreach ([
        'pos.index', 'dashboard', 'products.index', 'categories.index',
        'customers.index', 'suppliers.index', 'expenses.index', 'purchases.index',
        'sales.index', 'shifts.index', 'reports.sales', 'reports.daily',
        'reports.profit_loss', 'reports.valuation', 'settings.index',
        'adjustments.index', 'stock-takes.index', 'reorder.index',
        // The feature-gated ones, which are only reachable because beforeEach
        // switches every flag on. If a skip pattern ever swallows these, the
        // sweep silently stops covering the newest code in the app.
        'branches.index', 'serials.index', 'tables.index',
    ] as $name) {
        expect($covered)->toContain($name);
    }

    // Guard the other direction: the file-streaming routes must stay out.
    foreach (['reports.sales.report.csv', 'products.export', 'settings.audit.csv'] as $name) {
        expect($covered)->not->toContain($name);
    }
});

test('no screen prints a currency symbol of its own', function () {
    /*
    | money() and currency() read the shop's configured symbol, so the only way
    | to catch a hardcoded one at runtime is to change the symbol and look for
    | the old one. The naira sign is the one that was typed in by hand around
    | the app; config/sectors.php is the legitimate place for it, and this shop
    | is now trading in something else.
    |
    | UiConsistencyTest greps the Blade files for the same thing. This is the
    | other half: a symbol built in a controller, a helper or a component's PHP
    | never appears in a template.
    */
    Setting::set('currency_symbol', 'CUR ');

    $offenders = [];

    foreach (['admin', 'manager', 'cashier'] as $role) {
        $this->actingAs(User::factory()->state(['role' => $role])->create());

        foreach (emptyInstallScreens() as $name => $url) {
            $response = $this->get($url);

            if ($response->status() === 200 && str_contains($response->getContent(), '₦')) {
                $offenders[] = "{$name} ({$url}) as {$role}";
            }
        }
    }

    expect($offenders)->toBe([], "Use money() or currency() instead of a literal symbol in: " . implode(', ', $offenders));
});
