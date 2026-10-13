<?php

use App\Mail\ReceiptMail;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\ReceiptShareService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

test('the settings page renders with the real settings array', function () {
    Setting::set('store_name', 'Mama Put Stores');

    $this->get(route('settings.index'))->assertOk()->assertSee('Mama Put Stores');
    expect(setting())->toBeArray()->toHaveKey('store_name');
});

test('saving settings with empty fields does not blow up (settings.value is NOT NULL)', function () {
    $this->post(route('settings.update'), [
        'store_name' => 'Shop',
        'store_motto' => '',
        'store_address' => null,
    ])->assertRedirect(route('settings.index'))->assertSessionHas('success');

    expect(setting('store_name'))->toBe('Shop')->and(setting('store_motto'))->toBe('');
});

test('only known settings are stored; unexpected request fields are ignored', function () {
    $this->post(route('settings.update'), ['store_name' => 'Shop', 'evil_key' => 'x', 'APP_KEY' => 'y']);

    expect(Setting::where('key', 'evil_key')->exists())->toBeFalse()
        ->and(Setting::where('key', 'APP_KEY')->exists())->toBeFalse();
});

test('unticking a checkbox really switches the setting off', function () {
    $this->post(route('settings.update'), ['enable_low_stock_warning' => '1', 'enable_audit_log' => '1']);
    expect(setting('enable_low_stock_warning'))->toBe('1');

    $this->post(route('settings.update'), ['store_name' => 'Shop']); // boxes absent = unticked
    expect(setting('enable_low_stock_warning'))->toBe('0')->and(setting('enable_audit_log'))->toBe('0');
});

test('form field names and stored keys line up (email is saved as store_email)', function () {
    $this->post(route('settings.update'), [
        'store_email' => 'shop@example.com',
        'low_stock_threshold' => 7,
        'auto_logout_time' => 15,
    ])->assertSessionHasNoErrors();

    expect(setting('store_email'))->toBe('shop@example.com')
        ->and(setting('low_stock_threshold'))->toBe('7')
        ->and(setting('auto_logout_time'))->toBe('15');

    $this->post(route('settings.update'), ['low_stock_threshold' => 0])->assertSessionHasErrors('low_stock_threshold');
});

test('the audit log toggle is honoured', function () {
    Setting::set('enable_audit_log', '0');
    AuditLog::record('Something', 'x', 1);
    expect(AuditLog::count())->toBe(0);

    Setting::set('enable_audit_log', '1');
    AuditLog::record('Something', 'x', 1);
    expect(AuditLog::count())->toBe(1);
});

test('any signed-in user can change the theme, nothing else', function () {
    $this->actingAs(User::factory()->cashier()->create());

    $this->postJson(route('settings.theme'), ['theme' => 'dark'])->assertOk();
    expect(setting('theme'))->toBe('dark');

    $this->postJson(route('settings.theme'), ['theme' => 'neon'])->assertStatus(422);
});

test('an admin can create a user with a role (regression: the form posted to the guest-only register route)', function () {
    $this->post(route('settings.users.create'), [
        'name' => 'Ada Manager',
        'email' => 'ada@example.com',
        'password' => 'a-Strong-pass-123',
        'password_confirmation' => 'a-Strong-pass-123',
        'role' => 'manager',
    ])->assertRedirect(route('settings.index'))->assertSessionHas('success');

    $user = User::where('email', 'ada@example.com')->first();
    expect($user->role)->toBe('manager')->and($user->hasRole('manager'))->toBeTrue();
});

test('user creation rejects unknown roles, short passwords and mismatched confirmation', function () {
    $base = ['name' => 'X', 'email' => 'x@example.com', 'password' => 'a-Strong-pass-123', 'password_confirmation' => 'a-Strong-pass-123'];

    $this->post(route('settings.users.create'), $base + ['role' => 'staff'])->assertSessionHasErrors('role');
    $this->post(route('settings.users.create'), array_merge($base, ['password' => '123', 'password_confirmation' => '123', 'role' => 'cashier']))->assertSessionHasErrors('password');
    $this->post(route('settings.users.create'), array_merge($base, ['password_confirmation' => 'nope', 'role' => 'cashier']))->assertSessionHasErrors('password');
});

test('an admin cannot delete themselves or the last admin', function () {
    $this->delete(route('settings.users.delete', $this->admin->id))->assertSessionHas('error');
    expect($this->admin->fresh()->trashed())->toBeFalse();

    $other = User::factory()->cashier()->create();
    $this->delete(route('settings.users.delete', $other->id))->assertSessionHas('success');
    expect($other->fresh()->trashed())->toBeTrue();
});

