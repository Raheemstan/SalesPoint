<?php

/**
 * Uploads, end to end.
 *
 * This is the part of the app a non-technical shopkeeper touches on day one -
 * "here is my shop's logo, here is a photo of the thing I sell" - and the part
 * that failed on a real install: the logo uploaded, the row saved, and every
 * picture was a 403 because `php artisan storage:link` had never been run.
 *
 * So this file is about the whole lifecycle rather than the validation rules
 * alone: what is accepted, what reaches the disk and under what name, what
 * happens to the old file when one is replaced or removed, what a screen shows
 * when the row names a file that is not there, and what is left behind when a
 * save fails halfway. Nothing here writes to real storage - Storage::fake()
 * points the public disk at a temporary folder.
 */

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/../BranchHelpers.php';

beforeEach(function () {
    Storage::fake('public');

    $this->manager = User::factory()->manager()->create();
    $this->actingAs($this->manager);
    $this->category = Category::factory()->create();
});

/** The product form, with whatever the test is actually about. */
function mediaProductData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sugar',
        'sku' => 'SUG-1',
        'category_id' => test()->category->id,
        'cost_price' => 10,
        'sale_price' => 15,
        'stock_quantity' => 20,
    ], $overrides);
}

/** Save a product with a picture and hand back the row. */
function productWithPicture(string $filename = 'sugar.jpg', array $overrides = []): Product
{
    test()->post(route('products.store'), mediaProductData(array_merge([
        'image' => UploadedFile::fake()->image($filename, 400, 300),
    ], $overrides)))->assertSessionHas('success');

    return Product::latest('id')->firstOrFail();
}

function mediaService(): MediaService
{
    return app(MediaService::class);
}

/** Real PNG bytes, for the cases where the content has to disagree with the name. */
function pngBytes(int $width = 40, int $height = 40): string
{
    $image = imagecreatetruecolor($width, $height);

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * A PHP file's bytes, assembled rather than written out.
 *
 * Not coyness: a literal script in a test file is what a virus scanner on a
 * developer's machine quarantines, and it would take this file with it.
 */
function phpBytes(): string
{
    return '<' . "?php echo 'this is not a picture';";
}

/** The first bytes of a GIF - enough for finfo to call it image/gif. */
function gifBytes(): string
{
    return "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff,";
}

/**
 * An upload whose content and name genuinely disagree.
 *
 * UploadedFile::fake() cannot express that: the test double answers
 * getMimeType() from the FILENAME, so every fake is whatever it is called. A
 * real UploadedFile asks the operating system what the bytes are, exactly as a
 * browser upload does - which is the thing being tested here.
 */
function realUpload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'salespoint-media');

    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

/*
|--------------------------------------------------------------------------
| What reaches the disk
|--------------------------------------------------------------------------
*/

test('a picture is stored under a name of our own and is reachable at the URL the list prints', function () {
    $product = productWithPicture('my holiday snap.jpg');
    $path = $product->image_path;

    Storage::disk('public')->assertExists($path);

    expect($path)->toStartWith('products/')
        // Nothing the browser sent survives: not the words, not the spaces, not
        // a second extension, and certainly not a path.
        ->and($path)->not->toContain('holiday')
        ->and(basename($path))->toMatch('/^[A-Za-z0-9]{40}\.jpg$/');

    // And what the screen prints is the URL of the file that was actually saved.
    $this->get(route('products.index'))->assertOk()->assertSee(mediaService()->url($path));
});

test('two uploads of the same filename do not collide', function () {
    productWithPicture('logo.png', ['name' => 'Rice', 'sku' => 'RIC-1']);
    productWithPicture('logo.png', ['name' => 'Beans', 'sku' => 'BEA-1']);

    $paths = Product::pluck('image_path');

    expect($paths)->toHaveCount(2)
        ->and($paths->unique())->toHaveCount(2)
        ->and(Storage::disk('public')->allFiles())->toHaveCount(2);

    Storage::disk('public')->assertExists($paths->all());
});

test('the extension on disk comes from what the file contains, not what it is called', function () {
    // A genuine PNG offered as a .jpg. The name is the only thing wrong with it,
    // and the name is the thing we do not trust.
    $this->post(route('products.store'), mediaProductData([
        'image' => realUpload('photo.jpg', pngBytes()),
    ]))->assertSessionHas('success');

    expect(Product::first()->image_path)->toEndWith('.png');
});

/*
|--------------------------------------------------------------------------
| What is refused
|--------------------------------------------------------------------------
*/

