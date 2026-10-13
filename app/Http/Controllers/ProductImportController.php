<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Bulk product import and export by CSV.
 *
 * Onboarding a shop used to mean typing a few hundred items into the product
 * form one at a time. This takes the spreadsheet the shop already has.
 *
 * The import is deliberately a two-step job: the first upload only *reads* the
 * file and shows what would happen (created, updated, rejected, with the row
 * number of every problem), and nothing is written until the person confirms.
 * The file is parsed and validated again at that point, so the preview is never
 * trusted as a source of data.
 */
class ProductImportController extends Controller
{
    /**
     * The one place the CSV format is described.
     *
     * csv header => [
     *   'column'  => the products column it fills,
     *   'rules'   => validation for one cell,
     *   'lookup'  => a model matched by name (and created if missing),
     *   'boolean' => accept yes/no/true/false/1/0,
     *   'feature' => only used when that sector feature is switched on,
     * ]
     *
     * Add a column here and the template, the export, the preview and the
     * import all pick it up; nothing else needs touching.
     */
    public const COLUMNS = [
        'name' => ['column' => 'name', 'rules' => 'required|string|max:255'],
        'sku' => ['column' => 'sku', 'rules' => 'nullable|string|max:100'],
        'barcode' => ['column' => 'barcode', 'rules' => 'nullable|string|max:100'],
        'category' => ['column' => 'category_id', 'rules' => 'nullable|string|max:255', 'lookup' => Category::class],
        // suppliers.phone and .address are NOT NULL, so a supplier created from
        // a name alone needs them filled in rather than left as null.
        'supplier' => ['column' => 'supplier_id', 'rules' => 'nullable|string|max:255', 'lookup' => Supplier::class,
            'creates' => ['phone' => '', 'address' => '']],
        'cost_price' => ['column' => 'cost_price', 'rules' => 'required|numeric|min:0'],
        'sale_price' => ['column' => 'sale_price', 'rules' => 'required|numeric|min:0'],
        'unit' => ['column' => 'unit', 'rules' => 'nullable|string|max:20'],
        'pack_size' => ['column' => 'pack_size', 'rules' => 'nullable|numeric|min:0.001'],
        'stock_quantity' => ['column' => 'stock_quantity', 'rules' => 'nullable|integer|min:0'],
        'alert_quantity' => ['column' => 'alert_quantity', 'rules' => 'nullable|integer|min:0'],
        'reorder_quantity' => ['column' => 'reorder_quantity', 'rules' => 'nullable|integer|min:0'],
        'is_service' => ['column' => 'is_service', 'rules' => 'nullable|boolean', 'boolean' => true],
        'track_stock' => ['column' => 'track_stock', 'rules' => 'nullable|boolean', 'boolean' => true],
        'is_active' => ['column' => 'is_active', 'rules' => 'nullable|boolean', 'boolean' => true],
        'batch_no' => ['column' => 'batch_no', 'rules' => 'nullable|string|max:255', 'feature' => 'expiry'],
        'expiry_date' => ['column' => 'expiry_date', 'rules' => 'nullable|date', 'feature' => 'expiry'],
    ];

    /** Without these there is nothing to import, so the file is refused by name. */
    public const REQUIRED_HEADERS = ['name', 'cost_price', 'sale_price'];

    /** A sanity limit, so one bad paste cannot exhaust the server's memory. */
    public const MAX_ROWS = 5000;

    public function form()
    {
        Gate::authorize('importProducts', StockAdjustment::class);

        return view('products.import', [
            'headers' => $this->headers(),
            'preview' => null,
        ]);
    }

