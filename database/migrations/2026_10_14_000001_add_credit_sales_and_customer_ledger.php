<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Selling on credit, and the ledger that keeps track of what is owed.
 *
 * A sale now records how much of it is still unpaid (`balance_due`) and when it
 * falls due. The money that comes in later is stored in two tables rather than
 * one: `customer_payments` is the cash/transfer that actually arrived, and
 * `customer_payment_allocations` says which invoices it settled. Splitting them
 * is what makes one payment across three invoices auditable, and what lets a
 * payment be reversed without guessing which invoices it had touched.
 */
return new class extends Migration
{
    /**
     * Making `customers.phone` nullable makes SQLite rebuild the table, and the
     * foreign-key pragma Laravel toggles around that rebuild is ignored inside a
     * transaction - which would make the rebuild fail on an install that already
     * has sales pointing at customers. So this migration runs unwrapped.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // How much of the money has been collected: paid|partial|unpaid.
            // Separate from `status`, which tracks the refund lifecycle.
            $table->string('payment_status', 20)->default('paid')->index()->after('void_reason');
            // Still owed on this sale. Kept on the row (rather than derived) so the
            // receivables list and the till's credit-limit check are one cheap query.
            $table->decimal('balance_due', 15, 2)->default(0)->after('payment_status');
            $table->date('due_date')->nullable()->after('balance_due');
        });

        // Every sale made before credit existed was paid in full at the till.
        // Without this the whole history would suddenly read as unpaid debt.
        DB::table('sales')->update(['payment_status' => 'paid', 'balance_due' => 0]);

        Schema::table('customers', function (Blueprint $table) {
            // 0 means "no limit": a shop that does not want to think about limits
            // should not have to set one per customer.
            $table->decimal('credit_limit', 15, 2)->default(0)->after('address');
            // Customers are deactivated, never deleted: their invoices must survive.
            $table->boolean('is_active')->default(true)->after('credit_limit');
            $table->text('notes')->nullable()->after('is_active');
        });

        Schema::table('customers', function (Blueprint $table) {
            // A walk-in who buys on account often leaves only a name.
            $table->string('phone')->nullable()->change();
        });

        // The money that actually arrived.
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('user_id')->constrained();   // who took it
            $table->decimal('amount', 15, 2);
            $table->string('method', 20);                  // cash|pos|transfer
            $table->string('reference')->nullable();       // teller slip, transfer ref
            $table->string('note')->nullable();
            $table->timestamp('paid_at');                   // when the customer paid, not when it was keyed in
            $table->timestamps();

            $table->index('created_at');
        });

        // Which invoices that money settled. Cascades with its payment, so
        // reversing a payment cannot leave allocations behind.
        Schema::create('customer_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_allocations');
        Schema::dropIfExists('customer_payments');

        // An indexed column has to lose its index before it can be dropped (SQLite refuses otherwise).
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['payment_status']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'balance_due', 'due_date']);
        });

        // phone goes back to NOT NULL, so the rows that used the new freedom need a value.
        DB::table('customers')->whereNull('phone')->update(['phone' => '']);

        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone')->nullable(false)->change();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['credit_limit', 'is_active', 'notes']);
        });
    }
};
