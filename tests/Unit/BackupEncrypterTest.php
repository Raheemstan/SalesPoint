<?php

use App\Services\BackupEncrypter;

/*
|--------------------------------------------------------------------------
| BackupEncrypter
|--------------------------------------------------------------------------
|
| No database and no application: this is bytes in and bytes out, which is
| exactly what has to be provable. An encrypted backup is useless if it cannot
| be decrypted and dangerous if it can be decrypted wrongly, and neither of
| those shows up on the screen - it shows up on the day a shop has lost its
| database and this file is all they have left.
|
| The chunk size is set to 64 bytes throughout rather than the real megabyte, so
| that a few kilobytes of test data crosses several chunk boundaries. That is
| where the bugs live: the CBC chain between chunks, and the padding that must
| go on the last piece and nowhere else.
*/
beforeEach(function () {
    $this->chunk = 64;
    $this->encrypter = new BackupEncrypter($this->chunk);
    $this->key = 'a-very-secret-key-that-is-long-enough-to-be-real';

    $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'salespoint-encrypter-test-' . bin2hex(random_bytes(4));
    mkdir($this->workspace, 0755, true);

    $this->file = fn (string $name) => $this->workspace . DIRECTORY_SEPARATOR . $name;

    $this->write = function (string $name, string $contents) {
        $path = $this->workspace . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);

        return $path;
    };
});

