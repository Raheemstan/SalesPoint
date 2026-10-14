<?php

namespace App\Services;

use RuntimeException;

/**
 * Compressing and encrypting a database dump before it leaves the building.
 *
 * A dump is the whole shop: customer names, phone numbers and addresses, every
 * sale ever rung up, password hashes and two-factor secrets. A copy of that
 * sitting in a bucket - or worse, in somebody's inbox - is a data breach with a
 * delay on it. So nothing goes offsite in the clear.
 *
 * Everything here works in chunks. A dump is as large as the shop's whole
 * trading history, and `file_get_contents()` on it is a memory limit waiting to
 * be hit on the smallest VPS the shop could get away with.
 *
 * ## The file format
 *
 * A fixed 40-byte header, the ciphertext, then a 32-byte tag:
 *
 *   offset  0  8 bytes   magic "SALESPT1"
 *   offset  8  16 bytes  salt, random per file  (PBKDF2)
 *   offset 24  16 bytes  initialisation vector, random per file
 *   offset 40  ...       AES-256-CBC ciphertext, PKCS#7 padded
 *   last 32    32 bytes  HMAC-SHA256 over everything before it
 *
 * Three decisions worth explaining:
 *
 *  - **Salt and IV live in the file, not in the database.** A shop that still
 *    has the object but has lost the app must be able to get its data back, so
 *    every number needed to decrypt is in the file itself. The only secret is
 *    the key. See the restore notes in the summary for the openssl commands
 *    that do this by hand.
 *
 *  - **Encrypt-then-MAC, and the MAC is checked before a single byte is
 *    written.** Without it, the wrong key produces either a padding error at
 *    the very last block or, with bad luck, megabytes of plausible-looking
 *    garbage - and "plausible-looking garbage" restored over a live database is
 *    worse than a refusal. The check is its own streaming pass over the file:
 *    two reads, no memory.
 *
 *  - **CBC with the chain carried between chunks**, rather than a fresh IV per
 *    chunk or AES-GCM. It is what `openssl enc -d -aes-256-cbc` understands,
 *    which is the whole point of the manual escape hatch.
 */
class BackupEncrypter
{
    /** First 8 bytes of every encrypted backup, so the format is recognisable. */
    public const MAGIC = 'SALESPT1';

    public const CIPHER = 'aes-256-cbc';

    /** magic (8) + salt (16) + IV (16). */
    public const HEADER_BYTES = 40;

    /** The trailing HMAC-SHA256. */
    public const MAC_BYTES = 32;

    public const SALT_BYTES = 16;

    public const IV_BYTES = 16;

    /**
     * PBKDF2 rounds.
     *
     * Lower than you would use for a human-chosen password, deliberately: the
     * key here is a 64-character random string (see newKey()), so there is no
     * dictionary to grind through and the rounds are only defence against a
     * shop that typed in something short. It runs once per file, not per chunk.
     */
    public const ITERATIONS = 100000;

    private const BLOCK = 16;

    /**
     * @param  int  $chunk  bytes read at a time; must be a multiple of 16.
     *                      Smaller values exist for the tests, which need to
     *                      cross a chunk boundary without writing a megabyte.
     */
    public function __construct(private int $chunk = 1048576)
    {
        if ($this->chunk < self::BLOCK || $this->chunk % self::BLOCK !== 0) {
            throw new RuntimeException('The chunk size must be a positive multiple of ' . self::BLOCK . ' bytes.');
        }
    }

    /** A fresh key: 64 hex characters, 256 bits of real entropy. */
    public static function newKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ------------------------------------------------------------- compression

    /**
     * gzip $source into $target, a megabyte at a time.
     *
     * A SQL dump compresses by something like 80%, which is the difference
     * between a backup a shop on a market-stall connection can actually upload
     * every night and one it cannot.
     *
     * @return string $target
     */
    public function gzip(string $source, string $target): string
    {
        $in = $this->openRead($source);
        $out = @gzopen($target, 'wb6');

        if ($out === false) {
            fclose($in);

            throw new RuntimeException('Could not write the compressed backup to "' . $target . '".');
        }

        try {
            while (!feof($in)) {
                $buffer = fread($in, $this->chunk);

                if ($buffer === false) {
                    throw new RuntimeException('Failed while reading "' . $source . '" to compress it.');
                }

                if ($buffer !== '' && gzwrite($out, $buffer) === false) {
                    throw new RuntimeException('Failed while writing the compressed backup.');
                }
            }
        } catch (RuntimeException $e) {
            fclose($in);
            gzclose($out);
            @unlink($target);

            throw $e;
        }

        fclose($in);
        gzclose($out);

        return $target;
    }