test('the users CSV export never leaks password hashes or tokens', function () {
    $csv = $this->get(route('settings.backup.export', 'users'))->assertOk()->streamedContent();

    expect($csv)->toContain('email')->not->toContain('password')->not->toContain('remember_token')
        ->not->toContain($this->admin->password);
});

test('CSV export neutralises spreadsheet formulas and rejects unknown tables', function () {
    \App\Models\Product::factory()->create(['name' => '=HYPERLINK("http://evil")']);

    $csv = $this->get(route('settings.backup.export', 'products'))->streamedContent();
    expect($csv)->toContain("'=HYPERLINK");

    $this->get(route('settings.backup.export', 'audit_logs'))->assertNotFound();
});

test('the settings page puts no <form> inside another <form>', function () {
    $html = $this->get(route('settings.index'))->getContent();

    // walk the tags in order and make sure forms never nest
    preg_match_all('/<(\/?)form\b/i', $html, $m);
    $depth = 0;
    foreach ($m[1] as $closing) {
        $depth += $closing === '/' ? -1 : 1;
        expect($depth)->toBeLessThanOrEqual(1);
    }
});

test('the user modal posts to the real create-user route and its ids match the script', function () {
    $html = $this->get(route('settings.index'))->getContent();

    expect($html)->toContain(route('settings.users.create'))
        ->and($html)->toContain('id="userModal"')->toContain('id="userForm"')->toContain('id="closeUserModal"')
        ->and($html)->toContain("getElementById('userModal')")->toContain("getElementById('closeUserModal')")
        // the old script looked up ids that never existed, which threw on page load
        ->and($html)->not->toContain("getElementById('user-modal')")
        ->and($html)->not->toContain("getElementById('close-user-modal')")
        ->and($html)->not->toContain("getElementById('user-form')");
});

/*
|--------------------------------------------------------------------------
| Business type and sector features
|--------------------------------------------------------------------------
*/

test('the business type must be one of the configured trades', function () {
    $this->post(route('settings.update'), ['business_type' => 'pharmacy'])->assertSessionHasNoErrors();
    expect(setting('business_type'))->toBe('pharmacy')->and(sector('key'))->toBe('pharmacy');

    // An unknown key is refused rather than stored for sector() to fall back on.
    $this->post(route('settings.update'), ['business_type' => 'crypto-exchange'])
        ->assertSessionHasErrors('business_type');

    expect(setting('business_type'))->toBe('pharmacy');
});

test('changing the business type changes the words the app uses', function () {
    Setting::set('business_type', 'retail');
    expect(term('product'))->toBe('Product');

    $this->post(route('settings.update'), ['business_type' => 'pharmacy']);
    expect(term('product'))->toBe('Drug')->and(term('product', true))->toBe('Drugs');
});

test('a feature override has three states and beats the preset', function () {
    Setting::set('business_type', 'services'); // stock is off in this preset
    expect(feature('stock'))->toBeFalse();

    // Forced on
    $this->post(route('settings.update'), ['business_type' => 'services', 'feature_stock' => '1'])
        ->assertSessionHasNoErrors();
    expect(feature('stock'))->toBeTrue();

    // Forced off, against a preset that has it on
    $this->post(route('settings.update'), ['business_type' => 'retail', 'feature_stock' => '0']);
    expect(feature('stock'))->toBeFalse();

    // Back to following the preset
    $this->post(route('settings.update'), ['business_type' => 'retail', 'feature_stock' => '']);
    expect(setting('feature_stock'))->toBe('')->and(feature('stock'))->toBeTrue();
});

test('a feature override only accepts the three states', function () {
    $this->post(route('settings.update'), ['feature_stock' => 'maybe'])->assertSessionHasErrors('feature_stock');
    $this->post(route('settings.update'), ['feature_expiry' => '2'])->assertSessionHasErrors('feature_expiry');
});

test('the business tab is built from the config, not a hardcoded list', function () {
    $html = $this->get(route('settings.index'))->getContent();

    // Every configured trade and every configured feature has a control.
    foreach (array_keys(config('sectors.types')) as $type) {
        expect($html)->toContain('value="' . $type . '"');
    }

    foreach (array_keys(config('sectors.features')) as $feature) {
        expect($html)->toContain('name="feature_' . $feature . '"');
    }

    // And the explanation of what switching type does is on screen.
    expect($html)->toContain('It does not delete anything');
});

/*
|--------------------------------------------------------------------------
| Currency
|--------------------------------------------------------------------------
*/

