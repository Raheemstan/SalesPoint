<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| UI consistency
|--------------------------------------------------------------------------
|
| Guards for the things the consistency pass fixed, which are all easy to
| reintroduce by hand later and invisible until a shop in another country
| complains:
|
|   - a currency symbol typed into a view instead of money()
|   - alert() instead of the app's toast stack
|   - select2 / jQuery creeping back in after being deliberately removed
|   - a screen that simply does not render for one of the roles
|
| These are deliberately file-level greps rather than assertions about
| markup: the point is to stop a habit coming back, not to freeze a layout.
|
*/

/**
 * Every Blade template in the app.
 *
 * @return array<string, string>  path => contents
 */
function bladeFiles(?string $under = null): array
{
    $root = resource_path('views' . ($under ? DIRECTORY_SEPARATOR . $under : ''));

    if (! is_dir($root)) {
        return [];
    }

    $files = [];

    $walker = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($walker as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $relative = str_replace(resource_path('views') . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $files[str_replace('\\', '/', $relative)] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($files);

    return $files;
}

/*
| The feature screens this pass covers. pos/, tables/, table-orders/,
| branches/ and transfers/ are deliberately left out: they belong to other
| workstreams, and a guard that fails on someone else's in-flight file stops
| being a useful signal.
*/
function ownedViewDirs(): array
{
    return [
        'adjustments', 'categories', 'customers', 'expenses', 'products',
        'purchases', 'reorder', 'sales', 'serials', 'settings', 'shifts',
        'stock-takes', 'suppliers',
    ];
}

test('no Blade template hardcodes a currency symbol', function () {
    /*
    | money() and currency() read the shop's own symbol, so a literal one in a
    | view prints the wrong currency on every non-Nigerian install. The naira
    | sign is the one that was actually typed in by hand around the app; the
    | fallback in config/sectors.php is the legitimate place for it.
    */
    $offenders = [];

    foreach (bladeFiles() as $path => $contents) {
        if (str_contains($contents, '₦')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Use money() or currency() instead of a literal symbol in: ' . implode(', ', $offenders));
});

test('no feature screen calls alert()', function () {
    /*
    | The app has one way to tell the user something: window.toast(msg, {
    | variant: 'error' }), which goes through the same #toast-stack as flash
    | messages so everything looks the same. A bare alert() is a different,
    | blocking, unstyled dialog.
    |
    | "alert-triangle" (an icon name), role="alert" and the alert_quantity
    | column are all fine - only a call is matched.
    */
    $offenders = [];

    foreach (ownedViewDirs() as $dir) {
        foreach (bladeFiles($dir) as $path => $contents) {
            if (preg_match('/(?<![\w-])alert\s*\(/', $contents)) {
                $offenders[] = $path;
            }
        }
    }

    expect($offenders)->toBe([], 'Use window.toast(msg, { variant: "error" }) instead of alert() in: ' . implode(', ', $offenders));
});

test('select2 and jQuery are not loaded or used anywhere', function () {
    /*
    | Both were removed on purpose: the one thing select2 was used for is now
    | x-searchable-select, and nothing is fetched from a CDN because the till
    | has to start with no internet.
    |
    | This looks for them being *used* rather than merely named - several files
    | carry a comment explaining that they are gone, and those comments are the
    | documentation for this rule, not a breach of it.
    */
    $offenders = [];

    // Nothing declares them as a dependency.
    $package = json_decode((string) file_get_contents(base_path('package.json')), true) ?: [];

    foreach (['dependencies', 'devDependencies'] as $section) {
        foreach (array_keys($package[$section] ?? []) as $dependency) {
            if (preg_match('/jquery|select2/i', $dependency)) {
                $offenders[] = "package.json: {$dependency}";
            }
        }
    }

    // Nothing pulls them in over the wire, imports them, or calls them.
    $patterns = [
        '/<script[^>]+(?:jquery|select2)/i' => 'a script tag',
        '/(?:import|require)\s*\(?\s*[\'"][^\'"]*(?:jquery|select2)/i' => 'an import',
        '/\.select2\s*\(/i' => 'a .select2() call',
        '/(?:^|[^\w$])(?:jQuery|\$)\s*\(\s*(?:document|[\'"])/' => 'a jQuery call',
    ];

    $sources = bladeFiles();

    foreach (['js', 'css'] as $dir) {
        $base = resource_path($dir);

        if (! is_dir($base)) {
            continue;
        }

        $walker = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walker as $file) {
            if ($file->isFile()) {
                $sources['resources/' . $dir . '/' . $file->getFilename()] = (string) file_get_contents($file->getPathname());
            }
        }
    }

    foreach ($sources as $path => $contents) {
        foreach ($patterns as $pattern => $what) {
            if (preg_match($pattern, $contents)) {
                $offenders[] = "{$path}: {$what}";
            }
        }
    }

    expect($offenders)->toBe([], 'select2/jQuery are gone; use x-searchable-select and plain Alpine. Found: ' . implode(', ', $offenders));
});

test('the restyled screens render for a manager', function () {
    /*
    | A smoke test across every screen the pass touched. It is here because a
    | component slot typed wrongly, an x-icon that does not exist or a variable
    | the controller never passed all render as a 500 that no other test on
    | that screen would notice.
    |
    | The serials screen 404s unless the trade records them
    | (SerialController::index), so the feature is switched on explicitly - a
    | per-install "feature_*" setting beats the business type's preset.
    */
    Category::factory()->create();
    Supplier::factory()->create();
    Product::factory()->create();

    Setting::updateOrCreate(['key' => 'feature_serials'], ['value' => '1']);
    Cache::forget('settings_cache');

    $this->actingAs(User::factory()->manager()->create());

    $screens = [
        'products.index', 'products.import.form',
        'categories.index', 'expenses.index',
        'customers.index', 'suppliers.index',
        'purchases.index', 'purchases.create', 'purchases.returns.index',
        'sales.index',
        'stock-takes.index', 'stock-takes.create',
        'adjustments.index', 'reorder.index',
        'shifts.index', 'serials.index',
        'settings.index',
    ];

    // Collected rather than asserted one at a time, so a failure names the screen.
    $statuses = [];

    foreach ($screens as $screen) {
        $statuses[$screen] = $this->get(route($screen))->getStatusCode();
    }

    expect($statuses)->toBe(array_fill_keys($screens, 200));
});

test('the screens a cashier is allowed to see render for a cashier', function () {
    Product::factory()->create();

    $this->actingAs(User::factory()->cashier()->create());

    foreach (['dashboard', 'pos.index', 'sales.index'] as $screen) {
        $this->get(route($screen))->assertOk();
    }
});

test('the admin-only screens render for an admin', function () {
    $this->actingAs(User::factory()->admin()->create());

    $statuses = [];

    foreach (['settings.index', 'settings.audit', 'settings.backups'] as $screen) {
        $statuses[$screen] = $this->get(route($screen))->getStatusCode();
    }

    expect($statuses)->toBe([
        'settings.index' => 200,
        'settings.audit' => 200,
        'settings.backups' => 200,
    ]);
});

test('every component tag actually compiles to a component', function () {
    /*
    | A Blade @if inside a component tag's attribute list stops the tag being
    | recognised as a component. It does not raise anything: the tag survives
    | into the compiled template as literal <x-...> markup, the browser ignores
    | the unknown element, and the field simply is not on the page. This pass
    | shipped exactly that bug on the product form's category picker, and the
    | screen still returned 200.
    |
    | So: compile every template and fail on any <x-...> left in the output.
    | Put a conditional attribute on a plain wrapper element instead, or bind it
    | with :attribute="$condition ? ... : null".
    */
    $compiler = app('blade.compiler');
    $offenders = [];

    foreach (bladeFiles() as $path => $contents) {
        // The library documents its own usage in comments; those are examples.
        if (str_starts_with($path, 'components/')) {
            continue;
        }

        if (preg_match_all('/<x-[a-z0-9:._-]+/i', $compiler->compileString($contents), $found)) {
            $offenders[] = $path . ': ' . implode(' ', array_unique($found[0]));
        }
    }

    expect($offenders)->toBe([], 'Uncompiled component tags (a @if in the tag\'s attributes?): ' . implode(', ', $offenders));
});

test('the long option lists are type-to-filter, not bare selects', function () {
    /*
    | x-searchable-select is what replaced select2. It keeps the real <select>
    | in the form - so the field name, `required`, the change event and old()
    | all still work - and only puts a filterable listbox in front of it.
    |
    | This asserts both halves: the component is actually in use on the screens
    | with hundreds of options, and the underlying field names the controllers
    | read are untouched.
    */
    Category::factory()->create();
    Supplier::factory()->create();
    Product::factory()->create();

    $this->actingAs(User::factory()->manager()->create());

    $expectations = [
        // screen => the field names that must survive the enhancement
        'products.index' => ['name="category_id"', 'name="supplier_id"'],
        'purchases.create' => ['name="supplier_id"', 'name="product_ids[]"'],
        'purchases.index' => ['name="supplier_id"'],
        'sales.index' => ['name="cashier"'],
    ];

    $missing = [];

    foreach ($expectations as $screen => $fields) {
        $html = $this->get(route($screen))->assertOk()->getContent();

        if (! str_contains($html, 'searchableSelect')) {
            $missing[] = "{$screen} has no searchable select";
        }

        foreach ($fields as $field) {
            if (! str_contains($html, $field)) {
                $missing[] = "{$screen} lost {$field}";
            }
        }
    }

    expect($missing)->toBe([]);
});

test('the restyled screens render in every business type', function () {
    /*
    | The nouns and the optional fields on these screens come from the trade's
    | preset, so each one is a different render path: a salon has no stock at
    | all, a pharmacy has batches and expiry dates, electronics has serials.
    */
    Category::factory()->create();
    Supplier::factory()->create();
    Product::factory()->create();

    $this->actingAs(User::factory()->manager()->create());

    $screens = ['products.index', 'sales.index', 'purchases.create', 'reorder.index'];
    $broken = [];

    foreach (array_keys(config('sectors.types')) as $type) {
        Setting::updateOrCreate(['key' => 'business_type'], ['value' => $type]);
        Cache::forget('settings_cache');

        foreach ($screens as $screen) {
            $status = $this->get(route($screen))->getStatusCode();

            if ($status !== 200) {
                $broken[] = "{$screen} returned {$status} for business type {$type}";
            }
        }
    }

    expect($broken)->toBe([]);
});

test('a validation error is reported once, not twice', function () {
    /*
    | The shell renders $errors itself, above the page body. products/index
    | also had its own @if ($errors->any()) block near the top, so a failed
    | save listed every message twice.
    */
    $this->actingAs(User::factory()->manager()->create());

    $this->from(route('products.index'))
        ->post(route('products.store'), [])
        ->assertRedirect(route('products.index'));

    $html = $this->get(route('products.index'))->assertOk()->getContent();

    expect(substr_count($html, 'name field is required'))->toBe(1);
});