test('a file with a picture extension but no picture inside it is refused', function () {
    // The attack this is about: a script under a name the web server might one
    // day be willing to execute, inside a folder that is web-reachable.
    $this->post(route('products.store'), mediaProductData([
        'image' => realUpload('innocent.png', phpBytes()),
    ]))->assertSessionHasErrors('image');

    // The other direction, which an extension check on its own would wave
    // through: a genuine GIF called .png. The list is of what a file IS.
    $this->post(route('products.store'), mediaProductData([
        'image' => realUpload('cat.png', gifBytes()),
    ]))->assertSessionHasErrors('image');

    expect(Product::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('an SVG is refused: it is a scriptable document that happens to draw', function () {
    $this->post(route('products.store'), mediaProductData([
        'image' => realUpload(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><text>hello</text></svg>'
        ),
    ]))->assertSessionHasErrors('image');

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('an oversized file is refused', function () {
    $this->post(route('products.store'), mediaProductData([
        'image' => UploadedFile::fake()->image('huge.jpg')->size(MediaService::MAX_KILOBYTES + 1),
    ]))->assertSessionHasErrors('image');

    expect(Product::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('an absurd canvas is refused however small the file is, and says why in plain words', function () {
    /*
     * A decompression bomb: a few kilobytes on disk, hundreds of megabytes once
     * anything opens it. `max:` cannot see this one, because the file is tiny.
     */
    $this->post(route('products.store'), mediaProductData([
        'image' => UploadedFile::fake()->image('bomb.png', MediaService::MAX_PIXELS + 500, 8),
    ]))->assertSessionHasErrors([
        'image' => 'That picture is too big to use: ' . number_format(MediaService::MAX_PIXELS)
            . ' pixels is the most on either side.',
    ]);

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('the service refuses to store a type it has no extension for, even with validation bypassed', function () {
    // The second lock: nothing can be named onto the public disk by a caller
    // that forgot the rules.
    expect(fn () => mediaService()->store(realUpload('cat.png', gifBytes()), 'products'))
        ->toThrow(RuntimeException::class);

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Replacing, removing and archiving
|--------------------------------------------------------------------------
*/

test('replacing a picture deletes the file it replaced and records that it did', function () {
    $product = productWithPicture('first.jpg');
    $first = $product->image_path;

    $this->put(route('products.update', $product), mediaProductData([
        'image' => UploadedFile::fake()->image('second.png', 200, 200),
    ]))->assertSessionHas('success');

    $second = $product->fresh()->image_path;

    expect($second)->not->toBe($first)
        // One file, not two: storage/app/public does not grow with every edit.
        ->and(Storage::disk('public')->allFiles())->toHaveCount(1);

    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);

    // The old file is gone for good, which is worth a line in the audit log.
    expect(AuditLog::where('action', 'Replaced product image')->count())->toBe(1);
});

test('saving an edit with no new file leaves the picture alone', function () {
    $product = productWithPicture();
    $path = $product->image_path;

    $this->put(route('products.update', $product), mediaProductData(['sale_price' => 99]))
        ->assertSessionHas('success');

    expect($product->fresh()->image_path)->toBe($path);
    Storage::disk('public')->assertExists($path);
});

test('removing a picture clears the column, deletes the file and leaves an audit entry', function () {
    $product = productWithPicture();

    $this->delete(route('products.image.destroy', $product))->assertSessionHas('success');

    expect($product->fresh()->image_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBeEmpty()
        ->and(AuditLog::where('action', 'Removed product image')->count())->toBe(1);

    // Nothing to remove the second time round.
    $this->delete(route('products.image.destroy', $product))->assertSessionHas('error');
});

test('archiving a product keeps its picture, because the sales history still shows it', function () {
    /*
     * The decision this pins down. A product is soft-deleted, so it is still on
     * last quarter's receipts and in every report covering the period it sold
     * in, and it can be restored. Deleting the file would make both of those
     * worse, and nothing ever force-deletes a product (ProductPolicy refuses
     * it), so the file cannot be orphaned that way either. Removing a picture on
     * purpose is its own action, and that one does delete the file.
     */
    $product = productWithPicture();
    $path = $product->image_path;

    $this->delete(route('products.destroy', $product))->assertSessionHas('success');

    Storage::disk('public')->assertExists($path);
    expect(Product::withTrashed()->find($product->id)->image_path)->toBe($path);

    // So a restore brings the picture back with the product.
    Product::withTrashed()->find($product->id)->restore();

    expect(mediaService()->url($path))->not->toBeNull();
    $this->get(route('products.index'))->assertOk()->assertSee($path);
});

/*
|--------------------------------------------------------------------------
| A file that is not there
|--------------------------------------------------------------------------
*/

test('a product whose file has gone missing renders the placeholder and does not error', function () {
    // A restored database, a half-copied deploy: the row survives, the file does
    // not. This must not be a broken-image icon in the middle of the table.
    $product = Product::factory()->create([
        'category_id' => $this->category->id,
        'name' => 'Ghost Rice',
        'image_path' => 'products/not-on-this-disk.jpg',
    ]);

    $html = $this->get(route('products.index'))->assertOk()->getContent();

    // The row is there, and no URL anywhere on the page points at the file that
    // is not. (image_path itself is still in the edit form's data, so that the
    // Remove button can clear the dangling name.)
    expect($html)->toContain('Ghost Rice')
        ->and($html)->not->toContain('storage/products/not-on-this-disk.jpg')
        ->and(mediaService()->url($product->image_path))->toBeNull();

    // The control: with the file there, the <img> is printed.
    Storage::disk('public')->put('products/really-here.jpg', 'pretend this is a jpeg');
    $product->update(['image_path' => 'products/really-here.jpg']);

    expect($this->get(route('products.index'))->getContent())
        ->toContain('storage/products/really-here.jpg');
});

test('the products screen says so when the link that serves pictures is missing', function () {
    /*
     * The 403 a shop reported, and the one upload failure nothing in the app can
     * see for itself: the web server answers those requests without PHP ever
     * being asked, so there is nothing in the log to explain them either.
     */
    config(['filesystems.links' => [
        storage_path('framework/testing/there-is-no-link-here') => storage_path('app/public'),
    ]]);

    $this->get(route('products.index'))->assertOk()->assertSee('php artisan storage:link');

    // ...and nothing to say while the link is in place.
    config(['filesystems.links' => [storage_path('app') => storage_path('app/public')]]);

    $this->get(route('products.index'))->assertOk()->assertDontSee('php artisan storage:link');
});

/*
|--------------------------------------------------------------------------
| A save that fails after the file is written
|--------------------------------------------------------------------------
*/

test('a save that fails after the upload does not leave the file behind', function () {
    // The file has to be written before the row that points at it, because an
    // upload is not part of the transaction and cannot be rolled back with it.
    Event::listen('eloquent.creating: ' . Product::class, function () {
        throw new RuntimeException('the database went away');
    });

    $this->post(route('products.store'), mediaProductData([
        'image' => UploadedFile::fake()->image('rice.jpg'),
    ]))->assertServerError();

    expect(Product::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('a replacement that fails keeps the old picture and throws the new file away', function () {
    $product = productWithPicture('first.jpg');
    $first = $product->image_path;

    Event::listen('eloquent.updating: ' . Product::class, function () {
        throw new RuntimeException('the database went away');
    });

    $this->put(route('products.update', $product), mediaProductData([
        'image' => UploadedFile::fake()->image('second.jpg'),
    ]))->assertServerError();

    expect($product->fresh()->image_path)->toBe($first)
        ->and(Storage::disk('public')->allFiles())->toHaveCount(1);

    Storage::disk('public')->assertExists($first);
});

/*
|--------------------------------------------------------------------------
| Who may do it
|--------------------------------------------------------------------------
*/

test('a cashier cannot upload a picture or strip one off, and nothing is written while finding out', function () {
    $product = productWithPicture();
    $path = $product->image_path;

    $this->actingAs(User::factory()->cashier()->create());

    $this->post(route('products.store'), mediaProductData([
        'sku' => 'CSH-1',
        'image' => UploadedFile::fake()->image('mine.jpg'),
    ]))->assertForbidden();

    $this->put(route('products.update', $product), mediaProductData([
        'image' => UploadedFile::fake()->image('mine.jpg'),
    ]))->assertForbidden();

    $this->delete(route('products.image.destroy', $product))->assertForbidden();

    // Permission is checked before the upload is touched, so a refused request
    // leaves nothing on the disk to clean up either.
    expect($product->fresh()->image_path)->toBe($path)
        ->and(Storage::disk('public')->allFiles())->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Branches and trades
|--------------------------------------------------------------------------
*/

test('a picture belongs to the catalogue, so it is the same picture at every branch', function () {
    /*
     * THE CATALOGUE IS SHARED; ONLY THE STOCK IS PER BRANCH (see Product). A
     * picture is a property of the product, so there is nothing branch-scoped
     * about it: one upload, one file, the same thumbnail at every shop.
     */
    $main = defaultBranch();
    $lekki = makeBranch('LEK');
    enableBranches();

    $this->actingAs(staffAt($main, 'manager'));
    $product = productWithPicture('rice.jpg');
    $path = $product->image_path;

    $this->actingAs(staffAt($lekki, 'manager'))
        ->get(route('products.index'))
        ->assertOk()
        ->assertSee(mediaService()->url($path));

    expect(Product::find($product->id)->image_path)->toBe($path);
});

test('every trade uploads pictures the same way', function () {
    // The picture is not one of the sector features, so the field is on the form
    // whatever the shop sells.
    $broken = [];

    foreach (array_keys(config('sectors.types')) as $type) {
        Setting::set('business_type', $type);

        $response = $this->get(route('products.index'));

        if ($response->getStatusCode() !== 200 || !str_contains($response->getContent(), 'name="image"')) {
            $broken[] = $type;
        }
    }

    expect($broken)->toBe([]);
});
