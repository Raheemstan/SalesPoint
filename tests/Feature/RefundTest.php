<?php

use App\Models\Expense;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;

beforeEach(function () {
    $this->cashier = User::factory()->cashier()->create();
    $this->manager = User::factory()->manager()->create();
});

/** Ring up a real sale as the cashier through the real checkout, then switch to the manager. */
function ringUp(array $lines, ?array $orderDiscount = null, array $payment = []): Sale
{
    test()->actingAs(test()->cashier);
    checkout(cartOf($lines), $payment, $orderDiscount)->assertSessionHasNoErrors();
    test()->actingAs(test()->manager);

    return Sale::latest('id')->first();
}

function refundPost(Sale $sale, array $items, array $extra = [])
{
    return test()->post(route('sales.refund', $sale), array_merge([
        'items' => $items,
        'reason' => 'Customer changed their mind',
        'method' => 'cash',
    ], $extra));
}

test('a partial refund returns the money for those units and puts them back in stock', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'cost_price' => 60, 'stock_quantity' => 10]);
    $sale = ringUp([[$p, 3]]);
    expect($p->fresh()->stock_quantity)->toBe(7);

    $item = $sale->items->first();
    refundPost($sale, [$item->id => ['quantity' => 1, 'restock' => 1]])->assertSessionHas('success');

    $sale->refresh();
    expect($p->fresh()->stock_quantity)->toBe(8)
        ->and($sale->status)->toBe('partially_refunded')
        ->and((float) $sale->refunded_amount)->toBe(100.0)
        ->and($item->fresh()->refunded_quantity)->toBe(1);

    $refund = Refund::first();
    expect((float) $refund->total_amount)->toBe(100.0)->and($refund->method)->toBe('cash')->and($refund->user_id)->toBe($this->manager->id);
    expect(InventoryLog::where('type', 'IN')->where('reason', 'Refund ' . $sale->invoice_number)->value('quantity'))->toBe(1);
});

test('refunding everything in pieces returns exactly what was paid, to the penny, discounts and tax included', function () {
    Setting::set('tax_rate', '7.5');
    $a = Product::factory()->create(['sale_price' => 33.33, 'stock_quantity' => 20]);
    $b = Product::factory()->create(['sale_price' => 19.99, 'stock_quantity' => 20]);
    $sale = ringUp([[$a, 3], [$b, 7]], ['type' => 'fixed', 'value' => 7]);

    $grand = (float) $sale->grand_total;
    $ia = $sale->items->firstWhere('product_id', $a->id);
    $ib = $sale->items->firstWhere('product_id', $b->id);

    refundPost($sale, [$ia->id => ['quantity' => 1, 'restock' => 1], $ib->id => ['quantity' => 3, 'restock' => 1]]);
    refundPost($sale, [$ia->id => ['quantity' => 1, 'restock' => 1], $ib->id => ['quantity' => 2, 'restock' => 1]]);
    refundPost($sale, [$ia->id => ['quantity' => 1, 'restock' => 1], $ib->id => ['quantity' => 2, 'restock' => 1]]);

    $sale->refresh();
    expect($sale->status)->toBe('refunded')
        ->and((float) $sale->refunded_amount)->toBe($grand)
        ->and(round((float) Refund::sum('total_amount'), 2))->toBe($grand)
        ->and(round((float) $sale->items()->sum('refunded_amount'), 2))->toBe($grand)
        ->and($a->fresh()->stock_quantity)->toBe(20)->and($b->fresh()->stock_quantity)->toBe(20);

    // the tax part of the refunds also adds up to the tax that was charged
    expect(round((float) Refund::sum('tax_amount'), 2))->toBe((float) $sale->tax_amount);
});

