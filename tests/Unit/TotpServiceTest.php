<?php

use App\Services\TotpService;

/*
 * The TOTP implementation is checked against the RFC 6238 test vectors. That is
 * the whole point of this file: an authenticator app and this code have to agree
 * on a number computed independently on two machines, and "it worked when I
 * tried it" is not evidence of that - a wrong-but-consistent implementation
 * passes a hand test and fails on every real phone.
 *
 * No database and no application: this is arithmetic.
 */
beforeEach(function () {
    $this->totp = new TotpService();

    // RFC 6238 Appendix B, SHA-1: the ASCII key "12345678901234567890".
    $this->secret = $this->totp->base32Encode('12345678901234567890');
});

test('base32 round-trips, and the RFC key encodes to the expected string', function () {
    expect($this->secret)->toBe('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ')
        ->and($this->totp->base32Decode($this->secret))->toBe('12345678901234567890');
});

test('base32 decoding ignores spaces, lower case and padding', function () {
    expect($this->totp->base32Decode('gezd gnbv gy3t qojq gezd gnbv gy3t qojq===='))
        ->toBe('12345678901234567890');
});

test('codes match the RFC 6238 test vectors', function (int $time, string $expected) {
    expect($this->totp->codeAt($this->secret, $time, digits: 8))->toBe($expected);
})->with([
    [59, '94287082'],
    [1111111109, '07081804'],
    [1111111111, '14050471'],
    [1234567890, '89005924'],
    [2000000000, '69279037'],
    [20000000000, '65353130'],
]);

test('the six-digit code is the last six digits of the RFC vector', function () {
    expect($this->totp->codeAt($this->secret, 59))->toBe('287082');
});

test('a code verifies, and one from a different secret does not', function () {
    $code = $this->totp->codeAt($this->secret, 1111111111);

    expect($this->totp->verify($this->secret, $code, timestamp: 1111111111))->toBeTrue()
        ->and($this->totp->verify($this->totp->generateSecret(), $code, timestamp: 1111111111))->toBeFalse();
});

test('the clock-skew window is one step either side and no further', function () {
    $code = $this->totp->codeAt($this->secret, 1111111111);

    // one step early, one step late: accepted
    expect($this->totp->verify($this->secret, $code, timestamp: 1111111111 - 30))->toBeTrue()
        ->and($this->totp->verify($this->secret, $code, timestamp: 1111111111 + 30))->toBeTrue()
        // two steps away: refused, or the 30-second code lasts two and a half minutes
        ->and($this->totp->verify($this->secret, $code, timestamp: 1111111111 - 90))->toBeFalse()
        ->and($this->totp->verify($this->secret, $code, timestamp: 1111111111 + 90))->toBeFalse();
});

test('malformed codes are refused rather than crashing', function () {
    foreach (['', '12345', '1234567', 'abcdef', '   ', '12 34 56'] as $rubbish) {
        expect($this->totp->verify($this->secret, $rubbish, timestamp: 59))->toBeFalse();
    }
});

test('a generated secret is 32 base32 characters of 160 bits', function () {
    $secret = $this->totp->generateSecret();

    expect($secret)->toHaveLength(32)
        ->toMatch('/^[A-Z2-7]+$/')
        // strlen, not toHaveLength: the decoded secret is 20 raw bytes, and
        // counting it as characters reads some byte pairs as one multibyte one.
        ->and(strlen($this->totp->base32Decode($secret)))->toBe(20);

    // Two secrets in a row must not be the same one.
    expect($this->totp->generateSecret())->not->toBe($secret);
});

test('the otpauth URI carries the issuer, the account and the secret', function () {
    $uri = $this->totp->otpauthUri('Mama Put Stores', 'ada@example.com', $this->secret);

    expect($uri)->toStartWith('otpauth://totp/')
        ->toContain('secret=' . $this->secret)
        ->toContain('issuer=Mama%20Put%20Stores')
        ->toContain('ada%40example.com')
        ->toContain('digits=6')
        ->toContain('period=30');
});

test('recovery codes are unique, readable and free of ambiguous characters', function () {
    $codes = $this->totp->generateRecoveryCodes();

    expect($codes)->toHaveCount(8)
        ->and(array_unique($codes))->toHaveCount(8);

    foreach ($codes as $code) {
        expect($code)->toMatch('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/');
    }
});

test('a recovery code is spent once and then refused', function () {
    $codes = $this->totp->generateRecoveryCodes();
    $used = $codes[3];

    $remaining = $this->totp->consumeRecoveryCode($codes, $used);

    expect($remaining)->toHaveCount(7)
        ->and($remaining)->not->toContain($used)
        // the same code again finds nothing to match: this is what makes it single-use
        ->and($this->totp->consumeRecoveryCode($remaining, $used))->toBeNull();
});

test('consuming a code that was never issued changes nothing', function () {
    $codes = $this->totp->generateRecoveryCodes();

    expect($this->totp->consumeRecoveryCode($codes, 'NOPE2-NOPE3'))->toBeNull()
        ->and($this->totp->consumeRecoveryCode([], 'ANY22-CODE3'))->toBeNull();
});

test('recovery codes are matched case-insensitively and trimmed', function () {
    $codes = $this->totp->generateRecoveryCodes();

    expect($this->totp->consumeRecoveryCode($codes, '  ' . strtolower($codes[0]) . ' '))
        ->toHaveCount(7);
});
