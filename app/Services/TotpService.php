<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Time-based one-time passwords (RFC 6238), written out rather than installed.
 *
 * Several agents share this tree's composer.json, so a package was not an
 * option - and the algorithm is small enough to read in one sitting:
 *
 *   counter = floor(unix time / 30)
 *   hmac    = HMAC-SHA1(secret, counter as 8 big-endian bytes)
 *   offset  = low nibble of the last hmac byte
 *   code    = (4 bytes at offset, top bit cleared) mod 10^digits
 *
 * The parts that are easy to get wrong, and are therefore spelled out below:
 *
 *  - The shared secret is handled as raw bytes. Authenticator apps show it as
 *    base32, so it is decoded on the way in and encoded on the way out, but it
 *    is never hashed: both sides must compute the same code from it.
 *  - Clocks drift. A window of +/-1 step accepts the code the user is reading
 *    while it expires, at the cost of a 90-second validity instead of 30.
 *  - Comparisons are timing-safe. Comparing codes with == leaks, one character
 *    at a time, how much of a guess was right.
 *
 * Verified against the RFC 6238 test vectors in tests/Unit/TotpServiceTest.php.
 */
class TotpService
{
    /** Seconds per code. 30 is what every authenticator app assumes. */
    public const PERIOD = 30;

    /** Digits shown to the user. 6 is what every authenticator app assumes. */
    public const DIGITS = 6;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A fresh shared secret, base32-encoded for display and for the QR code.
     *
     * 20 bytes = 160 bits, which is the HMAC-SHA1 block size RFC 4226 asks for;
     * shorter secrets are accepted by apps but weaken the whole scheme.
     */
    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    /**
     * The code for a point in time (defaults to now).
     *
     * @param  string  $secret  base32, as shown to the user
     */
    public function codeAt(string $secret, ?int $timestamp = null, int $digits = self::DIGITS, int $period = self::PERIOD): string
    {
        $counter = intdiv($timestamp ?? time(), $period);

        return $this->codeForCounter($secret, $counter, $digits);
    }

    /**
     * Does this code match the secret, allowing for clock skew?
     *
     * @param  int  $window  how many 30-second steps either side to accept
     */
    public function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null, int $digits = self::DIGITS, int $period = self::PERIOD): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if ($secret === '' || strlen($code) !== $digits) {
            return false;
        }

        $counter = intdiv($timestamp ?? time(), $period);

        // Every candidate is computed and compared even once one has matched:
        // bailing out early turns the window into a timing signal.
        $matched = false;

        for ($i = -$window; $i <= $window; $i++) {
            $candidate = $this->codeForCounter($secret, $counter + $i, $digits);

            if (hash_equals($candidate, $code)) {
                $matched = true;
            }
        }

        return $matched;
    }

    /**
     * The URI an authenticator app reads from the QR code.
     *
     * The label carries the account so a user with several shops can tell the
     * entries apart; the issuer is repeated as a parameter because older apps
     * read one and newer apps read the other.
     */
    public function otpauthUri(string $issuer, string $account, string $secret): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * One-time codes for the day the phone is lost or wiped.
     *
     * Two halves separated by a dash so they can be read aloud, and no
     * ambiguous characters (0/O, 1/I) because these get copied by hand.
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::upper(Str::random(5) . '-' . Str::random(5)))
            ->map(fn (string $code) => str_replace(['0', 'O', '1', 'I', 'L'], ['2', '3', '4', '5', '6'], $code))
            ->all();
    }

    /**
     * Spend a recovery code: returns the remaining codes, or null if the code
     * was not one of them.
     *
     * The caller must persist what comes back - that is what makes a code
     * single-use, and a replayed code is then simply not in the list any more.
     */
    public function consumeRecoveryCode(array $codes, string $candidate): ?array
    {
        $candidate = Str::upper(trim($candidate));
        $remaining = [];
        $found = false;

        foreach ($codes as $code) {
            // hash_equals, not ===, for the same reason as the TOTP comparison.
            if (!$found && hash_equals(Str::upper((string) $code), $candidate)) {
                $found = true;

                continue;
            }

            $remaining[] = $code;
        }

        return $found ? array_values($remaining) : null;
    }

    /** Base32 (RFC 4648, no padding needed by authenticator apps). */
    public function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    /** The inverse. Unknown characters (spaces, padding, typos) are dropped. */
    public function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');

        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::BASE32_ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        // Trailing bits that do not make a whole byte are padding, not data.
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }

    /** HOTP (RFC 4226): the step every TOTP code is built on. */
    private function codeForCounter(string $secret, int $counter, int $digits): string
    {
        $key = $this->base32Decode($secret);

        // The counter goes on the wire as 8 bytes, big-endian. pack('J') is
        // 64-bit big-endian, which is exactly that.
        $hmac = hash_hmac('sha1', pack('J', $counter), $key, true);

        // Dynamic truncation: the last nibble picks where to read from.
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;

        $value = ((ord($hmac[$offset]) & 0x7F) << 24)
            | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
            | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
            | (ord($hmac[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }
}
