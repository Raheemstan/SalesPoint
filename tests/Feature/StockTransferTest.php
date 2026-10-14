<?php

/**
 * Stock transfers between branches.
 *
 * The rule under test, over and over: stock in transit is counted in neither
 * place. It leaves the source on dispatch, arrives at the destination on
 * receipt, and for the time in between it is on no shelf at all - which is
 * exactly what stops one shop selling what the other already has on a van.
 */

use App\Models\AuditLog;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StockTransferService;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/../BranchHelpers.php';

/**
 * Two shops, a product on the source's shelf, and a manager who works at both.
 *
 * @return array{0: \App\Models\Branch, 1: \App\Models\Branch, 2: Product, 3: User}
 */
function transferScene(int $atSource = 20, int $atDestination = 0): array
{
    $from = defaultBranch();
    $to = makeBranch('LEK');
    enableBranches();

    $product = atBranch($from, fn () => Product::factory()->create([
        'name' => 'Rice', 'stock_quantity' => $atSource, 'cost_price' => 25,
    ]));

    if ($atDestination > 0) {
        atBranch($to, fn () => $product->increment('stock_quantity', $atDestination));
    }

    return [$from, $to, $product->refresh(), staffAt($from, 'manager')];
}

/*
|--------------------------------------------------------------------------
| Dispatch: the stock leaves
|--------------------------------------------------------------------------
*/

test('dispatching removes stock from the source and adds it nowhere', function () {
    [$from, $to, $product, $manager] = transferScene(20);

    $transfer = atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 8], $manager));

    $product->refresh();

    expect($transfer->status)->toBe(StockTransfer::STATUS_IN_TRANSIT)
        ->and(shelf($from, $product))->toBe(12)
        // The whole point: not here yet.
        ->and(shelf($to, $product))->toBe(0)
        /*
         * And the group total dips too, by the same amount. Goods on a van
         * belong to no shelf, so counting them in the group figure would be
         * counting them somewhere.
         */
        ->and((int) $product->stock_quantity)->toBe(12);
});

test('over-sending is refused, and nothing moves', function () {
    [$from, $to, $product, $manager] = transferScene(5);

    expect(fn () => atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 6], $manager)))
        ->toThrow(DomainException::class, 'only has 5');

    $product->refresh();

    // Transactional: a refusal leaves no transfer, no item and no movement.
    expect(StockTransfer::count())->toBe(0)
        ->and(shelf($from, $product))->toBe(5)
        ->and((int) $product->stock_quantity)->toBe(5);
});

test('a transfer with nothing on it is refused', function () {
    [$from, $to, $product, $manager] = transferScene();

    expect(fn () => atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 0], $manager)))
        ->toThrow(DomainException::class, 'at least one');

    expect(StockTransfer::count())->toBe(0);
});

test('stock cannot be sent to the branch it is already at', function () {
    [$from, , $product, $manager] = transferScene();

    expect(fn () => atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $from, [$product->id => 1], $manager)))
        ->toThrow(DomainException::class, 'different branch');
});

test('stock cannot be sent from a branch you are not standing in', function () {
    [$from, $to, $product, $manager] = transferScene();

    /*
     * Sending from somewhere you are not is how stock gets taken off a shelf
     * nobody has looked at. The message names the branch to switch to.
     */
    expect(fn () => atBranch($to, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 1], $manager)))
        ->toThrow(DomainException::class, 'Switch to ' . $from->name);

    expect(shelf($from, $product))->toBe(20);
});

test('a service cannot be put on a van', function () {
    [$from, $to, , $manager] = transferScene();

    $haircut = atBranch($from, fn () => Product::factory()->create([
        'name' => 'Haircut', 'is_service' => true, 'track_stock' => false, 'stock_quantity' => 0,
    ]));

    expect(fn () => atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$haircut->id => 1], $manager)))
        ->toThrow(DomainException::class, 'no stock level');
});

/*
|--------------------------------------------------------------------------
| Receipt: the stock arrives
|--------------------------------------------------------------------------
*/

test('receiving adds the stock at the destination and only then', function () {
    [$from, $to, $product, $manager] = transferScene(20, 3);

    $transfer = atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 8], $manager));

    // Still nowhere while it is on its way.
    expect(shelf($from, $product))->toBe(12)
        ->and(shelf($to, $product))->toBe(3)
        ->and((int) $product->fresh()->stock_quantity)->toBe(15);

    atBranch($to, fn () => app(StockTransferService::class)->receive($transfer, $manager));

    $product->refresh();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_RECEIVED)
        ->and(shelf($from, $product))->toBe(12)
        // Landed on the destination's shelf, on top of what was already there.
        ->and(shelf($to, $product))->toBe(11)
        // And back in the group total, where it belongs again.
        ->and((int) $product->stock_quantity)->toBe(23)
        ->and(shelf($from, $product) + shelf($to, $product))->toBe(23);
});

