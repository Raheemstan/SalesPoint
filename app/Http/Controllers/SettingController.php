<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Setting;
use App\Models\User;
use App\Services\BackupService;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;
use RuntimeException;

class SettingController extends Controller
{
    /**
     * Text/number settings editable on the settings form.
     *
     * This is a whitelist, not a convenience: the old code wrote every field in
     * the request straight into the settings table, so a crafted form could
     * invent keys the app would later read. Adding a setting means adding it
     * here *and* giving it a rule in rules() - anything else is ignored.
     */
    private const VALUE_KEYS = [
        // Store
        'store_name', 'store_phone', 'store_motto', 'store_email', 'store_address',
        // Business (the feature_* overrides are added by valueKeys(), from config)
        'business_type',
        // Currency - read by the currency() and money() helpers
        'currency_symbol', 'currency_code', 'currency_decimals',
        // Sales
        'tax_rate', 'max_cashier_discount', 'low_stock_threshold',
        // Receipt
        'receipt_width', 'receipt_footer', 'receipt_tax_number', 'print_method', 'printer_name',
        // Sharing a receipt
        'whatsapp_country_code',
        // Security and appearance
        'auto_logout_time', 'theme',
        // Backup
        'backup_frequency', 'backup_time', 'backup_retention_days', 'backup_retention_count',
        'backup_disk', 'backup_path',
        // Offsite backup - the copy that is not on this machine
        'backup_offsite_disk', 'backup_offsite_path', 'backup_offsite_retention_days',
        'backup_encryption_key',
        'backup_email_to', 'backup_email_max_mb',
    ];

    /** Checkbox settings: an unchecked box is simply absent from the request. */
    private const FLAG_KEYS = [
        'show_logo', 'show_business_info', 'enable_low_stock_warning', 'enable_audit_log',
        'receipt_show_logo', 'receipt_show_tax_number',
        'require_2fa_admins', 'require_open_shift',
        'backup_enabled',
        'backup_offsite_enabled', 'backup_encrypt', 'backup_email_report', 'backup_email_attach',
    ];

    /**
     * Settings only an admin may write, whatever the form posts.
     *
     * Two separate problems, one fix.
     *
     * The first is a silent one. Every flag above is written on every save,
     * because an unchecked box is absent from the request and cannot be told
     * apart from a box that was never on the page. That rule is only safe while
     * the whole form renders - and the Backup tab renders for admins only. So a
     * manager changing the shop's phone number posted no backup checkboxes, and
     * every one of them was written '0': scheduled backups off, offsite copies
     * off, encryption off. Nothing said so, because the screen that would is
     * the one the manager cannot see. For a backup feature that is the worst
     * failure there is - it stops working and waits to be needed.
     *
     * The second is deliberate privilege. Every backup *operation* is admin
     * only, so a manager who may not take, list or download a backup must not
     * own the key that protects every one of them, nor choose where they are
     * written. The same goes for the two settings that constrain managers
     * themselves: enable_audit_log is the record of what they did (AuditLogPolicy
     * keeps it from them precisely so they cannot check whether anyone noticed),
     * and require_2fa_admins guards accounts above theirs. A role must not be
     * able to edit its own constraints.
     *
     * Admins are unaffected: their form renders every tab, so their save
     * carries every box.
     */
    private const ADMIN_ONLY_KEYS = [
        'enable_audit_log', 'require_2fa_admins',
        'backup_enabled', 'backup_frequency', 'backup_time',
        'backup_retention_days', 'backup_retention_count', 'backup_disk', 'backup_path',
        'backup_offsite_enabled', 'backup_offsite_disk', 'backup_offsite_path',
        'backup_offsite_retention_days', 'backup_encrypt', 'backup_encryption_key',
        'backup_email_report', 'backup_email_to', 'backup_email_attach', 'backup_email_max_mb',
    ];

    /** Keys the signed-in user is allowed to write on this request. */
    private function writableKeys(Request $request, array $keys): array
    {
        if ($request->user()?->hasRole('admin')) {
            return $keys;
        }

        return array_values(array_diff($keys, self::ADMIN_ONLY_KEYS));
    }

