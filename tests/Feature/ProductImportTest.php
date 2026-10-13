<?php

use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // The two-step import parks the uploaded file between the preview and the
    // confirmation, so the disk it parks on is faked here.
    Storage::fake('local');

    $this->actingAs(User::factory()->manager()->create());
});

function csvFile(string $contents, string $name = 'products.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

/** Upload a file and return the preview response (nothing is written yet). */
function preview(string $contents)
{
    return test()->post(route('products.import.store'), ['file' => csvFile($contents)]);
}

/** Upload, then confirm, in the two steps the screen uses. */
function importCsv(string $contents, array $confirmFields = [])
{
    $token = preview($contents)->viewData('preview')['token'];

    return test()->post(route('products.import.store'), ['token' => $token] + $confirmFields);
}

test('the template is just the header row, and the export round-trips through it', function () {
    Product::factory()->create(['name' => 'Sugar', 'sku' => 'SUG-1', 'cost_price' => 10, 'sale_price' => 15]);

    $template = $this->get(route('products.import.template'))->assertOk();
    $header = trim($template->streamedContent());

    expect($header)->toContain('name')->toContain('cost_price')->toContain('sale_price')
        ->and(substr_count($header, "\n"))->toBe(0);

    $export = $this->get(route('products.export'))->assertOk();
    $lines = array_filter(explode("\n", $export->streamedContent()));

    expect(trim($lines[0]))->toBe($header)   // same columns in the same order
        ->and($lines[1])->toContain('Sugar')
        // money as a bare number: no currency symbol in a CSV cell
        ->and($lines[1])->not->toContain(currency());
});

test('a product name that looks like a formula is neutralised in the export', function () {
    Product::factory()->create(['name' => '=1+1', 'sku' => 'EQ-1']);

    $csv = $this->get(route('products.export'))->assertOk()->streamedContent();

    expect($csv)->toContain("'=1+1");
});

test('a file whose headers do not match is rejected by naming the missing columns', function () {
    $response = preview("product,price\nSugar,10\n");

    $response->assertSessionHas('error');
    expect(session('error'))->toContain('name')->toContain('cost_price')->toContain('sale_price');

    expect(Product::count())->toBe(0);
});

test('the preview writes nothing and says what would be created, updated and rejected', function () {
    Product::factory()->create(['sku' => 'SUG-1', 'name' => 'Sugar', 'cost_price' => 1, 'sale_price' => 2]);

    $csv = <<<CSV
    name,sku,category,cost_price,sale_price
    Sugar 1kg,SUG-1,Groceries,12,18
    Salt,SALT-1,Groceries,5,8
    ,NO-NAME-1,Groceries,5,8
    CSV;

    $response = preview($csv)->assertOk();
    $plan = $response->viewData('preview');

    expect($plan['create'])->toHaveCount(1)
        ->and($plan['update'])->toHaveCount(1)
        ->and($plan['errors'])->toHaveCount(1)
        ->and($plan['errors'][0]['line'])->toBe(4);              // header is row 1

    // the dry run really is dry
    expect(Product::count())->toBe(1)
        ->and(Product::first()->name)->toBe('Sugar')
        ->and(Category::count())->toBe(1);                       // only the factory's own category

    $response->assertSee('Row')->assertSee('4')->assertSee('name field is required', false);
});

test('one bad row is reported with its row number and nothing is imported until it is dealt with', function () {
    $csv = <<<CSV
    name,sku,category,cost_price,sale_price
    Rice,RICE-1,Grains,100,150
    Beans,BEANS-1,Grains,abc,150
    Yam,YAM-1,Grains,200,260
    CSV;

    // confirming with errors still present changes nothing
    importCsv($csv)->assertSessionHas('error');
    expect(Product::count())->toBe(0);

    // the person has to say explicitly that the bad row may be skipped
    importCsv($csv, ['skip_invalid' => 1])->assertSessionHas('success');

    expect(Product::count())->toBe(2)
        ->and(Product::where('sku', 'BEANS-1')->exists())->toBeFalse()
        ->and(Product::where('sku', 'RICE-1')->exists())->toBeTrue();
});

test('an import matches an existing product by SKU instead of duplicating it', function () {
    $existing = Product::factory()->create([
        'sku' => 'SUG-1', 'name' => 'Sugar', 'cost_price' => 1, 'sale_price' => 2, 'stock_quantity' => 30,
    ]);

    importCsv("name,sku,category,cost_price,sale_price,stock_quantity\nSugar 1kg,SUG-1,Groceries,12,18,999\n")
        ->assertSessionHas('success');

    expect(Product::count())->toBe(1);

    $existing->refresh();
    expect($existing->name)->toBe('Sugar 1kg')
        ->and((float) $existing->cost_price)->toBe(12.0)
        // the import never moves stock on an existing product: that needs a reason
        ->and($existing->stock_quantity)->toBe(30)
        ->and(InventoryLog::count())->toBe(0);
});

test('an import matches on barcode when there is no SKU to go on', function () {
    $existing = Product::factory()->create(['sku' => 'OLD-1', 'barcode' => '5012345678900', 'name' => 'Old name']);

    importCsv("name,barcode,cost_price,sale_price\nNew name,5012345678900,3,4\n")->assertSessionHas('success');

    expect(Product::count())->toBe(1)->and($existing->fresh()->name)->toBe('New name');
});

test('a new product gets its opening stock as an inventory movement', function () {
    importCsv("name,sku,category,cost_price,sale_price,stock_quantity\nRice,RICE-1,Grains,100,150,40\n")
        ->assertSessionHas('success');

    $product = Product::where('sku', 'RICE-1')->first();
    expect($product->stock_quantity)->toBe(40);

    $log = InventoryLog::where('product_id', $product->id)->first();
    expect($log->type)->toBe('IN')->and($log->quantity)->toBe(40)->and($log->reason)->toContain('Opening stock');
});

test('categories and suppliers are created by name, matching case-insensitively', function () {
    Category::factory()->create(['name' => 'Groceries']);
    Supplier::create(['name' => 'Acme', 'phone' => '1', 'address' => 'x']);

    $csv = <<<CSV
    name,sku,category,supplier,cost_price,sale_price
    Rice,RICE-1,groceries,ACME,100,150
    Soap,SOAP-1,Toiletries,Unilever,50,70
    CSV;

    importCsv($csv)->assertSessionHas('success');

    // the existing two were reused, the two new names were created
    expect(Category::count())->toBe(2)->and(Supplier::count())->toBe(2);

    $rice = Product::where('sku', 'RICE-1')->first();
    expect($rice->category->name)->toBe('Groceries')->and($rice->supplier->name)->toBe('Acme');
    expect(Product::where('sku', 'SOAP-1')->first()->category->name)->toBe('Toiletries');
});

test('a new product without a category is rejected, because the column cannot be null', function () {
    importCsv("name,sku,cost_price,sale_price\nRice,RICE-1,100,150\n", ['skip_invalid' => 1]);

    expect(Product::count())->toBe(0);

    $plan = preview("name,sku,cost_price,sale_price\nRice,RICE-1,100,150\n")->viewData('preview');
    expect($plan['errors'][0]['messages'][0])->toContain('Category is required');
});

test('spreadsheet spellings are accepted: grouped numbers, yes/no and odd header case', function () {
    $csv = "Name,SKU,Category,Cost Price,Sale Price,Stock Quantity,Track Stock,Is Service\n"
        . "\"Bag of rice\",RICE-50,Grains,\"12,500.00\",\"15,000.00\",4,yes,no\n";

    importCsv($csv)->assertSessionHas('success');

    $product = Product::where('sku', 'RICE-50')->first();
    expect((float) $product->cost_price)->toBe(12500.0)
        ->and((float) $product->sale_price)->toBe(15000.0)
        ->and($product->track_stock)->toBeTrue()
        ->and($product->is_service)->toBeFalse();
});

test('the same SKU twice in one file is rejected rather than quietly overwritten', function () {
    $csv = <<<CSV
    name,sku,category,cost_price,sale_price
    Rice,RICE-1,Grains,100,150
    Rice again,RICE-1,Grains,120,150
    CSV;

    $plan = preview($csv)->viewData('preview');

    expect($plan['create'])->toHaveCount(1)
        ->and($plan['errors'])->toHaveCount(1)
        ->and($plan['errors'][0]['line'])->toBe(3)
        ->and($plan['errors'][0]['messages'][0])->toContain('row 2');
});

test('a barcode that belongs to another product is rejected instead of breaking the unique index', function () {
    // The row below matches B-1 on its SKU, but carries A-1's barcode.
    Product::factory()->create(['sku' => 'A-1', 'barcode' => '5012345678900']);
    Product::factory()->create(['sku' => 'B-1', 'barcode' => null]);

    $plan = preview("name,sku,barcode,category,cost_price,sale_price\nOther,B-1,5012345678900,Grains,1,2\n")
        ->viewData('preview');

    expect($plan['errors'])->toHaveCount(1)
        ->and($plan['errors'][0]['messages'][0])->toContain('already belongs to another product');
});

test('a file with a header but no rows, or no file at all, is refused politely', function () {
    preview("name,cost_price,sale_price\n")->assertSessionHas('error');

    $this->post(route('products.import.store'), [])->assertSessionHasErrors('file');
    $this->post(route('products.import.store'), ['file' => UploadedFile::fake()->create('notes.pdf', 10)])
        ->assertSessionHasErrors('file');
});

test('a product with no SKU in the file still gets one, as the product form does', function () {
    importCsv("name,category,cost_price,sale_price\nLoose sweets,Sweets,1,2\n")->assertSessionHas('success');

    expect(Product::first()->sku)->toStartWith('SKU-');
});

test('a stale or forged token is refused', function () {
    $this->post(route('products.import.store'), ['token' => 'not a token'])->assertSessionHasErrors('token');
    $this->post(route('products.import.store'), ['token' => str_repeat('a', 32)])->assertSessionHas('error');

    expect(Product::count())->toBe(0);
});

test('the import form renders with the column list', function () {
    $this->get(route('products.import.form'))->assertOk()
        ->assertSee('cost_price')
        ->assertSee('Download template');
});
