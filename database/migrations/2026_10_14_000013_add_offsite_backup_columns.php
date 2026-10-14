<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offsite backups: where the copy went, whether it got there, and the proof.
 *
 * Until now a backup was a file next to the database. A stolen till, a dead
 * disk, a fire or a ransomware run takes the backup with it, so the whole
 * trading history of the business lives or dies with one machine. These columns
 * are the record of the second copy - the one that is somewhere else.
 *
 * Why so many of them:
 *
 *  - `offsite_status` has to distinguish "we have not tried", "we tried and the
 *    network was down" (retry tonight) and "we tried and the credentials were
 *    refused" (nobody is coming to fix that by itself). Retention reads it too:
 *    a local backup whose offsite copy is still owed must never be deleted,
 *    because for a moment it is the only copy there is.
 *
 *  - `offsite_size_bytes` and `offsite_checksum` are what turns "the upload
 *    returned no error" into "the object is there and it is the right length".
 *    An upload that half-finished and reported success is the failure mode a
 *    backup system cannot have. The checksum is of the compressed, encrypted
 *    object, so a restore can prove the download before trying to decrypt it.
 *
 *  - `local_pruned_at` lets the row outlive its local file. Local retention is
 *    usually days and offsite retention months; without this the row - and with
 *    it any memory of the remote object - would be deleted first, and nothing
 *    would ever clean the bucket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            // off|pending|uploaded|failed|expired - see App\Models\Backup.
            $table->string('offsite_status', 12)->default('off')->after('error');

            $table->string('offsite_disk', 30)->nullable()->after('offsite_status');
            $table->string('offsite_path')->nullable()->after('offsite_disk');

            // The uploaded object, which is the compressed and encrypted dump -
            // not the same size as size_bytes, which is the raw local copy.
            $table->unsignedBigInteger('offsite_size_bytes')->nullable()->after('offsite_path');
            $table->string('offsite_checksum', 64)->nullable()->after('offsite_size_bytes');
            $table->boolean('offsite_encrypted')->default(false)->after('offsite_checksum');

            $table->text('offsite_error')->nullable()->after('offsite_encrypted');

            // How many goes it has had, which is what the backoff is worked out
            // from, and when the next one is due.
            $table->unsignedInteger('offsite_attempts')->default(0)->after('offsite_error');
            $table->timestamp('offsite_next_attempt_at')->nullable()->after('offsite_attempts');
            $table->timestamp('offsite_uploaded_at')->nullable()->after('offsite_next_attempt_at');

            // Set when retention removed the local file but the row is being
            // kept for its offsite copy.
            $table->timestamp('local_pruned_at')->nullable()->after('offsite_uploaded_at');

            // Every run asks "what is still waiting to go offsite?".
            $table->index('offsite_status');
        });
    }

    public function down(): void
    {
        // SQLite will not drop an indexed column while the index still exists,
        // so the index goes in its own statement first.
        Schema::table('backups', function (Blueprint $table) {
            $table->dropIndex(['offsite_status']);
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn([
                'offsite_status',
                'offsite_disk',
                'offsite_path',
                'offsite_size_bytes',
                'offsite_checksum',
                'offsite_encrypted',
                'offsite_error',
                'offsite_attempts',
                'offsite_next_attempt_at',
                'offsite_uploaded_at',
                'local_pruned_at',
            ]);
        });
    }
};