    /**
     * The other direction, for a restore.
     *
     * @return string $target
     */
    public function gunzip(string $source, string $target): string
    {
        $in = @gzopen($source, 'rb');

        if ($in === false) {
            throw new RuntimeException('Could not open "' . $source . '" to decompress it.');
        }

        $out = @fopen($target, 'wb');

        if ($out === false) {
            gzclose($in);

            throw new RuntimeException('Could not write the decompressed backup to "' . $target . '".');
        }

        while (!gzeof($in)) {
            $buffer = gzread($in, $this->chunk);

            if ($buffer === false) {
                gzclose($in);
                fclose($out);
                @unlink($target);

                throw new RuntimeException('"' . basename($source) . '" is not a readable gzip file.');
            }

            if ($buffer !== '') {
                fwrite($out, $buffer);
            }
        }

        gzclose($in);
        fclose($out);

        return $target;
    }

    // -------------------------------------------------------------- encryption

    /**
     * Encrypt $source into $target with a key derived from $secret.
     *
     * @return string $target
     *
     * @throws RuntimeException and leaves no half-written file behind
     */
    public function encryptFile(string $source, string $target, string $secret): string
    {
        $this->requireSecret($secret);

        $salt = random_bytes(self::SALT_BYTES);
        $iv = random_bytes(self::IV_BYTES);
        [$key, $macKey] = $this->deriveKeys($secret, $salt);

        $in = $this->openRead($source);
        $out = @fopen($target, 'wb');

        if ($out === false) {
            fclose($in);

            throw new RuntimeException('Could not write the encrypted backup to "' . $target . '".');
        }

        $mac = hash_init('sha256', HASH_HMAC, $macKey);

        try {
            $header = self::MAGIC . $salt . $iv;
            fwrite($out, $header);
            hash_update($mac, $header);

            // CBC chains: each chunk is encrypted with the previous chunk's last
            // ciphertext block as its IV, exactly as one long stream would be.
            $chain = $iv;
            $buffer = '';

            while (true) {
                // One byte more than a chunk is kept in hand so we always know
                // whether the piece about to be encrypted is the final one -
                // only the final piece is padded.
                while (strlen($buffer) <= $this->chunk && !feof($in)) {
                    $read = fread($in, $this->chunk);

                    if ($read === false) {
                        throw new RuntimeException('Failed while reading "' . $source . '" to encrypt it.');
                    }

                    if ($read === '') {
                        break;
                    }

                    $buffer .= $read;
                }

                $final = strlen($buffer) <= $this->chunk;
                $piece = $final ? $buffer : substr($buffer, 0, $this->chunk);
                $buffer = $final ? '' : substr($buffer, $this->chunk);

                $cipher = openssl_encrypt(
                    $piece,
                    self::CIPHER,
                    $key,
                    // Only the last piece gets PKCS#7 padding; the others are
                    // whole blocks already and must not grow.
                    OPENSSL_RAW_DATA | ($final ? 0 : OPENSSL_ZERO_PADDING),
                    $chain
                );

                if ($cipher === false) {
                    throw new RuntimeException('OpenSSL refused to encrypt the backup.');
                }

                fwrite($out, $cipher);
                hash_update($mac, $cipher);
                $chain = substr($cipher, -self::BLOCK);

                if ($final) {
                    break;
                }
            }

            fwrite($out, hash_final($mac, true));
        } catch (RuntimeException $e) {
            fclose($in);
            fclose($out);
            @unlink($target);

            throw $e;
        }

        fclose($in);
        fclose($out);

        return $target;
    }

