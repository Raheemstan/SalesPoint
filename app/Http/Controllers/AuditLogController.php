<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Response;

/**
 * Reading the audit log.
 *
 * The log has been written from all over the app since the beginning, and until
 * now there was no screen that could read it - which makes it a table that
 * costs a row per action and answers no questions.
 *
 * Two things this has to get right:
 *
 *  - Every row names a user, so the list eager-loads the relation. Rendering 50
 *    rows that each fetch their own user is 51 queries and it only gets worse as
 *    the log grows.
 *  - The CSV must carry the user's name, not their record. Joining to `users`
 *    with `select *` would put password hashes in a spreadsheet that gets
 *    emailed around, so the columns are listed explicitly.
 */
class AuditLogController extends Controller
{
    /** Columns of the CSV, as [heading => expression]. Nothing else is selected. */
    private const CSV_COLUMNS = [
        'id' => 'audit_logs.id',
        'when' => 'audit_logs.created_at',
        'user' => 'users.name',
        'role' => 'users.role',
        'action' => 'audit_logs.action',
        'table' => 'audit_logs.table_name',
        'record_id' => 'audit_logs.record_id',
        'ip_address' => 'audit_logs.ip_address',
    ];

    public function index(Request $request)
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filters = $this->filters($request);

        $logs = $this->query($filters)
            ->with('user:id,name,role')   // no N+1, and only the columns the list shows
            ->latest('id')                // newest first; id breaks ties within a second
            ->paginate(50)
            ->withQueryString();

        return view('settings.audit', [
            'logs' => $logs,
            'filters' => $filters,
            'users' => User::withTrashed()->orderBy('name')->get(['id', 'name']),
            // Built from what is actually in the log rather than a hardcoded
            // list: every feature that calls AuditLog::record() shows up here
            // without anyone remembering to add it.
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'tables' => AuditLog::query()->distinct()->orderBy('table_name')->pluck('table_name'),
        ]);
    }

    public function exportCsv(Request $request)
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filters = $this->filters($request);

        AuditLog::record('Exported Audit Log', 'audit_logs', 0);

        /*
         * toBase(): the export wants rows, not models. Streaming Eloquent models
         * and casting each one to an array drags its internal state along with
         * the attributes, and the columns here come from a join anyway.
         */
        $rows = $this->query($filters)
            ->toBase()
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->select(array_values(self::CSV_COLUMNS))
            ->orderBy('audit_logs.id');

        $filename = 'audit-log_' . now()->format('Ymd_His') . '.csv';

        $callback = function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_keys(self::CSV_COLUMNS));

            // cursor() streams rows instead of loading the whole log into memory.
            foreach ($rows->cursor() as $row) {
                // Same neutralisation as SettingController::exportCsv(): a value
                // starting with = or + is a formula to Excel, and action text
                // comes from the app while IPs and names come from outside it.
                fputcsv($handle, array_map(
                    fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v,
                    (array) $row
                ));
            }

            fclose($handle);
        };

        return Response::stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** The filters, validated once and reused by the list and the export. */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'action' => 'nullable|string|max:255',
            'table_name' => 'nullable|string|max:255',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        return [
            'user_id' => $data['user_id'] ?? null,
            'action' => $data['action'] ?? null,
            'table_name' => $data['table_name'] ?? null,
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
        ];
    }

    private function query(array $filters)
    {
        return AuditLog::query()
            ->when($filters['user_id'], fn ($q, $id) => $q->where('audit_logs.user_id', $id))
            ->when($filters['action'], fn ($q, $action) => $q->where('audit_logs.action', $action))
            ->when($filters['table_name'], fn ($q, $table) => $q->where('audit_logs.table_name', $table))
            ->when($filters['from'], fn ($q, $from) => $q->where('audit_logs.created_at', '>=', Carbon::parse($from)->startOfDay()))
            // endOfDay, not the bare date: "to 14 October" has to include the
            // 14th, which a plain <= comparison against midnight does not.
            ->when($filters['to'], fn ($q, $to) => $q->where('audit_logs.created_at', '<=', Carbon::parse($to)->endOfDay()));
    }
}
