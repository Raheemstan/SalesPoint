<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table service: the restaurant / bar / cafe sale flow.
 *
 * A till sale is one event - ring it up, take the money, done. A restaurant sale
 * is a conversation: the order is opened against a table when the guests sit
 * down, added to in rounds through the meal, sent to the kitchen in batches, and
 * only turned into money at the end. None of that fits `sales`, which has no
 * concept of a sale that is still being written, so it gets its own three tables
 * and only becomes a `sale` at the moment it is settled (table_orders.sale_id).
 *
 * Nothing here moves stock. Stock comes off at the till when the order is rung
 * up, which is also why an order that is cancelled never cost the shop a thing
 * in the records - see App\Services\TableOrderService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();

            // What the staff call it out loud: "T1", "Terrace 3", "Booth by the window".
            $table->string('name')->unique();

            // Grouping on the floor view: "Terrace", "Upstairs", "Bar".
            $table->string('area')->nullable();

            $table->unsignedInteger('seats')->default(2);

            // Retired rather than deleted: a table with history cannot be removed,
            // and a table taken out of service for a week should come back with
            // its name intact.
            $table->boolean('is_active')->default(true);

            // The order staff want to see them in, which is rarely alphabetical.
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });

        Schema::create('table_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();

            $table->foreignId('table_id')->constrained();

            // The server who opened it. Kept for the floor view and for "whose
            // table is this?", but it does NOT own the order: anyone on shift may
            // add to any table (see App\Policies\TableOrderPolicy).
            $table->foreignId('user_id')->constrained();

            // A named guest, when there is one (a tab, a room charge, an account).
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            // open|settled|cancelled. Indexed because every screen in this feature
            // asks "which orders are open?" - the floor view does it per table.
            $table->string('status', 12)->default('open')->index();

            $table->unsignedInteger('guests')->default(1);
            $table->text('note')->nullable();

            $table->timestamp('opened_at');
            $table->timestamp('settled_at')->nullable();

            /*
             * The sale this order turned into, set once it has been paid for.
             * Null while the order is open, and null forever on a cancelled order.
             * nullOnDelete: voiding and deleting a sale must not take the record of
             * the meal with it.
             */
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('table_order_items', function (Blueprint $table) {
            $table->id();

            // Cascade: the lines have no meaning without their order.
            $table->foreignId('table_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();

            $table->unsignedInteger('quantity');

            /*
             * The price when it was ordered, not the price now. A menu re-price
             * halfway through dinner must not change what the guests at table 4
             * are charged for the drinks they ordered an hour ago - they agreed to
             * the old price, and so did the menu they read it from.
             */
            $table->decimal('price', 15, 2);

            // "no onions", "rare", "allergy: nuts" - printed on the kitchen ticket.
            $table->string('note')->nullable();

            // starter / main / dessert / drinks, so the kitchen can fire in order.
            $table->string('course', 20)->nullable();

            /*
             * When this line was printed for the kitchen. Null means the kitchen
             * has never seen it, which is the only state in which a line can still
             * be changed freely: once it is stamped, the food exists.
             */
            $table->timestamp('sent_to_kitchen_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Children before parents, and the indexed `status` column's index before
        // the table it sits on - SQLite will not drop an indexed column otherwise.
        Schema::dropIfExists('table_order_items');

        Schema::table('table_orders', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::dropIfExists('table_orders');
        Schema::dropIfExists('tables');
    }
};
