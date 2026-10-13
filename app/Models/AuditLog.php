<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'table_name',
        'record_id',
        'ip_address',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record an audit entry for the current user, honouring the
     * "Enable Audit Logs" setting (on unless explicitly switched off).
     */
    public static function record(string $action, string $table, int $recordId = 0): ?self
    {
        if (setting('enable_audit_log', '1') !== '1' || !auth()->check()) {
            return null;
        }

        return static::create([
            'user_id'    => auth()->id(),
            'action'     => $action,
            'table_name' => $table,
            'record_id'  => $recordId,
            'ip_address' => request()->ip(),
        ]);
    }
}