test('money() reflects a changed currency symbol and decimals', function () {
    $this->post(route('settings.update'), [
        'currency_symbol' => 'KSh',
        'currency_code' => 'KES',
        'currency_decimals' => 0,
    ])->assertSessionHasNoErrors();

    expect(currency())->toBe('KSh')
        ->and(money(1234.56))->toBe('KSh1,235')
        ->and(money(1234.56, false))->toBe('1,235');

    $this->post(route('settings.update'), ['currency_symbol' => '$', 'currency_decimals' => 2]);
    expect(money(1234.5))->toBe('$1,234.50');
});

test('currency_decimals is validated as 0 to 4', function () {
    $this->post(route('settings.update'), ['currency_decimals' => 2])->assertSessionHasNoErrors();

    foreach ([-1, 5, 'two', 2.5] as $bad) {
        $this->post(route('settings.update'), ['currency_decimals' => $bad])
            ->assertSessionHasErrors('currency_decimals');
    }

    expect(setting('currency_decimals'))->toBe('2');
});

test('the currency code must be three letters', function () {
    $this->post(route('settings.update'), ['currency_code' => 'NGN'])->assertSessionHasNoErrors();

    $this->post(route('settings.update'), ['currency_code' => 'NGNX'])->assertSessionHasErrors('currency_code');
    $this->post(route('settings.update'), ['currency_code' => 'N1N'])->assertSessionHasErrors('currency_code');
});

/*
|--------------------------------------------------------------------------
| Receipt
|--------------------------------------------------------------------------
*/

test('receipt options are stored and whitelisted', function () {
    $this->post(route('settings.update'), [
        'receipt_width' => '58mm',
        'receipt_footer' => 'Thank you. No refunds after 7 days.',
        'receipt_show_logo' => '1',
        'receipt_show_tax_number' => '1',
        'receipt_tax_number' => 'TIN-00112233',
        'print_method' => 'escpos',
        'printer_name' => 'POS-80',
    ])->assertSessionHasNoErrors();

    expect(setting('receipt_width'))->toBe('58mm')
        ->and(setting('receipt_footer'))->toBe('Thank you. No refunds after 7 days.')
        ->and(setting('receipt_show_logo'))->toBe('1')
        ->and(setting('receipt_tax_number'))->toBe('TIN-00112233')
        ->and(setting('printer_name'))->toBe('POS-80');

    // Not a paper width that exists.
    $this->post(route('settings.update'), ['receipt_width' => 'A4'])->assertSessionHasErrors('receipt_width');
});

test('the old receipt flags still work', function () {
    $this->post(route('settings.update'), ['show_logo' => '1', 'show_business_info' => '1']);
    expect(setting('show_logo'))->toBe('1')->and(setting('show_business_info'))->toBe('1');

    $this->post(route('settings.update'), ['store_name' => 'Shop']);
    expect(setting('show_logo'))->toBe('0')->and(setting('show_business_info'))->toBe('0');
});

/*
|--------------------------------------------------------------------------
| Security
|--------------------------------------------------------------------------
*/

test('the security flags are stored, and default off', function () {
    expect(setting('require_2fa_admins', '0'))->toBe('0')
        ->and(setting('require_open_shift', '0'))->toBe('0');

    $this->post(route('settings.update'), [
        'require_2fa_admins' => '1',
        'require_open_shift' => '1',
        'auto_logout_time' => 20,
    ])->assertSessionHasNoErrors();

    expect(setting('require_2fa_admins'))->toBe('1')
        ->and(setting('require_open_shift'))->toBe('1')
        ->and(setting('auto_logout_time'))->toBe('20');
});

/*
|--------------------------------------------------------------------------
| The screen itself
|--------------------------------------------------------------------------
*/

test('the settings screen is laid out in tabs and shows every group', function () {
    $html = $this->get(route('settings.index'))->assertOk()->getContent();

    foreach (['Store', 'Business', 'Currency', 'Sales', 'Receipt', 'Security', 'Users', 'Backup'] as $tab) {
        expect($html)->toContain('>' . $tab . '</button>');
    }
});

test('a manager sees the settings tabs but not users, backup or the audit log', function () {
    $this->actingAs(User::factory()->manager()->create());

    $html = $this->get(route('settings.index'))->assertOk()->getContent();

    expect($html)->toContain('>Store</button>')->toContain('>Currency</button>')
        ->not->toContain('>Users</button>')
        ->not->toContain('>Backup</button>')
        ->not->toContain(route('settings.audit'));
});

