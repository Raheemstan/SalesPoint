<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variants (one style in many sizes and colours) and serial numbers (an IMEI per
 * unit sold). Both flags already exist in config/sectors.php - `fashion` switches
 * on variants, `electronics` switches on serials - but gated nothing, because
 * there were no columns behind them.
 *
 * WHY A VARIANT IS A PRODUCT WITH A PARENT, AND NOT ITS OWN TABLE
 *
 * The obvious shape is a `product_variants` table holding the size, colour, SKU
 * and stock of each combination. It is also the expensive one: a variant is a
 * thing you sell, so the till's cart and its stock locking, `sale_items`,
 * `inventory_logs`, stock-takes, the reorder list, purchases and every report
 * would each need a second code path - "is this a product id or a variant id?" -
 * and every one of those is a place to get it wrong.
 *
 * So a variant is simply a product row with `parent_id` set. It has its own SKU,
 * barcode, cost, price, stock level and reorder level because products already
 * do, and therefore **the entire rest of the app sells, counts, buys and reports
 * on variants with no changes at all**. The parent stops being a thing you sell
 * and becomes a grouping row: its own stock is moved onto its variants (so it
 * reads as out of stock to anything that was never written for variants) and it
 * is excluded from the till, stock-takes, the reorder list and low-stock
 * warnings - see Product::scopeSellable().
 *
 * The price of that is one rule the database cannot express: only one level is
 * allowed, a variant may not have variants of its own. That is enforced in
 * App\Services\VariantService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            /*
             * Self-referencing: the style this row is a variant of. nullOnDelete
             * is the database's backstop only - products are soft-deleted, so in
             * practice a parent with variants is refused outright
             * (see ProductController::destroy) rather than orphaning its children.
             */
            $table->foreignId('parent_id')->nullable()->after('id')
                ->constrained('products')->nullOnDelete();

            // "Red / XL". Free text: the axes a trade varies by are its own business.
            $table->string('variant_label')->nullable()->after('name');

            // Must a serial / IMEI be recorded for every unit of this item sold?
            $table->boolean('serial_required')->default(false)->after('track_stock');

            // Listing a parent's variants is the commonest query there is.
            $table->index('parent_id');
            $table->index('variant_label');
        });

        /*
        | One recorded serial: this unit, on this sale line.
        |
        | Rows, not a comma-separated column, because the whole point is looking a
        | serial up ("who bought this phone?") and because each unit is one row of
        | the quantity sold. (product_id, serial) is unique: a serial identifies a
        | single physical unit, so selling the same one twice is a mistake, not a
        | repeat sale. product_id is stored as well as derivable from the sale item
        | so the uniqueness rule and the lookup are both one index away.
        */
        Schema::create('sale_item_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('serial');
            $table->timestamps();

            // "Which sale did this serial go out on?" - the warranty question.
            $table->index('serial');
            $table->unique(['product_id', 'serial']);
        });
    }

    public function down(): void
    {
        // Dropping a table takes its indexes with it; only an ALTER on SQLite
        // needs an index removed before the column it covers.
        Schema::dropIfExists('sale_item_serials');

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['parent_id']);
            $table->dropIndex(['variant_label']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['variant_label', 'serial_required']);
        });
    }
};
