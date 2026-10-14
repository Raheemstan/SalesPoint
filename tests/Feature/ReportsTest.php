<?php

use App\Models\Expense;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    $this->user = User::factory()->manager()->create();
    $this->actingAs($this->user);
});

function makeSale(string $when, array $attrs = []): Sale
{
    $sale = Sale::create(array_merge([
        'invoice_number' => 'INV-' . uniqid(),
        'user_id' => test()->user->id,
        'total_amount' => 100,
        'tax_amount' => 10,
        'discount_amount' => 5,
        'grand_total' => 105,
        'payment_method' => 'cash',
        'paid_amount' => 200,
        'change_due' => 95,
        'sale_date' => $when,
    ], $attrs));

    $sale->forceFill(['created_at' => $when, 'updated_at' => $when])->save();

    return $sale;
}

test('the sales report includes sales made on the END date (regression: the last day was dropped)', function () {
    makeSale('2026-10-10 09:00:00', ['invoice_number' => 'FIRST-DAY']);
    makeSale('2026-10-12 15:30:00', ['invoice_number' => 'LAST-DAY']);
    makeSale('2026-10-13 00:30:00', ['invoice_number' => 'OUTSIDE']);

    $this->get(route('reports.sales', ['start_date' => '2026-10-10', 'end_date' => '2026-10-12']))
        ->assertOk()
        ->assertSee('FIRST-DAY')
        ->assertSee('LAST-DAY')
        ->assertDontSee('OUTSIDE');
});

test('tax and discount totals are real numbers (regression: it summed non-existent columns)', function () {
    makeSale('2026-10-11 10:00:00');
    makeSale('2026-10-11 11:00:00', ['tax_amount' => 20, 'discount_amount' => 15]);

    $response = $this->get(route('reports.sales', ['start_date' => '2026-10-11', 'end_date' => '2026-10-11']))->assertOk();

    expect($response->viewData('summary'))->toMatchArray([
        'total_sales' => 210,
        'total_tax' => 30,
        'total_discount' => 20,
        'total_transactions' => 2,
    ]);
});

test('the payment-method filter and date validation work', function () {
    makeSale('2026-10-11 10:00:00', ['invoice_number' => 'BY-CASH']);
    makeSale('2026-10-11 10:05:00', ['invoice_number' => 'BY-POS', 'payment_method' => 'pos']);

    $this->get(route('reports.sales', ['start_date' => '2026-10-11', 'end_date' => '2026-10-11', 'payment_method' => 'pos']))
        ->assertSee('BY-POS')->assertDontSee('BY-CASH');

    $this->get(route('reports.sales', ['start_date' => '2026-10-12', 'end_date' => '2026-10-01']))->assertSessionHasErrors('end_date');
    $this->get(route('reports.sales', ['payment_method' => 'bitcoin']))->assertSessionHasErrors('payment_method');
});

test('the PDF export works (regression: the template needed $summary which was never passed)', function () {
    makeSale(now()->toDateTimeString());

    $response = $this->get(route('reports.sales.report.pdf'))->assertOk();

    expect($response->headers->get('content-type'))->toContain('pdf');
});

test('the CSV export carries real tax/discount values and the invoice number', function () {
    makeSale('2026-10-11 10:00:00', ['invoice_number' => 'CSV-1', 'tax_amount' => 12.5, 'discount_amount' => 3]);

    $csv = $this->get(route('reports.sales.report.csv', ['start_date' => '2026-10-11', 'end_date' => '2026-10-11']))
        ->assertOk()->streamedContent();

    // parse instead of string-matching: MySQL returns "12.50", SQLite returns 12.5
    $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
    expect($rows[0])->toBe(['Date', 'Invoice', 'Items', 'Total', 'Tax', 'Discount', 'Payment Method', 'Status', 'Refunded']);

    expect($rows[1][1])->toBe('CSV-1')
        ->and((float) $rows[1][4])->toBe(12.5)
        ->and((float) $rows[1][5])->toBe(3.0);
});

test('"this month" does not include the same month of earlier years (regression: whereMonth without year)', function () {
    makeSale(now()->toDateTimeString(), ['grand_total' => 100]);
    makeSale(now()->subYear()->toDateTimeString(), ['grand_total' => 9000]);

    $data = $this->get(route('reports.daily'))->assertOk()->viewData('data');

    expect((float) $data['sales_this_month'])->toBe(100.0)
        ->and((float) $data['sales_today'])->toBe(100.0)
        ->and($data['transactions_today'])->toBe(1);
});

