<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class SettingController extends Controller
{
    public function index()
    {
        $settings = setting(null);
        $users = User::all();
        return view('settings.index', compact('settings', 'users'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'store_name' => 'nullable|string|max:255',
            'store_logo' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
            'store_address' => 'nullable|string|max:255',
            'store_phone' => 'nullable|string|max:255',
            'receipt_logo_enabled' => 'nullable|in:1,0',
            'print_method' => 'nullable|in:dom,escpos',
            'theme' => 'nullable|in:light,dark,system',
            'audit_log_enabled' => 'nullable|in:1,0',
            'low_stock_warning' => 'nullable|in:1,0',
            'stock_threshold' => 'nullable|numeric|min:1',
            'auto_logout_minutes' => 'nullable|numeric|min:1'
        ]);

        foreach ($request->except(['store_logo', '_token']) as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
        }

        // Handle logo upload
        if ($request->hasFile('store_logo')) {
            $logo = $request->file('store_logo')->store('logos', 'public');
            Setting::updateOrCreate(
                ['key' => 'store_logo'],
                ['value' => $logo]
            );
        }

        return back()->with('success', 'Settings updated successfully.');
    }

    public function createUser(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6|confirmed'
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        AuditLog::create([
            'user_id' => userId(),
            'action' => 'Created User',
            'table_name' => 'users',
            'record_id' => $user->id,
            'ip_address' => request()->ip(),
        ]);

        return back()->with('success', 'User created successfully.');
    }

    public function deleteUser($id)
    {
        DB::beginTransaction();
        try {
            AuditLog::create([
                'user_id' => userId(),
                'action' => 'Deleted User',
                'table_name' => 'users',
                'record_id' => $id,
                'ip_address' => request()->ip(),
            ]);
            User::where('id', $id)->delete();
            DB::commit();
            return back()->with('success', 'User deleted successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to delete user.');
        }
    }


    public function downloadBackup()
    {
        $filename = 'backup-' . date('Y-m-d_H-i-s') . '.sql';

        AuditLog::create([
            'user_id' => userId(),
            'action' => 'Downloaded Database Backup',
            'table_name' => 'settings',
            'record_id' => 0,
            'ip_address' => request()->ip(),
        ]);
        $command = sprintf(
            'mysqldump -u%s -p%s %s > %s',
            env('DB_USERNAME'),
            env('DB_PASSWORD'),
            env('DB_DATABASE'),
            storage_path("app/{$filename}")
        );

        $exitCode = null;
        @exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            return back()->with('error', 'Failed to generate backup. Check server permissions.');
        }

        return response()->download(storage_path("app/{$filename}"))->deleteFileAfterSend(true);
    }

    public function exportCsv($table)
    {
        $valid = ['products', 'sales', 'users'];
        if (!in_array($table, $valid)) {
            abort(404);
        }

        AuditLog::create([
            'user_id' => userId(),
            'action' => 'Exported CSV',
            'table_name' => $table,
            'record_id' => 0,
            'ip_address' => request()->ip(),
        ]);

        $filename = "{$table}_" . now()->format('Ymd_His') . ".csv";

        $callback = function () use ($table) {
            $handle = fopen('php://output', 'w');

            $data = DB::table($table)->get();
            if ($data->isEmpty()) {
                fputcsv($handle, ['No data available']);
            } else {
                fputcsv($handle, array_keys((array) $data->first()));
                foreach ($data as $row) {
                    fputcsv($handle, (array) $row);
                }
            }

            fclose($handle);
        };

        return Response::stream($callback, 200, [
            "Content-Type" => "text/csv",
            "Content-Disposition" => "attachment; filename={$filename}",
        ]);
    }
}