test('receiving twice is refused', function () {
    [$from, $to, $product, $manager] = transferScene(20);

    $transfer = atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 8], $manager));

    atBranch($to, fn () => app(StockTransferService::class)->receive($transfer, $manager));

    // The second attempt is refused under a lock on the transfer's own row, so
    // two people tapping Receive at the same moment cannot both get through.
    expect(fn () => atBranch($to, fn () => app(StockTransferService::class)
        ->receive($transfer->fresh(), $manager)))
        ->toThrow(DomainException::class, 'already received');

    // The stock arrived once, not twice.
    expect(shelf($to, $product))->toBe(8)
        ->and((int) $product->fresh()->stock_quantity)->toBe(20);
});

test('a transfer can only be received at its destination', function () {
    [$from, $to, $product, $manager] = transferScene(20);

    $transfer = atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 8], $manager));

    // Nobody at the source has looked in the box at the other end.
    expect(fn () => atBranch($from, fn () => app(StockTransferService::class)
        ->receive($transfer, $manager)))
        ->toThrow(DomainException::class, 'Switch to ' . $to->name);

    expect(shelf($to, $product))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Recall
|--------------------------------------------------------------------------
*/

test('cancelling puts the stock back at the source', function () {
    [$from, $to, $product, $manager] = transferScene(20);

    $transfer = atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 8], $manager));

    atBranch($from, fn () => app(StockTransferService::class)->cancel($transfer, $manager, 'Van broke down'));

    $product->refresh();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_CANCELLED)
        ->and(shelf($from, $product))->toBe(20)
        ->and(shelf($to, $product))->toBe(0)
        ->and((int) $product->stock_quantity)->toBe(20);
});

test('a received transfer cannot be cancelled', function () {
    [$from, $to, $product, $manager] = transferScene(20);

    $transfer = atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 8], $manager));

    atBranch($to, fn () => app(StockTransferService::class)->receive($transfer, $manager));

    expect(fn () => atBranch($from, fn () => app(StockTransferService::class)
        ->cancel($transfer->fresh(), $manager)))
        ->toThrow(DomainException::class, 'has been received');

    expect(shelf($to, $product))->toBe(8);
});

test('a cancelled transfer cannot then be received', function () {
    [$from, $to, $product, $manager] = transferScene(20);

    $transfer = atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 8], $manager));

    atBranch($from, fn () => app(StockTransferService::class)->cancel($transfer, $manager));

    expect(fn () => atBranch($to, fn () => app(StockTransferService::class)
        ->receive($transfer->fresh(), $manager)))
        ->toThrow(DomainException::class, 'cancelled');

    expect(shelf($to, $product))->toBe(0)
        ->and(shelf($from, $product))->toBe(20);
});

/*
|--------------------------------------------------------------------------
| The trails every movement has to leave
|--------------------------------------------------------------------------
*/

test('a transfer writes inventory logs at both ends, each stamped with its branch', function () {
    [$from, $to, $product, $manager] = transferScene(20);

    $transfer = atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 8], $manager));

    atBranch($to, fn () => app(StockTransferService::class)->receive($transfer, $manager));

    // Group-wide read, because the point is that the two rows went to two
    // different branches.
    $logs = InventoryLog::withoutBranchScope()
        ->where('product_id', $product->id)
        ->where('reason', 'like', 'Transfer ' . $transfer->reference . '%')
        ->get();

    expect($logs)->toHaveCount(2);

    $out = $logs->firstWhere('type', 'OUT');
    $in = $logs->firstWhere('type', 'IN');

    expect($out->branch_id)->toBe($from->id)
        ->and($out->quantity)->toBe(8)
        ->and($in->branch_id)->toBe($to->id)
        ->and($in->quantity)->toBe(8);
});

test('a transfer is audited at every step', function () {
    [$from, $to, $product, $manager] = transferScene(20);

    $this->actingAs($manager);

    $transfer = atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 8], $manager));

    atBranch($to, fn () => app(StockTransferService::class)->receive($transfer, $manager));

    $entries = AuditLog::where('table_name', 'stock_transfers')->pluck('action');

    expect($entries)->toHaveCount(2)
        ->and($entries->first())->toContain('Dispatched stock transfer')
        ->and($entries->last())->toContain('Received stock transfer');
});

