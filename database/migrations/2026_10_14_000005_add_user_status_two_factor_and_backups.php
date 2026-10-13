<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Administration: user status, optional two-factor, and a record of backups.
 *
 * Three gaps this closes:
 *
 *  - The only way to stop someone signing in was to delete them, which took
 *    their sales history with them. `users.is_active` is the replacement:
 *    the account stays, the login does not. Indexed because the login flow and
 *    every request check it.
 *
 *  - Two-factor had nowhere to live. The secret and the recovery codes are
 *    stored encrypted by the model (see the `encrypted` casts on App\Models\User),
 *    so these are text columns, not short strings - ciphertext is much longer
 *    than the 32-character secret inside it. `two_factor_confirmed_at` is what
 *    makes 2FA *active*: a secret on its own is only a half-finished setup, and
 *    locking someone out of their own account with an unverified secret is the
 *    one failure mode a 2FA feature must not have.
 *
 *  - Backups were a button and nothing else: no record of whether last night's
 *    run worked. Every run now writes a row, successful or not, so the failure
 *    is visible before it matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role')->index();
            $table->timestamp('last_login_at')->nullable()->after('is_active');

            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });

        /*
         * The column default only applies to rows inserted from now on. Everyone
         * who already has an account could sign in yesterday and must still be
         * able to today, so say so explicitly.
         */
        DB::table('users')->update(['is_active' => true]);

        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('filename');

            // Where the file actually ended up: a path inside `disk`, or an
            // absolute path when the shop pointed the backup at another drive.
            $table->string('path');
            $table->string('disk', 30);
            $table->string('driver', 20);   // sqlite|mysql|mariadb - what was dumped

            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('status', 12)->default('success'); // success|failed

            // Why it failed, in the shop's own words rather than in the log file
            // nobody reads. Null on success.
            $table->text('error')->nullable();

            // Null when the scheduler ran it: there is no user behind a cron job.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('created_at'); // the history list and retention both sort on it
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');

        // SQLite will not drop an indexed column while the index still exists.
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_active',
                'last_login_at',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
