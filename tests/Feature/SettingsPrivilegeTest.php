<?php

use App\Models\Setting;
use App\Models\User;

/**
 * Who may write which setting.
 *
 * Every backup *operation* is admin-only, and the audit log is kept from
 * managers on purpose (see AuditLogPolicy). But the settings form is open to
 * managers, and `update()` wrote every checkbox on every save - so a manager
 * saving the shop phone number silently cleared flags whose tab their browser
 * never rendered. The failure is invisible: the screen that would report it is
 * the one they cannot see.
 */
beforeEach(function () {
    $this->manager = User::factory()->manager()->create();
    $this->admin = User::factory()->admin()->create();
});

test('a manager saving an unrelated setting does not switch off backups or encryption', function () {
    Setting::set('backup_enabled', '1');
    Setting::set('backup_offsite_enabled', '1');
    Setting::set('backup_encrypt', '1');

    // Exactly what a manager's browser posts: the tabs they can see, and nothing else.
    $this->actingAs($this->manager)
        ->post(route('settings.update'), ['store_name' => 'Corner Shop'])
        ->assertSessionHasNoErrors();

    expect(setting('backup_enabled'))->toBe('1')
        ->and(setting('backup_offsite_enabled'))->toBe('1')
        ->and(setting('backup_encrypt'))->toBe('1');
});

test('a manager cannot disable the audit log that records what they do', function () {
    Setting::set('enable_audit_log', '1');

    $this->actingAs($this->manager)
        ->post(route('settings.update'), ['store_name' => 'Corner Shop']);

    expect(setting('enable_audit_log'))->toBe('1');
});

test('a manager cannot set the backup encryption key or destination', function () {
    Setting::set('backup_offsite_path', 'salespoint-backups');

    $this->actingAs($this->manager)->post(route('settings.update'), [
        'store_name' => 'Corner Shop',
        'backup_encryption_key' => str_repeat('k', 40),
        'backup_offsite_path' => 'somewhere-else',
    ]);

    expect(setting('backup_offsite_path'))->toBe('salespoint-backups');
});

test('an admin can still change all of it', function () {
    $this->actingAs($this->admin)->post(route('settings.update'), [
        'store_name' => 'Corner Shop',
        'tabs' => ['store', 'security', 'backup'],
        'backup_enabled' => '1',
        'enable_audit_log' => '1',
    ])->assertSessionHasNoErrors();

    expect(setting('backup_enabled'))->toBe('1')
        ->and(setting('enable_audit_log'))->toBe('1');
});