test('the item line snapshots what the goods cost when they moved', function () {
    [$from, $to, $product, $manager] = transferScene(20);

    $transfer = atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 4], $manager));

    // Re-priced after the van left.
    $product->forceFill(['cost_price' => 999])->save();

    $item = $transfer->items()->firstOrFail();

    expect((float) $item->cost_price)->toEqual(25.0)
        ->and($item->value())->toEqual(100.0)
        ->and($transfer->fresh()->totalValue())->toEqual(100.0);
});

test('in-transit units are reported, since they are on no shelf', function () {
    [$from, $to, $product, $manager] = transferScene(20);

    atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 6], $manager));

    $transfers = app(StockTransferService::class);

    expect($transfers->inTransit($to, true)->sum())->toBe(6)
        ->and($transfers->inTransit($from, false)->sum())->toBe(6)
        ->and($transfers->inTransit($from, true)->sum())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The screens
|--------------------------------------------------------------------------
*/

test('the transfer screens are closed to cashiers', function () {
    [$from, , , ] = transferScene();

    $this->actingAs(staffAt($from, 'cashier'));

    $this->get(route('transfers.index'))->assertForbidden();
    $this->get(route('transfers.create'))->assertForbidden();
    $this->post(route('transfers.store'), [])->assertForbidden();
});

test('a manager dispatches and the destination receives, through the screens', function () {
    [$from, $to, $product, $manager] = transferScene(20);

    // Raised at the source.
    $this->actingAs($manager)
        ->post(route('transfers.store'), [
            'to_branch_id' => $to->id,
            'quantities' => [$product->id => 7],
            'note' => 'With the morning van',
        ])->assertRedirect();

    $transfer = StockTransfer::firstOrFail();

    expect(shelf($from, $product))->toBe(13)
        ->and(shelf($to, $product))->toBe(0);

    // The source cannot receive its own transfer, however hard it tries.
    $this->post(route('transfers.receive', $transfer))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(shelf($to, $product))->toBe(0);

    // Switch to the destination and confirm it arrived.
    $this->post(route('branches.switch'), ['branch_id' => $to->id])->assertRedirect();
    $this->post(route('transfers.receive', $transfer))->assertRedirect();

    expect($transfer->fresh()->status)->toBe(StockTransfer::STATUS_RECEIVED)
        ->and(shelf($to, $product))->toBe(7)
        ->and(shelf($from, $product))->toBe(13);
});

test('the list shows a branch only its own transfers', function () {
    [$from, $to, $product, $manager] = transferScene(20);
    $abuja = makeBranch('ABJ');

    $mine = atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 2], $manager));

    // One between two other shops: no business of the branch we are standing in.
    $theirs = StockTransfer::factory()->create([
        'from_branch_id' => $to->id,
        'to_branch_id' => $abuja->id,
    ]);

    $html = $this->actingAs($manager)->get(route('transfers.index'))->assertOk()->getContent();

    expect($html)->toContain($mine->reference)
        ->and($html)->not->toContain($theirs->reference);
});

test('an admin can ask for the group-wide transfer list', function () {
    [$from, $to, $product, $manager] = transferScene(20);
    $abuja = makeBranch('ABJ');

    atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 2], $manager));

    $theirs = StockTransfer::factory()->create([
        'from_branch_id' => $to->id,
        'to_branch_id' => $abuja->id,
    ]);

    // Looking at the group rather than at a shop. A manager gets no such view.
    $this->actingAs(User::factory()->admin()->create(['branch_id' => $from->id]))
        ->get(route('transfers.index', ['scope' => 'all']))
        ->assertOk()
        ->assertSee($theirs->reference);
});

test('the transfer page offers Receive only at the destination', function () {
    [$from, $to, $product, $manager] = transferScene(20);

    $transfer = atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 3], $manager));

    // Standing at the source: Recall, but no Receive.
    $this->actingAs($manager)->get(route('transfers.show', $transfer))->assertOk()
        ->assertSee('Recall')
        ->assertDontSee('Receive in full');

    $this->post(route('branches.switch'), ['branch_id' => $to->id])->assertRedirect();

    // Standing at the destination: the other way round.
    $this->get(route('transfers.show', $transfer))->assertOk()
        ->assertSee('Receive in full')
        ->assertDontSee('Recall');
});

test('a manager at neither end cannot read the transfer', function () {
    [$from, $to, $product, $manager] = transferScene(20);
    $abuja = makeBranch('ABJ');

    $transfer = atBranch($from, fn () => app(StockTransferService::class)
        ->dispatch($from, $to, [$product->id => 3], $manager));

    // Home branch is a third shop, and assigned to no end of this transfer.
    $outsider = staffAt($abuja, 'manager');

    expect(Gate::forUser($outsider)->allows('view', $transfer))->toBeFalse()
        // An admin is looking at the group, so they may.
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('view', $transfer))->toBeTrue();
});
