<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            // What the goods cost when they were sold (profit must not move when the product's cost later changes).
            $table->decimal('cost_price', 15, 2)->default(0)->after('price');
            // Line-level discount, and this line's share of order discount + tax.
            $table->decimal('discount_amount', 15, 2)->default(0)->after('total');
            $table->decimal('tax_amount', 15, 2)->default(0)->after('discount_amount');
            // What the customer actually paid for this line (sums to sales.grand_total); basis for refunds.
            $table->decimal('paid_total', 15, 2)->default(0)->after('tax_amount');
            $table->unsignedInteger('refunded_quantity')->default(0)->after('paid_total');
            $table->decimal('refunded_amount', 15, 2)->default(0)->after('refunded_quantity');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('status', 20)->default('completed')->index()->after('change_due'); // completed|partially_refunded|refunded|voided
            $table->decimal('tax_rate', 5, 2)->default(0)->after('status');
            $table->string('discount_type', 10)->nullable()->after('tax_rate');      // order-level: percent|fixed
            $table->decimal('discount_value', 15, 2)->nullable()->after('discount_type');
            $table->decimal('refunded_amount', 15, 2)->default(0)->after('discount_value');
            $table->timestamp('voided_at')->nullable()->after('refunded_amount');
            $table->unsignedBigInteger('voided_by')->nullable()->after('voided_at');
            $table->string('void_reason')->nullable()->after('voided_by');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->decimal('total_amount', 15, 2);            // money handed back, tax included
            $table->decimal('tax_amount', 15, 2)->default(0);  // the tax part of total_amount
            $table->string('method', 20);                       // cash|pos|transfer: how the money went back
            $table->string('reason');
            $table->boolean('is_void')->default(false);
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('refund_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->decimal('amount', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->boolean('restocked')->default(true);       // false = damaged/written off, cost stays booked
            $table->timestamps();
        });

        $this->backfill();
    }

    /**
     * Existing installs: best-effort history. Cost uses the product's *current* cost
     * (the real cost at the time was never recorded); paid_total reproduces what each
     * line actually contributed to grand_total.
     */
    private function backfill(): void
    {
        DB::statement('UPDATE sale_items SET cost_price = COALESCE((SELECT cost_price FROM products WHERE products.id = sale_items.product_id), 0)');
        DB::statement('UPDATE sale_items SET paid_total = total');

        DB::table('sales')
            ->where('total_amount', '>', 0)
            ->whereColumn('grand_total', '!=', 'total_amount')
            ->orderBy('id')
            ->each(function ($sale) {
                $items = DB::table('sale_items')->where('sale_id', $sale->id)->orderBy('id')->get();
                $paidLeft = (float) $sale->grand_total;
                $taxLeft = (float) $sale->tax_amount;
                $discLeft = (float) $sale->discount_amount;

                foreach ($items as $i => $item) {
                    $last = $i === $items->count() - 1;
                    $share = $item->total / $sale->total_amount;

                    $paid = $last ? $paidLeft : round($sale->grand_total * $share, 2);
                    $tax = $last ? $taxLeft : round($sale->tax_amount * $share, 2);
                    $disc = $last ? $discLeft : round($sale->discount_amount * $share, 2);

                    $paidLeft -= $paid;
                    $taxLeft -= $tax;
                    $discLeft -= $disc;

                    DB::table('sale_items')->where('id', $item->id)
                        ->update(['paid_total' => round($paid, 2), 'tax_amount' => round($tax, 2), 'discount_amount' => round($disc, 2)]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_items');
        Schema::dropIfExists('refunds');

        // An indexed column has to lose its index before it can be dropped (SQLite refuses otherwise).
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['status', 'tax_rate', 'discount_type', 'discount_value', 'refunded_amount', 'voided_at', 'voided_by', 'void_reason']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'discount_amount', 'tax_amount', 'paid_total', 'refunded_quantity', 'refunded_amount']);
        });
    }
};
