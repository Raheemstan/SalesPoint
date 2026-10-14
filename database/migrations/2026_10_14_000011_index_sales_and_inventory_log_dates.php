<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes on the two date columns every report filters by.
 *
 * `sales` is the largest table in a point of sale and every report narrows it
 * with a `created_at` range, yet the column was never indexed - while every
 * table added since (`refunds`, `stock_adjustments`, `customer_payments`,
 * `supplier_payments`, `purchase_returns`) does index it. That reads like an
 * oversight in the original schema rather than a decision, and it is the
 * difference between a dashboard that opens instantly and one that scans the
 * whole sales history on every tile.
 *
 * `inventory_logs` has the same problem: the movement report is a date range
 * over the table that grows fastest of all, because every line of every sale,
 * purchase, refund and adjustment writes a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->index('created_at');
        });

        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