test('saving settings is recorded in the audit log', function () {
    $this->post(route('settings.update'), ['store_name' => 'Shop']);

    expect(AuditLog::where('action', 'Updated Settings')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Sharing a receipt
|--------------------------------------------------------------------------
*/

test('a receipt can be emailed to the customer', function () {
    Mail::fake();

    $sale = saleForSharing(['email' => 'joe@example.com']);

    $address = app(ReceiptShareService::class)->email($sale);

    expect($address)->toBe('joe@example.com');

    Mail::assertSent(ReceiptMail::class, fn ($mail) => $mail->hasTo('joe@example.com')
        && $mail->sale->id === $sale->id);

    expect(AuditLog::where('action', 'Emailed Receipt')->where('record_id', $sale->id)->exists())->toBeTrue();
});

test('emailing a receipt can be sent to an address given at the till', function () {
    Mail::fake();

    $sale = saleForSharing(['email' => null]);

    app(ReceiptShareService::class)->email($sale, 'walkin@example.com');

    Mail::assertSent(ReceiptMail::class, fn ($mail) => $mail->hasTo('walkin@example.com'));
});

test('emailing a receipt with nowhere to send it says so', function () {
    Mail::fake();

    $sale = saleForSharing(['email' => null]);

    expect(fn () => app(ReceiptShareService::class)->email($sale))
        ->toThrow(DomainException::class);

    app(ReceiptShareService::class)->email($sale, 'ok@example.com'); // and a good one still works
    Mail::assertSentCount(1);
});

test('the emailed receipt renders with the configured currency and no hardcoded symbol', function () {
    Mail::fake();

    Setting::set('currency_symbol', 'KSh');
    Setting::set('store_name', 'Mama Put Stores');

    $sale = saleForSharing(['email' => 'joe@example.com']);

    app(ReceiptShareService::class)->email($sale);

    Mail::assertSent(ReceiptMail::class, function ($mail) {
        $html = $mail->render();

        return str_contains($html, 'KSh') && str_contains($html, 'Mama Put Stores') && !str_contains($html, '₦');
    });
});

test('the WhatsApp link carries the number and a readable summary', function () {
    Setting::set('store_name', 'Mama Put Stores');
    Setting::set('currency_symbol', 'KSh');
    Setting::set('whatsapp_country_code', '234');

    $sale = saleForSharing(['phone' => '08012345678']);

    $url = app(ReceiptShareService::class)->whatsappUrl($sale);

    expect($url)->toStartWith('https://wa.me/2348012345678?text=');

    $text = urldecode(Str::after($url, 'text='));

    expect($text)->toContain('Mama Put Stores')
        ->toContain($sale->invoice_number)
        ->toContain('KSh')
        ->not->toContain('₦');
});

test('a WhatsApp number is normalised, and a missing one still gives a usable link', function () {
    $share = app(ReceiptShareService::class);

    Setting::set('whatsapp_country_code', '234');

    expect($share->normalisePhone('0801 234 5678'))->toBe('2348012345678')
        ->and($share->normalisePhone('+234 801 234 5678'))->toBe('2348012345678')
        ->and($share->normalisePhone('2348012345678'))->toBe('2348012345678')
        ->and($share->normalisePhone(null))->toBe('');

    // No country code configured: whatever was typed, stripped of punctuation.
    Setting::set('whatsapp_country_code', '');
    expect($share->normalisePhone('+1 (555) 010-9999'))->toBe('15550109999');

    // A sale with no customer still produces a link WhatsApp can ask about.
    $sale = saleForSharing(['phone' => null]);
    expect($share->whatsappUrl($sale))->toStartWith('https://wa.me/?text=');
});

test('the WhatsApp country code must be digits', function () {
    $this->post(route('settings.update'), ['whatsapp_country_code' => '234'])->assertSessionHasNoErrors();
    $this->post(route('settings.update'), ['whatsapp_country_code' => '+234'])->assertSessionHasErrors('whatsapp_country_code');
    $this->post(route('settings.update'), ['whatsapp_country_code' => '12345'])->assertSessionHasErrors('whatsapp_country_code');
});

/** A sale with one line and a customer, built by hand (the shared SaleFactory has no defaults). */
function saleForSharing(array $customer = []): Sale
{
    $buyer = Customer::create(array_merge([
        'name' => 'Joe Customer',
        'phone' => '08012345678',
        'email' => 'joe@example.com',
    ], $customer));

    $product = \App\Models\Product::factory()->create(['name' => 'Bag of rice']);

    $sale = Sale::create([
        'invoice_number' => 'INV-SHARE-' . Str::random(6),
        'user_id' => auth()->id(),
        'customer_id' => $buyer->id,
        'total_amount' => 2000,
        'grand_total' => 2000,
        'payment_method' => 'cash',
        'paid_amount' => 2000,
    ]);

    SaleItem::create([
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => 1000,
        'total' => 2000,
    ]);

    return $sale->fresh();
}