test('a refund can never exceed what is left on the sale', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $sale = ringUp([[$p, 2]]);
    $item = $sale->items->first();

    refundPost($sale, [$item->id => ['quantity' => 3, 'restock' => 1]])->assertSessionHas('error');
    expect(Refund::count())->toBe(0)->and($p->fresh()->stock_quantity)->toBe(8);

    refundPost($sale, [$item->id => ['quantity' => 2, 'restock' => 1]])->assertSessionHas('success');
    refundPost($sale, [$item->id => ['quantity' => 1, 'restock' => 1]])->assertSessionHas('error');

    expect(Refund::count())->toBe(1)->and($p->fresh()->stock_quantity)->toBe(10);
});

test('selecting nothing, a reason of nothing or a bad method is refused', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $sale = ringUp([[$p, 2]]);
    $item = $sale->items->first();

    refundPost($sale, [$item->id => ['quantity' => 0]])->assertSessionHas('error', 'Select at least one item to refund.');
    refundPost($sale, [$item->id => ['quantity' => 1]], ['reason' => ''])->assertSessionHasErrors('reason');
    refundPost($sale, [$item->id => ['quantity' => 1]], ['method' => 'bitcoin'])->assertSessionHasErrors('method');
    $this->post(route('sales.refund', $sale), ['reason' => 'x y z', 'method' => 'cash'])->assertSessionHasErrors('items');

    expect(Refund::count())->toBe(0);
});

test('an item from a different sale cannot be smuggled into a refund', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 20]);
    $one = ringUp([[$p, 2]]);
    $two = ringUp([[$p, 2]]);

    $foreign = $two->items->first();
    refundPost($one, [$foreign->id => ['quantity' => 1, 'restock' => 1]])->assertSessionHas('error');

    expect(Refund::count())->toBe(0)->and($foreign->fresh()->refunded_quantity)->toBe(0);
});

test('damaged goods: money goes back but stock does not, and no stock movement is logged', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'cost_price' => 60, 'stock_quantity' => 10]);
    $sale = ringUp([[$p, 2]]);
    $item = $sale->items->first();

    refundPost($sale, [$item->id => ['quantity' => 1]]); // restock box left unticked

    expect($p->fresh()->stock_quantity)->toBe(8)
        ->and(InventoryLog::where('reason', 'like', 'Refund%')->count())->toBe(0)
        ->and(RefundItem::first()->restocked)->toBeFalse();
});

test('voiding a sale returns every item to stock, marks it voided and records who and why', function () {
    $a = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $b = Product::factory()->create(['sale_price' => 40, 'stock_quantity' => 10]);
    $sale = ringUp([[$a, 2], [$b, 3]]);

    $this->post(route('sales.void', $sale), ['reason' => 'Rang up on the wrong till', 'method' => 'cash'])
        ->assertSessionHas('success');

    $sale->refresh();
    expect($sale->status)->toBe('voided')
        ->and($sale->void_reason)->toBe('Rang up on the wrong till')
        ->and($sale->voided_by)->toBe($this->manager->id)
        ->and($sale->voided_at)->not->toBeNull()
        ->and((float) $sale->refunded_amount)->toBe((float) $sale->grand_total)
        ->and($a->fresh()->stock_quantity)->toBe(10)->and($b->fresh()->stock_quantity)->toBe(10)
        ->and(Refund::first()->is_void)->toBeTrue();
});

test('a voided or fully refunded sale cannot be refunded or voided again', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $sale = ringUp([[$p, 1]]);
    $item = $sale->items->first();

    $this->post(route('sales.void', $sale), ['reason' => 'mistake', 'method' => 'cash']);

    $this->post(route('sales.void', $sale), ['reason' => 'again', 'method' => 'cash'])->assertSessionHas('error');
    refundPost($sale, [$item->id => ['quantity' => 1, 'restock' => 1]])->assertSessionHas('error');

    expect(Refund::count())->toBe(1)->and($p->fresh()->stock_quantity)->toBe(10);
});

