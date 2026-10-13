<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Setting;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Http\Request;
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
    ];

    /** Checkbox settings: an unchecked box is simply absent from the request. */
    private const FLAG_KEYS = [
        'show_logo', 'show_business_info', 'enable_low_stock_warning', 'enable_audit_log',
        'receipt_show_logo', 'receipt_show_tax_number',
        'require_2fa_admins', 'require_open_shift',
        'backup_enabled',
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
        $settings = setting();

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

        // Only known keys are stored (the old code saved every request field),
        // and settings.value is NOT NULL, so empty fields become ''.
        foreach ($this->valueKeys() as $key) {
            if ($request->exists($key)) {
                Setting::set($key, $request->input($key) ?? '');
            }
        }

        foreach (self::FLAG_KEYS as $key) {
            Setting::set($key, $request->boolean($key) ? '1' : '0');
        }

        if ($request->hasFile('store_logo')) {
            Setting::set('store_logo', $request->file('store_logo')->store('logos', 'public'));
        }

        return redirect()->route('settings.index')->with('success', 'Settings updated successfully.');
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
            'store_logo' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
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
}
