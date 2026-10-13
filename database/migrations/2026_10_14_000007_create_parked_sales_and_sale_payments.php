<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two tables the till needs to finish the job: split payments and held carts.
 *
 * `sale_payments` is one row per tender, so a sale can be half cash and half
 * transfer. `sales.payment_method` stays populated with the dominant tender
 * (every existing report and filter reads it), but the drawer figures come from
 * here. ShiftService::salesByMethod() already prefers these rows over that
 * column, which is why the three column names `sale_id`, `method` and `amount`
 * are a contract: rename one and the end-of-day reconciliation goes quiet rather
 * than wrong, which is worse.
 *
 * An amount here is money *kept*, not money handed over: a 215 sale paid with a
 * 300 note writes a cash row of 215 and leaves the 85 in `sales.change_due`.
 * A drawer holds what it kept, so that is the only reading that reconciles.
 *
 * `parked_sales` holds a cart that was put aside: the lines as JSON rather than
 * as rows, because a held cart is not a sale and must not appear in any sales
 * figure, and because nothing joins to it. Stock is deliberately not reserved -
 * see ParkedSaleController::resume(), which re-checks availability instead.
 *
 * It also adds the two `sales` columns that hold a tip or service charge, which
 * is the third thing the till now has to record. See the comment on them below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            // Cascades: the tenders are part of the sale, not a record of their own.
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20);                   // cash|pos|transfer
            $table->decimal('amount', 15, 2);
            $table->string('reference')->nullable();        // card slip, transfer ref
            $table->timestamps();

            // The shift report groups by method over a shift's sales.
            $table->index('method');
        });

        Schema::create('parked_sales', function (Blueprint $table) {
            $table->id();
            // Short and readable: a cashier reads it back off the screen to find the cart again.
            $table->string('reference')->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            // The session cart and its order-level discount, exactly as the till held them.
            $table->json('cart');
            $table->json('discount')->nullable();
            $table->string('note')->nullable();
            /*
             * Both of these are for the list screen only, so it can show "3 items,
             * 4,500" without rebuilding every held cart. Prices are re-read from the
             * catalogue on resume, so a stale figure here can never reach a sale.
             */
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamps();

            // "My held sales, newest first" is the only query this table serves.
            $table->index(['user_id', 'created_at']);
        });

        /*
         * A tip or service charge, which table service hands to the till in the
         * session and checkout adds on top of the bill.
         *
         * It has to be stored, not derived: the receipt is printed again later
         * from this row alone, and a guest is entitled to see the charge named
         * and itemised rather than folded silently into the total. It sits on no
         * sale line - a service charge is not a thing that was sold - which is
         * also why a line refund never gives it back.
         *
         * The label ("Service charge (10%)") is stored as it was quoted, so a
         * reprint says what the guest agreed to even if the rate has changed
         * since.
         */
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('service_charge', 15, 2)->default(0)->after('discount_value');
            $table->string('service_charge_label', 60)->nullable()->after('service_charge');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['service_charge', 'service_charge_label']);
        });

        Schema::dropIfExists('parked_sales');
        Schema::dropIfExists('sale_payments');
    }
};
