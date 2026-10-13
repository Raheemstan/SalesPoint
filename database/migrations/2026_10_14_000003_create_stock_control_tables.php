<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock control: counted stock-takes and one-off adjustments.
 *
 * Until now the only way to correct a stock figure was to retype it on the
 * product form, which left no reason and needed nobody's approval - so
 * shrinkage (theft, damage, miscounts) was invisible. These three tables give
 * every correction a reason, an author and, for a stock-take, a second pair of
 * eyes before the stock actually moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
        | One counting session: a sheet of lines that staff fill in, then submit
        | for approval. Nothing touches real stock until it is approved.
        */
        Schema::create('stock_takes', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('user_id')->constrained();          // who counted
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            // Not an enum: SQLite fakes enums with a check constraint, which is a
            // pain to change later. The allowed values live in StockTake::STATUSES.
            $table->string('status', 12)->default('draft')->index();

            $table->text('note')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        /*
        | One product on one sheet. system_quantity and cost_price are snapshots
        | taken when the sheet was created: the figure the system believed at that
        | moment, and what the stock cost then. Both must stay frozen, or the
        | value of a loss would quietly change whenever someone edits the product.
        */
        Schema::create('stock_take_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_take_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->integer('system_quantity');
            $table->integer('counted_quantity')->nullable();      // null = not counted yet
            $table->integer('variance')->default(0);              // counted - system
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        /*
        | A single stock correction with a reason. Written both by the adjustment
        | screen and by stock-take approval (which fills in stock_take_id), so
        | "why is the stock what it is?" has one answer in one place.
        */
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('type', 10);                           // increase|decrease
            $table->integer('quantity');                          // always positive; `type` carries the direction
            $table->string('reason', 30);                         // damage|theft|expiry|count|correction|other
            $table->string('note')->nullable();
            $table->decimal('cost_price', 15, 2)->default(0);     // what the move was worth when it was made
            $table->foreignId('stock_take_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');                           // the history screen filters by date
        });
    }

    public function down(): void
    {
        // Children before parents, or the foreign keys refuse to go.
        // (Dropping a table drops its indexes with it; only an ALTER on SQLite
        // needs the index removed before the column it covers.)
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_take_items');
        Schema::dropIfExists('stock_takes');
    }
};
