<?php

use App\Models\User;

// Public self-registration is disabled: anyone who could sign up would get into the POS.
// Accounts are created by an admin under Settings > Users (see SettingsTest).

test('the public registration screen is not available', function () {
    $this->get('/register')->assertNotFound();
});

test('the public registration endpoint is not available', function () {
    $this->post('/register', [
        'name' => 'Intruder',
        'email' => 'intruder@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    expect(User::where('email', 'intruder@example.com')->exists())->toBeFalse();
});