afterEach(function () {
    // Leave no temporary files behind, even when a test failed.
    if (isset($this->workspace) && is_dir($this->workspace)) {
        foreach (glob($this->workspace . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workspace);
    }
});

// ------------------------------------------------------------------ round trips

test('encrypting and decrypting returns the file byte for byte', function () {
    /*
     * Random binary, not text: a dump is a binary file (SQLite certainly is),
     * and text that happens to survive is no evidence that arbitrary bytes do.
     * 5,000 bytes is 78 chunks at this chunk size, and is not a multiple of the
     * 16-byte block, so the final piece is a partial one that must be padded.
     */
    $plain = random_bytes(5000);
    $source = ($this->write)('dump.sqlite', $plain);

    $this->encrypter->encryptFile($source, ($this->file)('dump.enc'), $this->key);
    $this->encrypter->decryptFile(($this->file)('dump.enc'), ($this->file)('back.sqlite'), $this->key);

    expect(file_get_contents(($this->file)('back.sqlite')))->toBe($plain)
        ->and(hash_file('sha256', ($this->file)('back.sqlite')))->toBe(hash_file('sha256', $source));
});

test('a file that is an exact multiple of the chunk size round-trips too', function () {
    /*
     * The boundary case the read-ahead buffer exists for: when the data runs
     * out exactly at a chunk edge, something still has to be identified as the
     * final piece and padded, or the file comes back 16 bytes short.
     */
    $plain = random_bytes($this->chunk * 4);
    $source = ($this->write)('exact.sqlite', $plain);

    $this->encrypter->encryptFile($source, ($this->file)('exact.enc'), $this->key);
    $this->encrypter->decryptFile(($this->file)('exact.enc'), ($this->file)('exact.back'), $this->key);

    expect(file_get_contents(($this->file)('exact.back')))->toBe($plain);
});

test('an empty file and a one-byte file both round-trip', function () {
    foreach (['' => 'empty', 'x' => 'tiny'] as $contents => $name) {
        $source = ($this->write)($name, (string) $contents);

        $this->encrypter->encryptFile($source, ($this->file)($name . '.enc'), $this->key);
        $this->encrypter->decryptFile(($this->file)($name . '.enc'), ($this->file)($name . '.back'), $this->key);

        expect(file_get_contents(($this->file)($name . '.back')))->toBe((string) $contents);
    }
});

test('compressing and decompressing returns the file byte for byte', function () {
    $plain = str_repeat('INSERT INTO sales VALUES (1, 2, 3);' . "\n", 500);
    $source = ($this->write)('dump.sql', $plain);

    $this->encrypter->gzip($source, ($this->file)('dump.sql.gz'));

    // It is a real gzip file, which matters: the manual restore path is
    // "openssl, then gunzip", using tools that are not ours.
    expect(bin2hex(file_get_contents(($this->file)('dump.sql.gz'))))->toStartWith('1f8b')
        ->and(filesize(($this->file)('dump.sql.gz')))->toBeLessThan(strlen($plain));

    $this->encrypter->gunzip(($this->file)('dump.sql.gz'), ($this->file)('dump.sql.back'));

    expect(file_get_contents(($this->file)('dump.sql.back')))->toBe($plain);
});

test('the whole pipeline - compress then encrypt - round-trips', function () {
    // The order backups actually use. Decrypt then gunzip is the reverse, and
    // is what the restore command and the documented openssl recipe both do.
    $plain = str_repeat('customer,phone,address' . "\n", 400) . random_bytes(300);
    $source = ($this->write)('live.sqlite', $plain);

    $this->encrypter->gzip($source, ($this->file)('live.gz'));
    $this->encrypter->encryptFile(($this->file)('live.gz'), ($this->file)('live.gz.enc'), $this->key);

    $this->encrypter->decryptFile(($this->file)('live.gz.enc'), ($this->file)('out.gz'), $this->key);
    $this->encrypter->gunzip(($this->file)('out.gz'), ($this->file)('out.sqlite'));

    expect(file_get_contents(($this->file)('out.sqlite')))->toBe($plain);
});

// ------------------------------------------------------------- the ciphertext

test('the ciphertext does not contain the plaintext', function () {
    /*
     * The point of the whole exercise. A dump holds customer names, phone
     * numbers, addresses and the staff's password hashes; if any of that is
     * legible in the uploaded object then the encryption is decoration.
     */
    $secrets = [
        'Adaeze Okonkwo',
        '+2348031234567',
        '12 Allen Avenue, Ikeja',
        '$2y$12$abcdefghijklmnopqrstuv',
        'JBSWY3DPEHPK3PXP',
    ];

    $plain = "CREATE TABLE customers;\n" . implode("\n", $secrets) . "\n" . str_repeat('padding', 200);
    $source = ($this->write)('secrets.sqlite', $plain);

    $this->encrypter->encryptFile($source, ($this->file)('secrets.enc'), $this->key);

    $cipher = file_get_contents(($this->file)('secrets.enc'));

    foreach ($secrets as $secret) {
        expect($cipher)->not->toContain($secret);
    }

    expect($cipher)->not->toContain('CREATE TABLE')
        ->and($cipher)->not->toContain('padding');
});

test('the file says what it is, and carries its own salt and IV', function () {
    /*
     * Salt and IV live in the file rather than in the database, deliberately: a
     * shop that still has the bucket object but has lost the app must be able
     * to get its data back with openssl and the key alone.
     */
    $source = ($this->write)('dump.sqlite', random_bytes(300));
    $this->encrypter->encryptFile($source, ($this->file)('dump.enc'), $this->key);

    $header = file_get_contents(($this->file)('dump.enc'), false, null, 0, BackupEncrypter::HEADER_BYTES);

    expect(substr($header, 0, 8))->toBe(BackupEncrypter::MAGIC)
        ->and(strlen($header))->toBe(40)
        // Not all zeroes: a forgotten random_bytes() is the classic way an IV
        // ends up constant, and it would still decrypt perfectly.
        ->and(substr($header, 8, 32))->not->toBe(str_repeat("\0", 32))
        ->and($this->encrypter->isEncrypted(($this->file)('dump.enc')))->toBeTrue()
        ->and($this->encrypter->isEncrypted($source))->toBeFalse();
});

test('encrypting the same file twice gives different ciphertext', function () {
    // A fresh salt and IV each time. Identical output would tell anyone
    // watching the bucket which nights the database did not change.
    $source = ($this->write)('dump.sqlite', str_repeat('same bytes every night', 100));

    $this->encrypter->encryptFile($source, ($this->file)('one.enc'), $this->key);
    $this->encrypter->encryptFile($source, ($this->file)('two.enc'), $this->key);

    expect(file_get_contents(($this->file)('one.enc')))
        ->not->toBe(file_get_contents(($this->file)('two.enc')))
        ->and(filesize(($this->file)('one.enc')))->toBe(filesize(($this->file)('two.enc')));
});

// --------------------------------------------------------------- failing well

test('the wrong key is refused instead of producing garbage', function () {
    /*
     * The failure that matters most. Without the authentication tag, a wrong
     * key gives either a padding error on the very last block or - worse -
     * megabytes of plausible-looking rubbish, and plausible-looking rubbish
     * restored over a live database is worse than an honest refusal.
     */
    $plain = random_bytes(2000);
    $source = ($this->write)('dump.sqlite', $plain);
    $this->encrypter->encryptFile($source, ($this->file)('dump.enc'), $this->key);

    expect(fn () => $this->encrypter->decryptFile(($this->file)('dump.enc'), ($this->file)('out'), 'the-wrong-key-entirely'))
        ->toThrow(RuntimeException::class, 'does not open');

    // And nothing was written: no half-file to mistake for a restore.
    expect(is_file(($this->file)('out')))->toBeFalse();
});

test('a single altered byte is detected', function () {
    $source = ($this->write)('dump.sqlite', random_bytes(1000));
    $this->encrypter->encryptFile($source, ($this->file)('dump.enc'), $this->key);

    // Storage bit-rot, a truncated proxy, a tampering hand: all the same here.
    $cipher = file_get_contents(($this->file)('dump.enc'));
    $cipher[500] = $cipher[500] === 'A' ? 'B' : 'A';
    file_put_contents(($this->file)('tampered.enc'), $cipher);

    expect(fn () => $this->encrypter->decryptFile(($this->file)('tampered.enc'), ($this->file)('out'), $this->key))
        ->toThrow(RuntimeException::class);

    expect(is_file(($this->file)('out')))->toBeFalse();
});

test('a truncated download is refused rather than half-restored', function () {
    $source = ($this->write)('dump.sqlite', random_bytes(2000));
    $this->encrypter->encryptFile($source, ($this->file)('dump.enc'), $this->key);

    $whole = file_get_contents(($this->file)('dump.enc'));
    file_put_contents(($this->file)('short.enc'), substr($whole, 0, 900));

    expect(fn () => $this->encrypter->decryptFile(($this->file)('short.enc'), ($this->file)('out'), $this->key))
        ->toThrow(RuntimeException::class);

    expect(is_file(($this->file)('out')))->toBeFalse();
});

test('a file that is not one of ours says so, and says what to do instead', function () {
    $source = ($this->write)('plain.sql', 'INSERT INTO sales VALUES (1);' . str_repeat('x', 200));

    expect(fn () => $this->encrypter->decryptFile($source, ($this->file)('out'), $this->key))
        ->toThrow(RuntimeException::class, 'not an encrypted SalesPoint backup');
});

test('a missing file is reported plainly', function () {
    expect(fn () => $this->encrypter->decryptFile(($this->file)('nope.enc'), ($this->file)('out'), $this->key))
        ->toThrow(RuntimeException::class, 'no file at');
});

test('an empty key is refused in both directions', function () {
    $source = ($this->write)('dump.sqlite', 'data');

    expect(fn () => $this->encrypter->encryptFile($source, ($this->file)('out.enc'), ''))
        ->toThrow(RuntimeException::class, 'No backup encryption key')
        ->and(fn () => $this->encrypter->decryptFile($source, ($this->file)('out'), '   '))
        ->toThrow(RuntimeException::class, 'No backup encryption key');
});

test('the chunk size has to be a whole number of blocks', function () {
    // AES-CBC works in 16-byte blocks; a chunk that is not a multiple of one
    // would silently corrupt every chunk boundary.
    expect(fn () => new BackupEncrypter(100))->toThrow(RuntimeException::class, 'multiple of 16')
        ->and(fn () => new BackupEncrypter(0))->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------- keys

test('a generated key is 256 bits of hex and never the same twice', function () {
    $keys = collect(range(1, 20))->map(fn () => BackupEncrypter::newKey());

    expect($keys->unique())->toHaveCount(20);

    foreach ($keys as $key) {
        expect($key)->toHaveLength(64)->toMatch('/^[0-9a-f]{64}$/');
    }
});

test('a key that differs by one character does not open the file', function () {
    // There is no "close enough" to a key: a typo must fail, not half-work.
    $source = ($this->write)('dump.sqlite', random_bytes(500));
    $this->encrypter->encryptFile($source, ($this->file)('dump.enc'), 'correct-horse-battery-staple-1234');

    expect(fn () => $this->encrypter->decryptFile(($this->file)('dump.enc'), ($this->file)('out'), 'correct-horse-battery-staple-1235'))
        ->toThrow(RuntimeException::class, 'does not open');
});