test('a sale that has been partly refunded cannot be voided, only refunded for the rest', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $sale = ringUp([[$p, 2]]);
    refundPost($sale, [$sale->items->first()->id => ['quantity' => 1, 'restock' => 1]]);

    $this->post(route('sales.void', $sale), ['reason' => 'too late', 'method' => 'cash'])->assertSessionHas('error');

    expect($sale->fresh()->status)->toBe('partially_refunded');
});

test('cashiers cannot refund or void, and do not even see the buttons', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $sale = ringUp([[$p, 2]]);
    $item = $sale->items->first();

    $this->actingAs($this->cashier);
    refundPost($sale, [$item->id => ['quantity' => 1, 'restock' => 1]])->assertForbidden();
    $this->post(route('sales.void', $sale), ['reason' => 'nope', 'method' => 'cash'])->assertForbidden();

    $this->get(route('sales.show', $sale))->assertOk()
        ->assertDontSee('Refund selected items')->assertDontSee('Void entire sale')->assertSee('Reprint receipt');

    expect(Refund::count())->toBe(0)->and($sale->fresh()->status)->toBe('completed');
});

test('managers see the refund and void controls on a refundable sale, and neither once it is closed', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $sale = ringUp([[$p, 1]]);

    $this->get(route('sales.show', $sale))->assertSee('Refund selected items')->assertSee('Void entire sale');

    $this->post(route('sales.void', $sale), ['reason' => 'mistake', 'method' => 'cash']);

    $this->get(route('sales.show', $sale))->assertDontSee('Refund selected items')->assertDontSee('Void entire sale')
        ->assertSee('mistake')->assertSee('Refund history');
});

test('an archived product is still restocked when its sale is refunded', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $sale = ringUp([[$p, 2]]);
    $p->delete();

    refundPost($sale, [$sale->items->first()->id => ['quantity' => 2, 'restock' => 1]])->assertSessionHas('success');

    expect(Product::withTrashed()->find($p->id)->stock_quantity)->toBe(10);
});

test('the movement report shows the refund as stock coming back in', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10, 'name' => 'Returned Widget']);
    $sale = ringUp([[$p, 2]]);
    refundPost($sale, [$sale->items->first()->id => ['quantity' => 1, 'restock' => 1]]);

    $this->get(route('reports.movement'))->assertSee('Returned Widget')->assertSee('Refund ' . $sale->invoice_number);
});

test('dashboard figures are net of refunds and voided sales are not transactions', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 50]);
    $kept = ringUp([[$p, 2]]);                       // 200
    $part = ringUp([[$p, 3]]);                       // 300, 100 refunded
    $void = ringUp([[$p, 1]]);                       // 100, voided

    refundPost($part, [$part->items->first()->id => ['quantity' => 1, 'restock' => 1]]);
    $this->post(route('sales.void', $void), ['reason' => 'oops', 'method' => 'cash']);

    $data = $this->get(route('reports.daily'))->viewData('data');

    expect((float) $data['sales_today'])->toBe(400.0)   // 600 sold - 100 - 100
        ->and((float) $data['sales_this_month'])->toBe(400.0)
        ->and($data['transactions_today'])->toBe(2);
});

test('the sales report nets off refunds and shows each sale status', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 50]);
    $sale = ringUp([[$p, 3]]);
    refundPost($sale, [$sale->items->first()->id => ['quantity' => 1, 'restock' => 1]]);

    $response = $this->get(route('reports.sales', ['start_date' => today()->toDateString(), 'end_date' => today()->toDateString()]))
        ->assertOk()->assertSee('Partially refunded');

    expect($response->viewData('summary'))->toMatchArray(['total_sales' => 300, 'total_refunds' => 100, 'net_sales' => 200]);
});

// ---- profit & loss: the point of recording cost at the time of sale ----

function pnl(): \Illuminate\Testing\TestResponse
{
    return test()->get(route('reports.profit_loss', ['from' => today()->toDateString(), 'to' => today()->toDateString()]));
}