test('the inventory movement report renders (regression: it pointed at a view that did not exist) and lists real movements', function () {
    $p = Product::factory()->create(['name' => 'Movement Widget']);
    InventoryLog::create(['product_id' => $p->id, 'user_id' => $this->user->id, 'type' => 'IN', 'quantity' => 12, 'reason' => 'Purchase PO-9']);
    InventoryLog::create(['product_id' => $p->id, 'user_id' => $this->user->id, 'type' => 'OUT', 'quantity' => 3, 'reason' => 'Sale INV-1']);

    $this->get(route('reports.movement'))
        ->assertOk()
        ->assertSee('Movement Widget')
        ->assertSee('+12')
        ->assertSee('-3')
        ->assertSee('Purchase PO-9');
});

test('profit & loss sums by day, includes the end date, and expenses on the first day', function () {
    $p = Product::factory()->create(['cost_price' => 40]);

    // revenue = grand_total - tax; cost comes from the items' recorded cost
    $s1 = makeSale('2026-10-01 08:00:00', ['grand_total' => 1100, 'tax_amount' => 100]);
    $s1->items()->create(['product_id' => $p->id, 'quantity' => 10, 'price' => 100, 'cost_price' => 40, 'total' => 1000, 'paid_total' => 1100]);
    $s2 = makeSale('2026-10-03 23:00:00', ['grand_total' => 500, 'tax_amount' => 0]);
    $s2->items()->create(['product_id' => $p->id, 'quantity' => 5, 'price' => 100, 'cost_price' => 40, 'total' => 500, 'paid_total' => 500]);

    $supplier = Supplier::create(['name' => 'S', 'phone' => '1', 'address' => 'x']);
    Purchase::create(['invoice_number' => 'P1', 'supplier_id' => $supplier->id, 'user_id' => $this->user->id, 'total_amount' => 9999, 'purchase_date' => '2026-10-02 10:00:00']);
    Expense::create(['name' => 'Rent', 'amount' => 200, 'expense_date' => '2026-10-01', 'user_id' => $this->user->id]);

    $view = $this->get(route('reports.profit_loss', ['from' => '2026-10-01', 'to' => '2026-10-03']))->assertOk();

    expect((float) $view->viewData('sales'))->toBe(1500.0)       // 1000 + 500, tax excluded
        ->and((float) $view->viewData('cogs'))->toBe(600.0)       // 15 units x 40
        ->and((float) $view->viewData('grossProfit'))->toBe(900.0)
        ->and((float) $view->viewData('expenses'))->toBe(200.0)
        ->and((float) $view->viewData('netProfit'))->toBe(700.0)
        ->and((float) $view->viewData('taxCollected'))->toBe(100.0)
        // buying stock is cash out, not an expense: it must not reduce profit
        ->and((float) $view->viewData('purchases'))->toBe(9999.0)
        ->and($view->viewData('labels'))->toBe(['2026-10-01', '2026-10-02', '2026-10-03'])
        ->and($view->viewData('salesData'))->toBe([1000.0, 0.0, 500.0])
        ->and($view->viewData('netProfitData'))->toBe([1000.0 - 400.0 - 200.0, 0.0, 500.0 - 200.0]);
});

test('profit & loss refuses an absurdly long range instead of looping for ages', function () {
    $this->get(route('reports.profit_loss', ['from' => '2000-01-01', 'to' => '2026-01-01']))->assertStatus(422);
});

test('the profit & loss report runs a fixed number of queries regardless of range length', function () {
    \DB::enableQueryLog();
    $this->get(route('reports.profit_loss', ['from' => '2026-01-01', 'to' => '2026-12-31']))->assertOk();
    $count = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    // used to be 3 queries per day = 1000+ for a year
    expect($count)->toBeLessThan(30);
});

test('reports still render history for products that were later deleted', function () {
    $p = Product::factory()->create(['name' => 'Gone Product', 'stock_quantity' => 5]);
    $sale = makeSale('2026-10-11 10:00:00');
    $sale->items()->create(['product_id' => $p->id, 'quantity' => 1, 'price' => 100, 'total' => 100]);
    $p->delete();

    $this->get(route('reports.sales', ['start_date' => '2026-10-11', 'end_date' => '2026-10-11']))
        ->assertOk()->assertSee('Gone Product');
});

/*
|--------------------------------------------------------------------------
| The presentation added on top of the figures above
|--------------------------------------------------------------------------
*/