    /**
     * Step one: read the file and show what it would do.
     * Step two (with a token and `confirm`): write it.
     */
    public function store(Request $request)
    {
        Gate::authorize('importProducts', StockAdjustment::class);

        $confirming = $request->filled('token');

        $request->validate($confirming ? [
            // The token names a file this user uploaded a moment ago. The pattern
            // keeps it to a random name inside their own folder - no traversal,
            // no reading somebody else's upload.
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{32}$/'],
            'skip_invalid' => 'nullable|boolean',
        ] : [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        if ($confirming) {
            $path = $this->storedPath($request->user()->id, $request->token);

            if (!Storage::disk('local')->exists($path)) {
                return back()->with('error', 'That upload is no longer available. Please upload the file again.');
            }

            $rows = $this->read(Storage::disk('local')->path($path));
        } else {
            $rows = $this->read($request->file('file')->getRealPath());
        }

        if (is_string($rows)) {
            return back()->with('error', $rows);   // a header or structural problem
        }

        $plan = $this->analyse($rows);

        if (!$confirming) {
            // Keep the file so the confirm step can re-read it rather than
            // importing numbers posted back from a web page.
            $token = Str::random(32);
            Storage::disk('local')->put(
                $this->storedPath($request->user()->id, $token),
                file_get_contents($request->file('file')->getRealPath())
            );

            return view('products.import', [
                'headers' => $this->headers(),
                'preview' => $plan + ['token' => $token],
            ]);
        }

        if (!empty($plan['errors']) && !$request->boolean('skip_invalid')) {
            return back()->with('error', 'Some rows still have problems. Fix the file and upload it again, or choose to skip the rejected rows.');
        }

        $result = $this->import($plan);

        Storage::disk('local')->delete($this->storedPath($request->user()->id, $request->token));

        AuditLog::record(
            "Imported products from CSV: {$result['created']} created, {$result['updated']} updated, {$result['skipped']} skipped",
            'products',
            0
        );

        return redirect()->route('products.import.form')->with(
            'success',
            "Import finished: {$result['created']} created, {$result['updated']} updated"
                . ($result['skipped'] ? ", {$result['skipped']} row(s) skipped" : '') . '.'
        );
    }

    /** The header row on its own, so a shop can see the format it needs. */
    public function template()
    {
        Gate::authorize('importProducts', StockAdjustment::class);

        $headers = $this->headers();

        return response()->streamDownload(function () use ($headers) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            fclose($handle);
        }, 'product_import_template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Every product as CSV, in exactly the columns the import accepts - so a
     * shop can export, edit in a spreadsheet and import the same file back.
     */
    public function export(Request $request)
    {
        Gate::authorize('exportProducts', StockAdjustment::class);

        AuditLog::record('Exported products CSV', 'products', 0);

        $headers = $this->headers();
        $filename = 'products_' . now()->format('Ymd_His') . '.csv';

        $callback = function () use ($headers) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            // cursor() streams: a wholesaler's catalogue should not have to fit in memory.
            foreach (Product::with(['category', 'supplier'])->orderBy('name')->cursor() as $product) {
                fputcsv($handle, array_map(
                    fn($value) => $this->csvSafe($value),
                    $this->exportRow($product, $headers)
                ));
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** The columns in play for this shop (expiry columns only where the trade needs them). */
    private function headers(): array
    {
        return array_keys(array_filter(
            self::COLUMNS,
            fn($spec) => !isset($spec['feature']) || feature($spec['feature'])
        ));
    }

    private function storedPath(int $userId, string $token): string
    {
        return "imports/{$userId}/{$token}.csv";
    }

    /**
     * Stop a spreadsheet treating a product name that starts with "=" as a
     * formula - the same guard SettingController::exportCsv() uses.
     */
    private function csvSafe($value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    private function exportRow(Product $product, array $headers): array
    {
        $row = [];

        foreach ($headers as $header) {
            $row[] = match ($header) {
                'category' => $product->category->name ?? '',
                'supplier' => $product->supplier->name ?? '',
                // Bare numbers: a currency symbol in a CSV cell is not a number.
                'cost_price' => money($product->cost_price, false),
                'sale_price' => money($product->sale_price, false),
                'is_service', 'track_stock', 'is_active' => $product->{$header} ? 'yes' : 'no',
                'expiry_date' => $product->expiry_date?->toDateString() ?? '',
                default => (string) ($product->{self::COLUMNS[$header]['column']} ?? ''),
            };
        }

        return $row;
    }

    /**
     * Read a CSV into rows keyed by normalised header.
     *
     * @return array<int, array<string, string>>|string rows, or a message explaining why the file cannot be used
     */
    private function read(string $path)
    {
        $handle = fopen($path, 'r');

        if (!$handle) {
            return 'The file could not be read. Please try uploading it again.';
        }

        $header = fgetcsv($handle);

        if (!$header) {
            return 'That file is empty.';
        }

        // Excel writes a byte-order mark in front of the first cell.
        $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);
        $header = array_map(fn($cell) => Str::snake(trim(strtolower((string) $cell))), $header);

        $missing = array_diff(self::REQUIRED_HEADERS, $header);

        if ($missing) {
            fclose($handle);

            return 'The file is missing these columns: ' . implode(', ', $missing)
                . '. Download the template to see the format.';
        }

        $known = array_intersect($header, $this->headers());
        $rows = [];
        $line = 1;   // the header was line 1

        while (($cells = fgetcsv($handle)) !== false) {
            $line++;

            // A trailing blank line is not an error, it is just a blank line.
            if (count(array_filter($cells, fn($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);

                return 'That file has more than ' . number_format(self::MAX_ROWS)
                    . ' rows. Please split it into smaller files.';
            }

            $row = ['__line' => $line];

            foreach ($header as $index => $name) {
                // Unknown columns are ignored rather than fatal: a shop's own
                // spreadsheet always has extra columns we do not care about.
                if (in_array($name, $known, true)) {
                    $row[$name] = trim((string) ($cells[$index] ?? ''));
                }
            }

            $rows[] = $row;
        }

        fclose($handle);

        if (empty($rows)) {
            return 'That file has a header row but no products in it.';
        }

        return $rows;
    }

    /**
     * Work out what each row would do, without writing anything.
     *
     * Every row is validated, and a bad one is recorded with its row number
     * instead of stopping the run - a shop with three typos wants all three.
     *
     * @return array{create: array, update: array, errors: array, rows: int}
     */
    private function analyse(array $rows): array
    {
        $plan = ['create' => [], 'update' => [], 'errors' => [], 'rows' => count($rows)];
        $seen = ['sku' => [], 'barcode' => []];

        foreach ($rows as $row) {
            $line = $row['__line'];
            unset($row['__line']);

            $row = $this->normalise($row);
            $messages = $this->validateRow($row);

            $sku = $row['sku'] ?? '';
            $barcode = $row['barcode'] ?? '';

            // Two rows for the same item in one file: the second is almost
            // certainly a mistake, and silently overwriting the first hides it.
            foreach (['sku', 'barcode'] as $key) {
                $value = $key === 'sku' ? $sku : $barcode;

                if ($value !== '' && isset($seen[$key][strtolower($value)])) {
                    $messages[] = ucfirst($key) . " \"{$value}\" also appears on row {$seen[$key][strtolower($value)]}.";
                } elseif ($value !== '') {
                    $seen[$key][strtolower($value)] = $line;
                }
            }

            // Match on SKU first, then barcode: a shop's SKU is the thing it
            // actually maintains, a barcode is whatever the manufacturer printed.
            $product = null;

            if ($sku !== '') {
                $product = Product::withTrashed()->where('sku', $sku)->first();
            }

            if (!$product && $barcode !== '') {
                $product = Product::withTrashed()->where('barcode', $barcode)->first();
            }

            // A new product needs a category: products.category_id is NOT NULL.
            if (!$product && ($row['category'] ?? '') === '') {
                $messages[] = 'Category is required for a new product.';
            }

            // A barcode that already belongs to a different product would break
            // the unique index halfway through the import.
            if ($barcode !== '') {
                $clash = Product::withTrashed()->where('barcode', $barcode)
                    ->when($product, fn($q) => $q->whereKeyNot($product->id))
                    ->exists();

                if ($clash) {
                    $messages[] = "Barcode \"{$barcode}\" already belongs to another product.";
                }
            }

            if ($messages) {
                $plan['errors'][] = [
                    'line' => $line,
                    'name' => $row['name'] ?? '',
                    'messages' => $messages,
                ];

                continue;
            }

            $entry = ['line' => $line, 'row' => $row, 'name' => $row['name']];

            if ($product) {
                $plan['update'][] = $entry + ['product' => $product];
            } else {
                $plan['create'][] = $entry;
            }
        }

        return $plan;
    }

    /**
     * Tidy the cells a real spreadsheet produces: "Yes" for a boolean, and
     * "1,250.00" for a number, which money(…, false) also writes on the way out.
     */
    private function normalise(array $row): array
    {
        foreach ($row as $header => $value) {
            $spec = self::COLUMNS[$header] ?? null;

            if (!$spec) {
                continue;
            }

            if (!empty($spec['boolean'])) {
                $row[$header] = match (strtolower(trim((string) $value))) {
                    '1', 'yes', 'y', 'true', 't' => '1',
                    '0', 'no', 'n', 'false', 'f' => '0',
                    default => trim((string) $value),   // left alone so validation can complain
                };

                continue;
            }

            if (Str::contains($spec['rules'], ['numeric', 'integer']) && $value !== '') {
                $clean = str_replace([' ', "\u{00A0}"], '', (string) $value);

                if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $clean)) {
                    $clean = str_replace(',', '', $clean);        // 1,250.00 -> 1250.00
                } elseif (preg_match('/^-?\d+,\d{1,2}$/', $clean)) {
                    $clean = str_replace(',', '.', $clean);       // 1250,00 -> 1250.00
                }

                $row[$header] = $clean;
            }
        }

        return $row;
    }

    /** @return array<int, string> the problems with one row, in plain words */
    private function validateRow(array $row): array
    {
        $rules = [];

        foreach ($this->headers() as $header) {
            // A column the file does not have is simply not validated; the
            // required ones are already guaranteed by the header check.
            if (array_key_exists($header, $row)) {
                $rules[$header] = self::COLUMNS[$header]['rules'];
            }
        }

        // Blank optional cells arrive as '' from a CSV, which is "not given".
        $data = array_map(fn($value) => $value === '' ? null : $value, $row);

        return Validator::make($data, $rules, [], $this->attributeNames())->errors()->all();
    }

    /** Error messages say "cost price", not "cost_price". */
    private function attributeNames(): array
    {
        return array_combine(
            array_keys(self::COLUMNS),
            array_map(fn($header) => str_replace('_', ' ', $header), array_keys(self::COLUMNS))
        );
    }

    /**
     * Write the plan.
     *
     * One transaction: a half-imported catalogue is worse than none, because
     * nobody can tell which half. Rejected rows are skipped (the person has
     * already seen and accepted them in the preview).
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    private function import(array $plan): array
    {
        return DB::transaction(function () use ($plan) {
            $created = 0;
            $updated = 0;

            foreach ($plan['create'] as $entry) {
                $attributes = $this->attributesFor($entry['row']);

                // products.sku is NOT NULL and unique, and a spreadsheet often
                // has no SKU at all - generate one, as the product form does.
                $attributes['sku'] = $attributes['sku'] ?? 'SKU-' . Str::upper(Str::random(8));

                $product = Product::create($attributes);
                $created++;

                // Opening stock is a real movement and gets a log, exactly as
                // ProductController does when a product is added by hand.
                if ((int) $product->stock_quantity > 0) {
                    InventoryLog::create([
                        'product_id' => $product->id,
                        'user_id' => userId(),
                        'type' => 'IN',
                        'quantity' => (int) $product->stock_quantity,
                        'reason' => 'Opening stock (CSV import)',
                    ]);
                }
            }

            foreach ($plan['update'] as $entry) {
                $attributes = $this->attributesFor($entry['row']);

                /*
                | The import never changes the stock of an existing product.
                | A stock figure needs a reason and a trail, which is what
                | stock-takes and adjustments are for; a spreadsheet upload is
                | not the place to quietly move stock on a hundred items.
                */
                unset($attributes['stock_quantity']);

                // A blank cell means "I did not fill this in", not "erase it".
                $attributes = array_filter($attributes, fn($value) => $value !== null);

                $entry['product']->update($attributes);

                // The shop has just listed it in its catalogue again, so an
                // archived item comes back rather than staying hidden.
                if ($entry['product']->trashed()) {
                    $entry['product']->restore();
                }

                $updated++;
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'skipped' => count($plan['errors']),
            ];
        });
    }

    /**
     * Turn a validated row into product attributes, creating the category and
     * supplier it names if they do not exist yet.
     *
     * Creating them is deliberate: a shop importing its first spreadsheet has
     * forty categories in it and should not have to type them all in first.
     * Names are matched case-insensitively so "Drinks" and "drinks" stay one
     * category.
     */
    private function attributesFor(array $row): array
    {
        $attributes = [];

        foreach ($row as $header => $value) {
            $spec = self::COLUMNS[$header] ?? null;

            if (!$spec || !in_array($header, $this->headers(), true)) {
                continue;
            }

            $value = $value === '' ? null : $value;

            if (isset($spec['lookup'])) {
                $attributes[$spec['column']] = $value === null
                    ? null
                    : $this->resolveByName($spec['lookup'], $value, $spec['creates'] ?? []);

                continue;
            }

            if (!empty($spec['boolean'])) {
                $attributes[$spec['column']] = $value === null ? null : (bool) $value;

                continue;
            }

            $attributes[$spec['column']] = $value;
        }

        // Columns that are NOT NULL in the database must not be sent as null
        // just because the cell was blank; let the column default stand.
        foreach (['unit', 'pack_size', 'stock_quantity', 'alert_quantity', 'reorder_quantity', 'is_service', 'track_stock', 'is_active', 'category_id'] as $column) {
            if (array_key_exists($column, $attributes) && $attributes[$column] === null) {
                unset($attributes[$column]);
            }
        }

        return $attributes;
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  array<string, mixed>  $creates  extra columns needed when it has to be created
     */
    private function resolveByName(string $model, string $name, array $creates = []): int
    {
        $name = trim($name);

        $existing = $model::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();

        return $existing ? $existing->id : $model::create(['name' => $name] + $creates)->id;
    }
}
