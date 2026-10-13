<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splits a refund into the part that cancelled a debt and the part that was
 * actually handed over.
 *
 * Refunding a sale that was sold on credit does not necessarily mean money
 * leaves the till. If a customer owes 600 on a 1,000 invoice and brings back
 * 300 of goods, nothing is paid out: they simply owe 300 instead of 600. Only
 * once the returned value exceeds what they still owe does real money come out
 * of the drawer.
 *
 * Without this column the till reconciliation would treat the whole refund as
 * cash paid out and every such shift would look short by the amount of debt
 * cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->decimal('credit_applied', 15, 2)->default(0)->after('total_amount');
        });

        // Existing refunds all predate credit sales, so none of them cancelled a
        // debt: every penny was handed back. The default of 0 is already correct.
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn('credit_applied');
        });
    }
};