test('the sales report carries a day-by-day series alongside the table, with the empty days filled in', function () {
    makeSale('2026-10-10 09:00:00', ['grand_total' => 300]);
    // Nothing at all on the 11th: the series must still have a point for it, or
    // a chart would draw a straight line across a closed day.
    makeSale('2026-10-12 15:30:00', ['grand_total' => 200]);

    $trend = $this->get(route('reports.sales', ['start_date' => '2026-10-10', 'end_date' => '2026-10-12']))
        ->assertOk()->viewData('trend');

    expect($trend['labels'])->toBe(['2026-10-10', '2026-10-11', '2026-10-12'])
        ->and($trend['values'])->toBe([300.0, 0.0, 200.0]);
});

test('the day-by-day series is net of refunds, which land on the day they were paid out', function () {
    $sale = makeSale('2026-10-10 09:00:00', ['grand_total' => 1000]);

    // Refunded two days later: it comes off the 12th, not off the sale's own day.
    $refund = Refund::create([
        'sale_id' => $sale->id, 'user_id' => $this->user->id, 'total_amount' => 250,
        'tax_amount' => 0, 'method' => 'cash', 'reason' => 'Returned',
    ]);
    $refund->forceFill(['created_at' => '2026-10-12 11:00:00'])->save();

    $trend = $this->get(route('reports.sales', ['start_date' => '2026-10-10', 'end_date' => '2026-10-12']))
        ->assertOk()->viewData('trend');

    expect($trend['values'])->toBe([1000.0, 0.0, -250.0]);
});

test('the dashboard is a dashboard now, not a redirect to the daily summary', function () {
    makeSale(now()->toDateTimeString(), ['grand_total' => 400]);

    $response = $this->get(route('dashboard'))->assertOk();

    // The stub used to render reports.sales_daily and pass only `data`.
    expect($response->original->name())->toBe('dashboard');

    foreach (['today', 'profit', 'trend', 'methods', 'topQuantity', 'owed'] as $key) {
        expect(array_keys($response->original->getData()))->toContain($key);
    }
});

test('the daily summary agrees with the sales report on the same range', function () {
    makeSale('2026-10-10 09:00:00', ['grand_total' => 700]);
    makeSale('2026-10-11 09:00:00', ['grand_total' => 300]);

    $range = ['start_date' => '2026-10-10', 'end_date' => '2026-10-11'];

    $salesSummary = $this->get(route('reports.sales', $range))->assertOk()->viewData('summary');
    $daily = $this->get(route('reports.daily', $range))->assertOk();

    expect((float) $daily->viewData('summary')['takings'])->toBe((float) $salesSummary['net_sales'])
        ->and(array_sum($daily->viewData('trend')['values']))->toBe((float) $salesSummary['net_sales']);
});

test('a report range remembers itself, so coming back shows what you were looking at', function () {
    makeSale('2026-10-10 09:00:00', ['invoice_number' => 'REMEMBERED']);
    makeSale(now()->toDateTimeString(), ['invoice_number' => 'TODAYS-SALE']);

    $this->get(route('reports.sales', ['start_date' => '2026-10-10', 'end_date' => '2026-10-10']))
        ->assertOk()->assertSee('REMEMBERED');

    // No query string at all: the last range is still in force.
    $this->get(route('reports.sales'))->assertOk()
        ->assertSee('REMEMBERED')
        ->assertDontSee('TODAYS-SALE');
});

test('the profit & loss report exports a CSV of its day-by-day figures', function () {
    $p = Product::factory()->create(['cost_price' => 40]);

    $sale = makeSale('2026-10-01 08:00:00', ['grand_total' => 1100, 'tax_amount' => 100]);
    $sale->items()->create(['product_id' => $p->id, 'quantity' => 10, 'price' => 100, 'cost_price' => 40, 'total' => 1000, 'paid_total' => 1100]);
    Expense::create(['name' => 'Rent', 'amount' => 200, 'expense_date' => '2026-10-01', 'user_id' => $this->user->id]);

    $response = $this->get(route('reports.profit_loss', ['from' => '2026-10-01', 'to' => '2026-10-02', 'export' => 'csv']))
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv');

    $rows = array_map('str_getcsv', array_filter(explode("\n", trim($response->streamedContent()))));

    expect($rows[0][0])->toBe('Date')
        ->and($rows)->toHaveCount(3)                 // header + two days
        ->and($rows[1][0])->toBe('2026-10-01')
        ->and((float) $rows[1][1])->toBe(1000.0)     // revenue, tax excluded
        ->and((float) $rows[1][2])->toBe(400.0)      // cost of goods sold
        ->and((float) $rows[1][3])->toBe(600.0)      // gross profit
        ->and((float) $rows[1][5])->toBe(400.0);     // net profit after 200 of rent
});
