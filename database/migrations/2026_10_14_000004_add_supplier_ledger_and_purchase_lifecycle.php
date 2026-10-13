<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers get a ledger, purchases get a lifecycle.
 *
 * Two gaps this closes:
 *
 *  - Nothing recorded what the shop owes a supplier. A purchase was money that
 *    vanished the moment it was typed in. `suppliers.opening_balance`, the
 *    payments table and `purchases.amount_paid` together make the debt visible
 *    (see App\Services\SupplierLedgerService for how the balance is derived).
 *
 *  - A purchase was always "done": stock moved the instant it was saved, and a
 *    mistyped delivery was permanent. `purchases.status` separates ordering from
 *    receiving (stock only moves on receipt), and the returns tables let goods go
 *    back to the supplier instead of being deleted out of existence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // Archive a supplier you no longer buy from: deleting one with
            // purchase history would take the history with it.
            $table->boolean('is_active')->default(true)->after('address');

            // What was already owed when the shop started using SalesPoint.
            $table->decimal('opening_balance', 15, 2)->default(0)->after('is_active');

            $table->text('notes')->nullable()->after('opening_balance');
        });

        // Money paid to a supplier against their account as a whole. Not tied to
        // one invoice: shops pay a round figure off a running balance.
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->decimal('amount', 15, 2);
            $table->string('method', 20);            // cash|transfer|pos|cheque
            $table->string('reference')->nullable(); // teller number, cheque number
            $table->string('note')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::table('purchases', function (Blueprint $table) {
            // ordered   = raised with the supplier, nothing delivered, no stock moved
            // received  = goods are in; stock has been added
            // cancelled = abandoned; any stock it had added has been taken back out
            $table->string('status', 12)->default('received')->after('total_amount')->index();

            // Paid when the purchase was recorded (cash on delivery, a deposit).
            // Later payments live in supplier_payments, against the account.
            $table->decimal('amount_paid', 15, 2)->default(0)->after('status');
            $table->decimal('balance_due', 15, 2)->default(0)->after('amount_paid');

            $table->string('reference')->nullable()->after('note'); // the supplier's own document number
            $table->date('expected_at')->nullable()->after('reference');
            $table->timestamp('received_at')->nullable()->after('expected_at');
        });

        /*
         * Every purchase that already exists added its stock on the day it was
         * typed in and was treated as settled. Leaving the defaults would make the
         * whole history look ordered-but-undelivered and unpaid overnight, so say
         * so explicitly instead.
         */
        DB::statement('UPDATE purchases SET status = ?, received_at = purchase_date, amount_paid = total_amount, balance_due = 0', ['received']);

        Schema::table('purchase_items', function (Blueprint $table) {
            // How much of this line has gone back to the supplier.
            $table->unsignedInteger('returned_quantity')->default(0)->after('total');
        });

        // Goods sent back: damaged, wrong item, over-delivery.
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained();
            // Denormalised from the purchase so the supplier's statement is one query.
            $table->foreignId('supplier_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('reference');
            $table->decimal('total_amount', 15, 2);
            $table->string('reason');
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->unsignedInteger('quantity');
            // What the goods cost on the purchase line, not what the product costs
            // today: the supplier credits back what they charged.
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn('returned_quantity');
        });

        // SQLite will not drop an indexed column while the index still exists.
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['status', 'amount_paid', 'balance_due', 'reference', 'expected_at', 'received_at']);
        });

        Schema::dropIfExists('supplier_payments');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'opening_balance', 'notes']);
        });
    }
};
