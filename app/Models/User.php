<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        // Their home branch: where they are put when they sign in. Assigning it
        // is an admin's job, like `role` - see App\Http\Controllers\UserController.
        'branch_id',
    ];

    public const ROLES = ['admin', 'manager', 'cashier'];

    /**
     * `users.role` and the Spatie roles used by route middleware must never
     * disagree, so every save of the column re-syncs the Spatie role.
     */
    protected static function booted(): void
    {
        static::saved(function (User $user) {
            if ($user->wasRecentlyCreated || $user->wasChanged('role')) {
                $role = $user->role ?? 'cashier';
                Role::findOrCreate($role, 'web');
                $user->syncRoles([$role]);
            }
        });
    }


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // A 2FA secret is a second password: it must never reach a response, a
        // CSV or a log line, so it is hidden from serialisation as well as
        // encrypted at rest.
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',

            /*
             * Encrypted, not hashed: both have to be read back. The secret is
             * needed to recompute the expected code, and recovery codes are
             * shown to the user once when they are generated. A dumped database
             * on its own therefore does not hand over anyone's second factor.
             */
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Two-factor is only *on* once a code has been typed back correctly. A
     * secret with no confirmation is an abandoned setup, and treating it as
     * active would lock the user out of their own account.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** Deactivated accounts keep their history but cannot sign in (see EnsureUserIsActive). */
    public function isActive(): bool
    {
        // Rows written before the column existed read as null; treat those as active.
        return $this->is_active === null || (bool) $this->is_active;
    }

    /**
     * Is this the only admin left? Used by every guard rail in user management:
     * demoting, deactivating or deleting the last admin locks the shop out of
     * its own settings, and only a developer could undo it.
     *
     * @param  bool  $activeOnly  count only admins who can actually sign in
     */
    public function isLastAdmin(bool $activeOnly = false): bool
    {
        if ($this->role !== 'admin') {
            return false;
        }

        return static::query()
            ->where('role', 'admin')
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->whereKeyNot($this->getKey())
            ->doesntExist();
    }

    public function backups()
    {
        return $this->hasMany(Backup::class, 'created_by');
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Branches
    |--------------------------------------------------------------------------
    |
    | Two relations, because there are two different facts. `branch` is where
    | somebody normally works - it decides which shop they land in when they sign
    | in. `branches` is the pivot for people who genuinely work at several: a
    | relief cashier, a manager doing the rounds.
    |
    | A single-site shop never sees any of this: the migration puts everyone at
    | the one branch and the switcher renders nothing for a user with one choice.
    */

    /** Their home branch. */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** The other shops they are allowed to work at. */
    public function branches()
    {
        return $this->belongsToMany(Branch::class)->withTimestamps();
    }

    /**
     * May this person act as this branch?
     *
     * THE RULE, AND THE REASONING
     *
     * A cashier may use their home branch and any branch they are assigned to,
     * and nothing else. That is the control that matters: a till operator must
     * not be able to read another shop's takings, void another shop's sales or
     * sell off another shop's shelf by picking it out of a dropdown - and must
     * not be able to do it by editing a request either, which is why this is
     * checked on every request rather than only when the switcher is drawn.
     *
     * Admins and managers may use any open branch. They are the people who run
     * the business across sites: a manager who cannot look at the other shop
     * cannot do the job the reports exist for, and an admin can already change
     * any figure in the system, so pinning them would be theatre rather than
     * security.
     *
     * (A group large enough to want *branch* managers - pinned to one site and
     * unable to see the rest - wants a fourth role rather than a change here;
     * assigning them as a cashier-shaped user would take away the manager
     * screens they need.)
     *
     * A closed branch is nobody's, including an admin's: it is closed.
     */
    public function canUseBranch(Branch $branch): bool
    {
        if (!$branch->is_active) {
            return false;
        }

        if ($this->hasAnyRole(['admin', 'manager'])) {
            return true;
        }

        if ($this->branch_id !== null && (int) $this->branch_id === (int) $branch->getKey()) {
            return true;
        }

        return $this->branches()->whereKey($branch->getKey())->exists();
    }

    /**
     * Every branch this person may switch between, in switcher order.
     *
     * One query. The header renders nothing when this holds fewer than two, so
     * a single-site shop and a cashier tied to one till both see no switcher at
     * all.
     *
     * @return \Illuminate\Support\Collection<int, Branch>
     */
    public function availableBranches(): \Illuminate\Support\Collection
    {
        if ($this->hasAnyRole(['admin', 'manager'])) {
            return Branch::query()->active()->ordered()->get();
        }

        return Branch::query()
            ->active()
            ->where(fn ($query) => $query
                ->whereKey($this->branch_id)
                ->orWhereHas('staff', fn ($staff) => $staff->whereKey($this->getKey())))
            ->ordered()
            ->get();
    }
}