    /**
     * Decrypt $source into $target.
     *
     * The tag is verified first, in its own pass, so a wrong key or a truncated
     * download fails before anything is written.
     *
     * @return string $target
     *
     * @throws RuntimeException and leaves no half-written file behind
     */
    public function decryptFile(string $source, string $target, string $secret): string
    {
        $this->requireSecret($secret);

        $size = $this->sourceSize($source);
        $in = $this->openRead($source);

        try {
            /*
             * The magic is checked before the length. Both would catch a file
             * that is not ours, but "this is not an encrypted backup, and here
             * is what to do with a plain one" is a far more useful sentence
             * than "it looks truncated" when somebody has pointed this at the
             * wrong file.
             */
            $magic = $this->readExactly($in, strlen(self::MAGIC), $source);

            if ($magic !== self::MAGIC) {
                throw new RuntimeException(
                    '"' . basename($source) . '" is not an encrypted SalesPoint backup. If it is a plain .sql or '
                    . '.sqlite dump you can restore it directly, and a .gz needs only gunzip.'
                );
            }

            $body = $size - self::HEADER_BYTES - self::MAC_BYTES;

            if ($body < 0 || $body % self::BLOCK !== 0) {
                throw new RuntimeException(
                    '"' . basename($source) . '" is not a complete SalesPoint backup file - it looks truncated.'
                );
            }

            $salt = $this->readExactly($in, self::SALT_BYTES, $source);
            $iv = $this->readExactly($in, self::IV_BYTES, $source);
            [$key, $macKey] = $this->deriveKeys($secret, $salt);

            $this->verifyTag($in, $source, $body, $macKey);

            // Back to the start of the ciphertext for the real pass.
            if (fseek($in, self::HEADER_BYTES) !== 0) {
                throw new RuntimeException('Could not rewind "' . $source . '" to decrypt it.');
            }

            $out = @fopen($target, 'wb');

            if ($out === false) {
                throw new RuntimeException('Could not write the decrypted backup to "' . $target . '".');
            }

            try {
                $chain = $iv;
                $remaining = $body;

                while ($remaining > 0) {
                    $take = min($this->chunk, $remaining);
                    $cipher = $this->readExactly($in, $take, $source);
                    $remaining -= $take;
                    $final = $remaining === 0;

                    $plain = openssl_decrypt(
                        $cipher,
                        self::CIPHER,
                        $key,
                        OPENSSL_RAW_DATA | ($final ? 0 : OPENSSL_ZERO_PADDING),
                        $chain
                    );

                    /*
                     * The tag already proved the key and the bytes, so this can
                     * really only trip on the final block's padding - but a
                     * silent false here would write an empty file and call it a
                     * restore, so it is checked anyway.
                     */
                    if ($plain === false) {
                        throw new RuntimeException('OpenSSL refused to decrypt the backup.');
                    }

                    fwrite($out, $plain);
                    $chain = substr($cipher, -self::BLOCK);
                }
            } catch (RuntimeException $e) {
                fclose($out);
                @unlink($target);

                throw $e;
            }

            fclose($out);
        } finally {
            fclose($in);
        }

        return $target;
    }

    /** Does this file start with our magic? Used before offering to decrypt one. */
    public function isEncrypted(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = fread($handle, strlen(self::MAGIC));
        fclose($handle);

        return $magic === self::MAGIC;
    }

    // ---------------------------------------------------------------- internals

    /**
     * One PBKDF2 pass, split into an encryption key and a separate MAC key.
     *
     * Separate keys because using one key for both is the mistake that makes
     * encrypt-then-MAC stop proving anything.
     *
     * @return array{0: string, 1: string}
     */
    private function deriveKeys(string $secret, string $salt): array
    {
        $material = hash_pbkdf2('sha256', $secret, $salt, self::ITERATIONS, 64, true);

        return [substr($material, 0, 32), substr($material, 32, 32)];
    }

    /**
     * Stream the header and ciphertext past an HMAC and compare it with the tag
     * on the end of the file.
     *
     * $in is positioned at the start of the ciphertext when this is called.
     */
    private function verifyTag($in, string $source, int $body, string $macKey): void
    {
        $mac = hash_init('sha256', HASH_HMAC, $macKey);
        hash_update($mac, self::MAGIC);

        if (fseek($in, strlen(self::MAGIC)) !== 0) {
            throw new RuntimeException('Could not read "' . $source . '" to check it.');
        }

        hash_update($mac, $this->readExactly($in, self::SALT_BYTES + self::IV_BYTES, $source));

        $remaining = $body;

        while ($remaining > 0) {
            $take = min($this->chunk, $remaining);
            hash_update($mac, $this->readExactly($in, $take, $source));
            $remaining -= $take;
        }

        $expected = $this->readExactly($in, self::MAC_BYTES, $source);

        // hash_equals, not ===: constant time, so the comparison itself gives
        // nothing away.
        if (!hash_equals(hash_final($mac, true), $expected)) {
            throw new RuntimeException(
                'That encryption key does not open "' . basename($source) . '", or the file was damaged in transit. '
                . 'Nothing was written. Check the key in Settings > Backup, and that the download finished.'
            );
        }
    }

    private function requireSecret(string $secret): void
    {
        if (trim($secret) === '') {
            throw new RuntimeException('No backup encryption key is set, so there is nothing to encrypt or decrypt with.');
        }
    }

    private function openRead(string $path)
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Could not open "' . $path . '".');
        }

        return $handle;
    }

    private function sourceSize(string $path): int
    {
        if (!is_file($path)) {
            throw new RuntimeException('There is no file at "' . $path . '".');
        }

        $size = @filesize($path);

        if ($size === false) {
            throw new RuntimeException('Could not measure "' . $path . '".');
        }

        return (int) $size;
    }

    /**
     * fread() is allowed to come back short on any stream; asking for exactly
     * $length and getting less means the file ended early.
     */
    private function readExactly($handle, int $length, string $source): string
    {
        $out = '';

        while (strlen($out) < $length) {
            $read = fread($handle, $length - strlen($out));

            if ($read === false || $read === '') {
                throw new RuntimeException('"' . basename($source) . '" ended sooner than its header says it should.');
            }

            $out .= $read;
        }

        return $out;
    }
}
