<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\ShiftService;

/**
 * The open-shift chip in the app header.
 *
 * It is included on every page, so a mistake here breaks the whole app rather
 * than one screen - which is why it is tested on its own.
 */
beforeEach(function () {
    $this->cashier = User::factory()->cashier()->create(['name' => 'Ada Till']);
});

test('a shop that does not require shifts sees no chip at all', function () {
    Setting::set('require_open_shift', '0');

    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertOk()
        ->assertDontSee('No shift open')
        ->assertDontSee('expected');
});

test('a shop that requires a shift is told when none is open, and where to open one', function () {
    Setting::set('require_open_shift', '1');

    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertOk()
        ->assertSee('No shift open')
        ->assertSee(route('shifts.index'), false);
});

test('an open drawer shows what it should be holding, in the shop currency', function () {
    Setting::set('currency_symbol', '$');
    app(ShiftService::class)->open($this->cashier, 2500, null);

    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertOk()
        // The opening float alone, since nothing has been sold yet, formatted
        // with the shop's symbol and decimal places.
        ->assertSee('$2,500.00 expected')
        ->assertDontSee('No shift open');
});

test('the chip shows the viewer their own drawer, not a colleague who is also on shift', function () {
    $other = User::factory()->cashier()->create();
    app(ShiftService::class)->open($other, 9999, null);

    Setting::set('require_open_shift', '1');

    // Ada has no shift of her own, so she must be prompted rather than shown
    // somebody else's float.
    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertOk()
        ->assertSee('No shift open')
        ->assertDontSee('9,999');
});