test('profit is revenue minus the cost of what was sold, minus expenses', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'cost_price' => 60, 'stock_quantity' => 50]);
    ringUp([[$p, 2]]);
    Expense::create(['name' => 'Fuel', 'amount' => 10, 'expense_date' => today()->toDateString(), 'user_id' => $this->manager->id]);

    $v = pnl()->assertOk();

    expect((float) $v->viewData('sales'))->toBe(200.0)
        ->and((float) $v->viewData('cogs'))->toBe(120.0)
        ->and((float) $v->viewData('grossProfit'))->toBe(80.0)
        ->and((float) $v->viewData('netProfit'))->toBe(70.0);
});

test('changing a product cost afterwards does not rewrite past profit', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'cost_price' => 60, 'stock_quantity' => 50]);
    ringUp([[$p, 2]]);

    $p->update(['cost_price' => 95]);

    expect((float) pnl()->viewData('grossProfit'))->toBe(80.0);
});

test('sales tax is not revenue', function () {
    Setting::set('tax_rate', '10');
    $p = Product::factory()->create(['sale_price' => 100, 'cost_price' => 60, 'stock_quantity' => 50]);
    ringUp([[$p, 1]]); // customer pays 110

    $v = pnl();
    expect((float) $v->viewData('sales'))->toBe(100.0)->and((float) $v->viewData('taxCollected'))->toBe(10.0)
        ->and((float) $v->viewData('grossProfit'))->toBe(40.0);
});

test('discounts reduce revenue and therefore profit', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'cost_price' => 60, 'stock_quantity' => 50]);
    ringUp([[$p, 1]], ['type' => 'fixed', 'value' => 10]);

    expect((float) pnl()->viewData('grossProfit'))->toBe(30.0); // 90 - 60
});

test('a restocked refund reverses both the revenue and the cost', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'cost_price' => 60, 'stock_quantity' => 50]);
    $sale = ringUp([[$p, 2]]);
    refundPost($sale, [$sale->items->first()->id => ['quantity' => 1, 'restock' => 1]]);

    $v = pnl();
    expect((float) $v->viewData('sales'))->toBe(100.0)->and((float) $v->viewData('cogs'))->toBe(60.0)
        ->and((float) $v->viewData('grossProfit'))->toBe(40.0);
});

test('a refund of damaged goods gives the money back but keeps the cost as a loss', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'cost_price' => 60, 'stock_quantity' => 50]);
    $sale = ringUp([[$p, 2]]);
    refundPost($sale, [$sale->items->first()->id => ['quantity' => 1]]); // not restocked

    $v = pnl();
    expect((float) $v->viewData('sales'))->toBe(100.0)->and((float) $v->viewData('cogs'))->toBe(120.0)
        ->and((float) $v->viewData('grossProfit'))->toBe(-20.0);
});

test('a voided sale leaves no profit and no cost behind', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'cost_price' => 60, 'stock_quantity' => 50]);
    $sale = ringUp([[$p, 2]]);
    $this->post(route('sales.void', $sale), ['reason' => 'mistake', 'method' => 'cash']);

    $v = pnl();
    expect((float) $v->viewData('sales'))->toBe(0.0)->and((float) $v->viewData('cogs'))->toBe(0.0)
        ->and((float) $v->viewData('grossProfit'))->toBe(0.0);
});

test('two managers cannot refund the same units twice', function () {
    $p = Product::factory()->create(['sale_price' => 100, 'stock_quantity' => 10]);
    $sale = ringUp([[$p, 1]]);
    $item = $sale->items->first();

    refundPost($sale, [$item->id => ['quantity' => 1, 'restock' => 1]])->assertSessionHas('success');
    $this->actingAs(User::factory()->admin()->create());
    refundPost($sale, [$item->id => ['quantity' => 1, 'restock' => 1]])->assertSessionHas('error');

    expect(Refund::count())->toBe(1)->and($p->fresh()->stock_quantity)->toBe(10);
});
