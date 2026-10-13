<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier shifts and end-of-day till reconciliation.
 *
 * A shift is the unit a drawer is reconciled over: one cashier opens it with a
 * cash float, rings up sales, then counts the drawer and closes it. Sales and
 * refunds point back at the shift they happened in so the expected contents of
 * the drawer can be worked out from the records rather than from memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();           // whose drawer this is
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            // Cash put in the drawer at the start so there is change to give.
            $table->decimal('opening_float', 15, 2)->default(0);

            /*
             * What the cashier actually counted, per tender. Null while the shift is
             * open: nothing has been counted yet, which is not the same as counting zero.
             */
            $table->decimal('counted_cash', 15, 2)->nullable();
            $table->decimal('counted_pos', 15, 2)->nullable();
            $table->decimal('counted_transfer', 15, 2)->nullable();

            /*
             * What the records say the drawer should hold, frozen at the moment of
             * closing. These are also computed live (ShiftService::expectedByMethod)
             * while the shift is open, so why store them at all? Because a
             * reconciliation is signed off against the figures shown at the time.
             * If a sale is later backdated into a closed shift, voided, or refunded,
             * the live figure moves and a shift that balanced would silently start
             * showing a variance - or, worse, a real shortage would quietly
             * disappear. The stored copy is the audit trail; the live figure is only
             * ever a view. Keeping both also makes a late edit visible: the two
             * disagreeing is itself a finding worth looking at.
             */
            $table->decimal('expected_cash', 15, 2)->default(0);
            $table->decimal('expected_pos', 15, 2)->default(0);
            $table->decimal('expected_transfer', 15, 2)->default(0);

            $table->text('note')->nullable();        // written when opening
            $table->text('close_note')->nullable();  // the cashier's explanation of a variance

            $table->string('status', 10)->default('open')->index(); // open|closed

            // Who signed it off: a manager may force-close someone else's drawer.
            $table->foreignId('closed_by')->nullable()->constrained('users');

            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table) {
            // Nullable: sales rung up before shifts existed have no shift, and the
            // till is allowed to sell without one unless the shop requires it.
            $table->foreignId('shift_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index('shift_id');
        });

        Schema::table('refunds', function (Blueprint $table) {
            // A cash refund hands money back out of the drawer, so it has to be
            // attributed to the shift that paid it, not to the shift that sold the goods.
            $table->foreignId('shift_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        // SQLite will not drop an indexed column while the index still exists,
        // so each index goes before the column it covers.
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex(['shift_id']);
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['shift_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
        });

        Schema::dropIfExists('shifts');
    }
};