    /**
     * Settings that are secrets: never sent back to the browser, in any form.
     *
     * The encryption key is the one thing standing between a stolen bucket
     * object and the shop's entire customer list, so it does not go into a page
     * - not into a value attribute, not into a JSON blob, not into the settings
     * array handed to a view. A secret rendered into HTML is a secret in the
     * browser cache, in the back button, and in the next screenshot somebody
     * attaches to a support email. The screen says whether it is set and lets
     * it be replaced; `php artisan salespoint:backup:key --show` is how you
     * read it, on the server, where the .env file already lives.
     */
    private const SECRET_KEYS = [
        'backup_encryption_key',
    ];

    /** Tables that may be exported as CSV, and the columns that are safe to expose. */
    private const EXPORTS = [
        'products' => ['*'],
        'sales' => ['*'],
        'users' => ['id', 'name', 'email', 'role', 'created_at'], // never password hashes / tokens / 2FA secrets
    ];

    /** Paper widths a thermal printer actually comes in. */
    public const RECEIPT_WIDTHS = [
        '58mm' => '58 mm (small thermal roll)',
        '80mm' => '80 mm (standard thermal roll)',
    ];

    public function index(Request $request)
    {
        // Arr::except, not setting(): the settings array goes into a view, and
        // the encryption key must never travel that way. See SECRET_KEYS.
        $settings = Arr::except(setting(), self::SECRET_KEYS);

        $users = $request->user()->hasRole('admin')
            ? User::orderBy('name')->get()
            : collect();

        // The Backup tab shows the latest runs inline; the full history has its
        // own page because it paginates (see backups()).
        $backups = $request->user()->hasRole('admin')
            ? Backup::with('creator:id,name')->latest('id')->limit(10)->get()
            : collect();

        return view('settings.index', [
            'settings' => $settings,
            'users' => $users,
            'backups' => $backups,
            'backupDestination' => app(BackupService::class)->destinationLabel(),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate($this->rules());

        /*
         * Recorded before the write, not after. One of the settings on this
         * screen switches the audit log itself off, and the save that does that
         * is the one entry you would most want to find afterwards - writing the
         * flags first would swallow it.
         */
        AuditLog::record('Updated Settings', 'settings', 0);

        /*
         * Before the loops below, for the same reason the entry above is: one
         * of the flags on this screen switches the audit log off, and a key
         * change recorded after that write would be swallowed. Whoever restores
         * a backup later needs to know when the key changed, because the older
         * backups need the older key.
         */
        $this->updateEncryptionKey($request);

        // Only known keys are stored (the old code saved every request field),
        // and settings.value is NOT NULL, so empty fields become ''.
        foreach ($this->writableKeys($request, $this->valueKeys()) as $key) {
            if (in_array($key, self::SECRET_KEYS, true)) {
                continue;   // handled above, on its own terms
            }

            if ($request->exists($key)) {
                Setting::set($key, $request->input($key) ?? '');
            }
        }

        foreach ($this->writableKeys($request, self::FLAG_KEYS) as $key) {
            Setting::set($key, $request->boolean($key) ? '1' : '0');
        }

        if ($request->hasFile('store_logo')) {
            /*
             * Through MediaService, like product images, rather than a bare
             * ->store(). That gives four things this used to lack: a write
             * failure raises instead of storing the literal `false` the public
             * disk returns (which silently lost the logo while reporting
             * success), the previous file is deleted instead of accumulating one
             * orphan per upload for ever, the extension comes from the file's
             * content rather than its name, and WebP is accepted - the product
             * form already took it, so refusing it here was just inconsistent.
             */
            $media = app(MediaService::class);
            $previous = setting('store_logo');

            try {
                $stored = $media->store($request->file('store_logo'), 'logos');
            } catch (RuntimeException $e) {
                return back()->withInput()->with('error', $e->getMessage());
            }

            Setting::set('store_logo', $stored);
            $media->delete($previous);
        }

        if ($request->boolean('remove_store_logo')) {
            // There was no way to take a logo off once one had been put on.
            $media = app(MediaService::class);
            $media->delete(setting('store_logo'));
            Setting::set('store_logo', '');
        }

        return redirect()->route('settings.index')->with('success', 'Settings updated successfully.');
    }

    /**
     * The encryption key is write-only, and an empty box means "leave it alone".
     *
     * Every other setting treats an empty field as "clear it". That rule is
     * wrong for a password-style field that is never rendered back: the box is
     * blank on every page load, so saving the Store tab would wipe the key and
     * silently orphan every backup already in the bucket.
     */
    private function updateEncryptionKey(Request $request): void
    {
        $key = trim((string) $request->input('backup_encryption_key', ''));

        if ($key === '') {
            return;
        }

        /*
         * Written outside the whitelist loops, so it needs the same guard they
         * now have. Replacing this key orphans every encrypted object already
         * in the bucket - the old ones need the old key - which makes it the
         * most destructive field on the screen and squarely an admin's.
         */
        if (!$request->user()?->hasRole('admin')) {
            return;
        }

        app(BackupService::class)->storeEncryptionKey($key);

        // Worth its own audit entry: whoever restores a backup later needs to
        // know when the key changed, because the older ones need the older key.
        AuditLog::record('Changed Backup Encryption Key', 'settings', 0);
    }

    /** Theme switcher in the header: available to every signed-in user. */
    public function updateTheme(Request $request)
    {
        $data = $request->validate(['theme' => 'required|in:light,dark,system']);

        Setting::set('theme', $data['theme']);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------ backups

    /** Every backup run, newest first. */
    public function backups()
    {
        $backups = Backup::with('creator:id,name')->latest('id')->paginate(25);

        return view('settings.backups', [
            'backups' => $backups,
            'destination' => app(BackupService::class)->destinationLabel(),
        ]);
    }

    /** Run a backup now, by hand. */
    public function runBackup(BackupService $backups)
    {
        $backup = $backups->run(auth()->user());
        $backups->recordAudit($backup);

        if (!$backup->succeeded()) {
            return back()->with('error', 'Backup failed: ' . $backup->error);
        }

        return back()->with('success', "Backed up {$backup->filename} ({$backup->size_for_humans}).");
    }

    /** Download a backup that was taken earlier (by hand or by the scheduler). */
    public function downloadStoredBackup(Backup $backup, BackupService $backups)
    {
        $download = $backups->download($backup);

        if (!$download) {
            return back()->with('error', 'That backup file is no longer where it was saved. It may have been moved or deleted.');
        }

        AuditLog::record('Downloaded Database Backup', 'backups', $backup->id);

        return $download;
    }

    /**
     * Take a backup and send it straight to the browser without keeping a copy.
     *
     * Kept because it is what the original button did, and a shop that just
     * wants a file on their laptop should not have to leave one on the server
     * as well. The dump itself is BackupService's - this used to be a second
     * copy of it, which is how the two drifted apart.
     */
    public function downloadBackup(BackupService $backups)
    {
        try {
            $path = $backups->dump();
        } catch (RuntimeException $e) {
            return back()->with('error', 'Failed to generate backup. ' . $e->getMessage());
        }

        AuditLog::record('Downloaded Database Backup', 'settings', 0);

        return response()->download($path)->deleteFileAfterSend(true);
    }

    // ----------------------------------------------------------- offsite backups

    /**
     * Write a small file to the offsite destination and delete it again.
     *
     * JSON rather than a redirect because the point is to answer *now*, while
     * the person is still looking at the credentials they just typed, without
     * throwing away the rest of the unsaved form. The disk and folder are taken
     * from the request so what is on screen is what gets tested - a shop should
     * not have to save a configuration to find out whether it works.
     */
    public function testOffsite(Request $request, BackupService $backups)
    {
        $data = $request->validate([
            'backup_offsite_disk' => $this->offsiteDiskRule(),
            'backup_offsite_path' => $this->offsitePathRule(),
        ]);

        $result = $backups->testConnection(
            $data['backup_offsite_disk'] ?? null,
            $data['backup_offsite_path'] ?? null
        );

        AuditLog::record(
            $result['ok'] ? 'Tested Offsite Backup' : 'Offsite Backup Test Failed',
            'settings',
            0
        );

        return response()->json($result);
    }

    /**
     * Try the uploads that are still owed, now, ignoring the backoff.
     *
     * The backoff exists so a nightly run does not hammer a dead endpoint; a
     * person pressing a button has usually just fixed something and should not
     * be told to wait six hours to find out.
     */
    public function uploadOffsiteNow(BackupService $backups)
    {
        if (!$backups->offsiteEnabled()) {
            return response()->json([
                'ok' => false,
                'message' => 'Offsite backup is switched off, so there is nowhere to upload to. '
                    . 'Switch it on and save, then try again.',
            ]);
        }

        $owed = $backups->pendingOffsiteCount();

        if ($owed === 0) {
            return response()->json([
                'ok' => true,
                'message' => 'Every backup already has an offsite copy. Nothing to upload.',
            ]);
        }

        $result = $backups->uploadPending(force: true, limit: $owed);

        AuditLog::record('Uploaded Backups Offsite', 'backups', 0);

        return response()->json([
            'ok' => $result['failed'] === 0,
            'message' => $result['failed'] === 0
                ? $result['uploaded'] . ' backup(s) uploaded and verified.'
                : $result['uploaded'] . ' uploaded, ' . $result['failed'] . ' still waiting. '
                    . 'The reason is on each row in the backup history.',
        ]);
    }

    /** Upload one particular backup's offsite copy, from the history list. */
    public function uploadBackupOffsite(Backup $backup, BackupService $backups)
    {
        if (!$backups->offsiteEnabled()) {
            return back()->with('error', 'Offsite backup is switched off, so there is nowhere to upload to.');
        }

        $backup = $backups->sendOffsite($backup)->fresh() ?? $backup;

        AuditLog::record('Uploaded Backup Offsite', 'backups', $backup->id);

        if (!$backup->isOffsite()) {
            return back()->with('error', 'That backup could not be uploaded: ' . $backup->offsite_error);
        }

        return back()->with(
            'success',
            "{$backup->filename} is now offsite ({$backup->offsite_size_for_humans})."
        );
    }

    public function exportCsv($table)
    {
        if (!array_key_exists($table, self::EXPORTS)) {
            abort(404);
        }

        AuditLog::record('Exported CSV', $table, 0);

        $filename = "{$table}_" . now()->format('Ymd_His') . '.csv';
        $columns = self::EXPORTS[$table];

        $callback = function () use ($table, $columns) {
            $handle = fopen('php://output', 'w');
            $wroteHeader = false;

            // cursor() streams rows instead of loading the whole table into memory.
            foreach (DB::table($table)->select($columns)->orderBy('id')->cursor() as $row) {
                $row = (array) $row;

                if (!$wroteHeader) {
                    fputcsv($handle, array_keys($row));
                    $wroteHeader = true;
                }

                // Neutralise spreadsheet formulas in user-entered text.
                fputcsv($handle, array_map(
                    fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v,
                    $row
                ));
            }

            if (!$wroteHeader) {
                fputcsv($handle, ['No data available']);
            }

            fclose($handle);
        };

        return Response::stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ---------------------------------------------------------------- internals

    /**
     * The whitelist, plus one key per sector feature.
     *
     * The feature overrides are derived from config/sectors.php rather than
     * listed here, so adding a trade or a flag to that config needs no change
     * in this controller - and the whitelist is still closed, because the keys
     * come from config and not from the request.
     */
    private function valueKeys(): array
    {
        return array_merge(
            self::VALUE_KEYS,
            array_map(fn ($name) => 'feature_' . $name, array_keys(config('sectors.features', [])))
        );
    }

    /**
     * Explicit rules for every key. Enums are whitelisted against the config
     * they are read back out of, so a saved value can never be one the rest of
     * the app does not understand.
     */
    private function rules(): array
    {
        $rules = [
            'store_name' => 'nullable|string|max:255',
            'store_logo' => MediaService::rules(1024),
            'remove_store_logo' => 'nullable|boolean',
            'store_address' => 'nullable|string|max:255',
            'store_phone' => 'nullable|string|max:255',
            'store_motto' => 'nullable|string|max:255',
            'store_email' => 'nullable|email|max:255',

            // Must be a key of config('sectors.types'): sector() falls back when
            // it is not, which would silently ignore what the shop chose.
            'business_type' => ['nullable', Rule::in(array_keys(config('sectors.types', [])))],

            'currency_symbol' => 'nullable|string|max:8',
            'currency_code' => 'nullable|alpha|size:3',
            // money() uses this as the number_format precision; anything outside
            // 0-4 is not a currency, it is a typo.
            'currency_decimals' => 'nullable|integer|min:0|max:4',

            'print_method' => 'nullable|in:dom,escpos',
            'printer_name' => 'nullable|string|max:255',
            'receipt_width' => ['nullable', Rule::in(array_keys(self::RECEIPT_WIDTHS))],
            'receipt_footer' => 'nullable|string|max:255',
            'receipt_tax_number' => 'nullable|string|max:50',

            // Digits only, no "+": wa.me takes a bare international number.
            'whatsapp_country_code' => 'nullable|digits_between:1,4',

            'theme' => 'nullable|in:light,dark,system',
            'low_stock_threshold' => 'nullable|integer|min:1',
            'auto_logout_time' => 'nullable|integer|min:1|max:1440',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'max_cashier_discount' => 'nullable|numeric|min:0|max:100',

            'backup_frequency' => 'nullable|in:daily,weekly',
            // 24-hour HH:MM, which is what Schedule::dailyAt() takes.
            'backup_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'backup_retention_days' => 'nullable|integer|min:0|max:3650',
            'backup_retention_count' => 'nullable|integer|min:0|max:1000',
            'backup_disk' => ['nullable', Rule::in(array_merge([BackupService::LOCAL], array_keys(config('filesystems.disks', []))))],
            'backup_path' => 'nullable|string|max:255',

            /*
             * Offsite backup. Every one of these has a default in
             * BackupService, so an install that has never opened this tab still
             * behaves sensibly - the defaults are listed with each rule.
             */

            // Which configured disk. Default "s3", which is the protocol rather
            // than the supplier (see config/filesystems.php).
            'backup_offsite_disk' => $this->offsiteDiskRule(),

            // The folder inside the bucket. Default "salespoint-backups".
            'backup_offsite_path' => $this->offsitePathRule(),

            /*
             * The key is write-only: blank means "keep the one already saved",
             * so there is no `required` to put here. 32 characters minimum
             * because a short key is the one failure nobody notices - the
             * backups still upload, still look encrypted, and are openable by
             * anybody with a word list. `salespoint:backup:key` generates 64.
             */
            'backup_encryption_key' => 'nullable|string|min:32|max:255',

            // Default 90 days. 0 keeps offsite copies for ever, which is a
            // legitimate choice when storage is cheap and history matters.
            'backup_offsite_retention_days' => 'nullable|integer|min:0|max:3650',

            // Who the report goes to. Empty falls back to the shop's own
            // address (store_email); several addresses may be separated by
            // commas, because "the owner and the bookkeeper" is the normal case.
            'backup_email_to' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
                foreach (preg_split('/[,;\s]+/', (string) $value) ?: [] as $address) {
                    if (trim($address) !== '' && !filter_var(trim($address), FILTER_VALIDATE_EMAIL)) {
                        $fail('"' . trim($address) . '" is not an email address.');
                    }
                }
            }],

            /*
             * Default 15 MB, and capped at 25: above that mail providers reject
             * or silently truncate, and a backup that stops arriving without
             * saying so is the worst failure this feature could have. The bucket
             * is the transport; email is the notification.
             */
            'backup_email_max_mb' => 'nullable|integer|min:1|max:25',
        ];

        /*
         * Each feature override has three states, which is why it is a select
         * and not a checkbox: '' follows the business type's preset, '1' forces
         * the feature on, '0' forces it off. See the feature() helper.
         */
        foreach (array_keys(config('sectors.features', [])) as $name) {
            $rules['feature_' . $name] = ['nullable', Rule::in(['', '0', '1'])];
        }

        return $rules;
    }

    /**
     * The offsite disk must be one that exists in config/filesystems.php.
     *
     * Unlike `backup_disk`, which falls back to a local folder when the name is
     * unknown, there is no safe fallback for an offsite destination: quietly
     * writing the shop's data somewhere other than where they said is worse
     * than refusing the value.
     *
     * @return array<int, mixed>
     */
    private function offsiteDiskRule(): array
    {
        return ['nullable', Rule::in(array_keys(config('filesystems.disks', [])))];
    }

    /**
     * A folder inside the bucket, and nothing clever.
     *
     * No leading slash, no "..", no backslashes, no drive letters: this string
     * is concatenated into an object key, and a path that climbs out of its
     * folder would scatter backups across somebody's bucket - or, on a local
     * disk used for testing, across their filesystem.
     *
     * @return array<int, mixed>
     */
    private function offsitePathRule(): array
    {
        return ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9._\-]*\/?)*[A-Za-z0-9._\-]*$/', 'not_regex:/\.\./'];
    }
}
