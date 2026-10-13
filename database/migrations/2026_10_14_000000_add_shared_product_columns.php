<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columns that more than one feature depends on, kept in one migration so the
 * features built on top of them cannot disagree about the shape of the data.
 *
 * `products` grows the fields that make the catalogue usable outside a plain
 * retail shop (see config/sectors.php): a salon sells services that are never
 * counted, a pharmacy needs an expiry date, a wholesaler buys by the carton and
 * sells by the piece, and every trade wants a picture and a default supplier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Who we normally buy this from: lets the reorder list group by supplier
            // without guessing from purchase history.
            $table->foreignId('supplier_id')->nullable()->after('category_id')->constrained()->nullOnDelete();

            $table->string('image_path')->nullable()->after('barcode');

            // Selling unit, and how many selling units are in one bought unit
            // (a carton of 12 => unit "piece", pack_size 12).
            $table->string('unit', 20)->default('piece')->after('sale_price');
            $table->decimal('pack_size', 10, 3)->default(1)->after('unit');

            // A service (labour, a haircut, a delivery fee) has no stock at all.
            // track_stock lets a normal product opt out too.
            $table->boolean('is_service')->default(false)->after('pack_size');
            $table->boolean('track_stock')->default(true)->after('is_service');

            // Batch / expiry: only shown when the trade needs it (feature('expiry')).
            $table->string('batch_no')->nullable()->after('alert_quantity');
            $table->date('expiry_date')->nullable()->after('batch_no');

            // How many to put on a purchase order when this item runs low.
            $table->unsignedInteger('reorder_quantity')->default(0)->after('expiry_date');

            // Archive an item without deleting its history.
            $table->boolean('is_active')->default(true)->after('reorder_quantity');

            $table->index('is_active');
            $table->index('expiry_date');
        });

        // A service cannot run low, so make the existing rows explicit rather than
        // leaving the defaults to be guessed at later.
        DB::table('products')->update(['is_service' => false, 'track_stock' => true, 'is_active' => true]);
    }

    public function down(): void
    {
        // SQLite will not drop an indexed column while the index still exists.
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropIndex(['expiry_date']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'image_path', 'unit', 'pack_size', 'is_service', 'track_stock',
                'batch_no', 'expiry_date', 'reorder_quantity', 'is_active',
            ]);
        });
    }
};
