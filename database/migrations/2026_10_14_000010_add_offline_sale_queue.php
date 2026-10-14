<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline sales: the two things the server needs before a till can queue a sale
 * on the device and replay it later.
 *
 * WHY A UNIQUE INDEX AND NOT A CHECK IN PHP
 *
 * `sales.client_uuid` is the id the *device* gives a sale at the moment the
 * cashier takes the money, before any server has seen it. Replaying a queue is
 * not an exceptional event - it is the normal path, and it happens again every
 * time a response is lost on the way back to a till on a bad connection. So the
 * same sale will arrive twice, and sometimes twice at once from two tabs or two
 * devices sharing a login.
 *
 * The unique index is the *entire* defence against banking that sale twice.
 * A `SELECT ... WHERE client_uuid = ?` followed by an insert is not a defence:
 * two requests can both run the select before either inserts, and both will
 * find nothing. Only the database can decide which of two concurrent inserts
 * wins, so the sync endpoint inserts and catches the violation rather than
 * asking first (see OfflineSyncService::replay). Double-banking a sale means
 * charging a customer twice and selling stock the shop does not have, so this
 * index is load-bearing: do not drop it to "fix" a sync error.
 *
 * It is nullable because almost no sale has one. A sale rung up online never
 * leaves the server's hands and needs no client id, and both MySQL and SQLite
 * allow any number of NULLs in a unique index, so those rows do not collide
 * with each other.
 *
 * `synced_at` is when the server banked it, which is deliberately NOT when it
 * was sold: `sale_date` and `created_at` carry the moment the cashier rang it
 * up. The gap between the two is the outage, and a shop wants to see it.
 *
 * WHY THE ATTEMPTS TABLE EXISTS
 *
 * An offline sale can arrive unacceptable - the customer has gone over their
 * credit limit since, the discount is above what that cashier may give, the
 * product has been deleted outright. Those are refused. Without a record of the
 * refusal, a sale that a cashier took real money for simply vanishes: nobody
 * knows it existed, what was in it, or why it was turned away. That is not
 * acceptable for money, so every verdict - accepted, rejected or duplicate - is
 * written here with the payload exactly as it arrived and, for a refusal, the
 * reason in the words the cashier will be shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            /*
             * 36 chars: the canonical hyphenated UUID the browser's
             * crypto.randomUUID() produces. Stored as a string rather than
             * binary because it is read by humans off a till screen when a
             * cashier is chasing a sale that did not arrive.
             */
            $table->string('client_uuid', 36)->nullable()->unique()->after('invoice_number');

            // When the server banked it. Null for every sale taken online.
            $table->timestamp('synced_at')->nullable()->after('sale_date');
        });

        Schema::create('offline_sale_attempts', function (Blueprint $table) {
            $table->id();

            /*
             * Indexed, not unique: one queued sale can legitimately produce
             * several rows - a rejection, then an accepted replay once the
             * manager has raised the credit limit. The history is the point.
             */
            $table->string('client_uuid', 36)->index();

            // Who posted the batch. Not necessarily who rang the sale up - that
            // is sales.user_id, taken from the payload (see OfflineSyncService).
            $table->foreignId('user_id')->constrained();

            // The queued sale exactly as it arrived, so a refusal can be read
            // back, understood and re-rung by hand if it has to be.
            $table->json('payload');

            // accepted | rejected | duplicate. Indexed because "what still needs
            // attention?" is the one query the till asks on every page load.
            $table->string('status', 12)->index();

            // Why it was refused, in the cashier's words. Null when accepted.
            $table->text('reason')->nullable();

            /*
             * The sale it became. Set for `accepted`, and also for `duplicate` -
             * pointing at the sale the first attempt banked, which is what makes
             * a duplicate verdict useful rather than just a shrug.
             */
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();

            // When the batch reached the server, as against when it was rung up.
            $table->timestamp('received_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sale_attempts');

        /*
         * SQLite cannot drop a column that an index still points at, and it does
         * not drop the index for you, so the index goes first and in its own
         * statement batch.
         */
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_client_uuid_unique');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['client_uuid', 'synced_at']);
        });
    }
};
