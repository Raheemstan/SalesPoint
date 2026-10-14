<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-branch: one business, several shops.
 *
 * This is the most invasive change in the app, so the shape of it is argued out
 * here rather than discovered in twenty controllers.
 *
 * WHICH TABLES ARE PER-SITE
 *
 * One rule decides it: **a table gets `branch_id` if and only if it is queried
 * on its own.** Anything only ever reached through a parent inherits the
 * parent's branch, and giving it a column of its own would be a second copy of
 * the same fact - and two copies drift. So `sale_items` has no `branch_id`
 * (you read it through its sale), `sale_payments` has none (same), and
 * `purchase_returns` does, because it has its own list screen.
 *
 * WHY THE CATALOGUE IS SHARED, AND STOCK IS NOT
 *
 * `products`, `categories`, `customers` and `suppliers` deliberately get no
 * `branch_id`. A branch is a place, not a different business: the same tin of
 * milk has the same barcode and the same price in both shops, and a customer who
 * owes money owes it to the business rather than to a building. Splitting the
 * catalogue per branch would mean re-keying every product for every new shop,
 * two SKUs for one physical item, a barcode scan that is ambiguous, and a
 * customer with two unrelated balances. What retailers actually want is one
 * catalogue with a different number of things on each shelf.
 *
 * So the *stock* moves to `branch_stock` (one row per branch per product) and
 * the catalogue stays shared.
 *
 * HOW `products.stock_quantity` AND `branch_stock` RELATE
 *
 * This is the part most likely to go wrong, so it is stated as an invariant:
 *
 *     products.stock_quantity == SUM(branch_stock.stock_quantity)
 *                                for that product, across all branches
 *
 * `products.stock_quantity` is the **group-wide total**; `branch_stock` is the
 * split of that total between shops. With one branch the two are the same
 * number, which is why a single-site shop behaves exactly as it does today and
 * every existing query, report and scope keeps working untouched.
 *
 * The invariant is maintained by App\Observers\ProductStockObserver, which
 * mirrors every change to `products.stock_quantity` into the acting branch's
 * row. It runs whether or not `feature('branches')` is on, deliberately: that is
 * what makes `branch_stock` still true on the day a shop that has been running
 * single-site for a year switches branches on. The alternative - only mirroring
 * when the feature is on - leaves this table holding the figures from the day
 * this migration ran, which would be silently wrong the moment it mattered.
 *
 * Stock in transit between branches belongs to neither and is counted in
 * neither: a dispatch takes it out of the source (and out of the group total), a
 * receipt puts it into the destination. See App\Services\StockTransferService.
 *
 * THE BACKFILL IS THE IMPORTANT PART
 *
 * Every existing install is a single-branch install. One default branch is
 * created and every existing row is assigned to it, so nothing is orphaned and
 * nothing has to be tidied up by hand afterwards.
 */
return new class extends Migration
{
    /**
     * Tables that are genuinely per-site, with the reason each one is.
     *
     * Keep the justification next to the column: the next person's question is
     * always "why is this one per-branch and that one not?".
     */
    private const PER_SITE = [
        // A sale is rung up on a till that stands in one shop. The whole feature
        // is pointless without this one.
        'sales' => 'created_at',
        // Goods are delivered to an address, and receiving them adds stock there.
        'purchases' => 'purchase_date',
        // Rent, power and wages belong to the site that incurred them, which is
        // the only way a per-branch profit figure means anything.
        'expenses' => 'expense_date',
        // A movement happens in a place. Without this the movement report cannot
        // answer "what moved here?".
        'inventory_logs' => 'created_at',
        // A drawer is a physical box at a physical till.
        'shifts' => 'opened_at',
        // You count the shelves of one shop.
        'stock_takes' => 'created_at',
        // Damage, theft and expiry happen somewhere.
        'stock_adjustments' => 'created_at',
        // Money handed back came out of a particular drawer.
        'refunds' => 'created_at',
        // A held cart belongs to the till it was held at; resuming it in another
        // shop would price goods that are not on that shelf.
        'parked_sales' => 'created_at',
        // A table is furniture. It cannot be in two restaurants.
        'tables' => null,
        // Derived from its table, but stamped so the floor view and the order
        // list filter in one index rather than through a join.
        'table_orders' => 'opened_at',
        // Has its own list screen, so by the rule above it earns a column.
        'purchase_returns' => 'created_at',
    ];

    public function up(): void
    {
        /*
        | A shop. `code` is the short form staff actually use ("IKJ", "MAIN") and
        | is what appears on a reference, so it is unique and kept short.
        |
        | `is_default` is not just a flag for the UI: it is the branch everything
        | falls back to - the home branch of a user who has none, and the branch
        | the whole app acts as while `feature('branches')` is off. Exactly one
        | row should carry it; App\Models\Branch::makeDefault() enforces that.
        */
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 12)->unique();
            $table->string('address')->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // The switcher and every list order by this, then by name.
            $table->index(['is_active', 'sort_order']);
        });

        $defaultId = $this->createDefaultBranch();

        /*
        | `branch_id` on the per-site tables.
        |
        | Nullable, even though the backfill fills every row and
        | App\Models\Concerns\BelongsToBranch stamps every new one. A NOT NULL
        | column would turn "this install has no branches table yet" into a
        | failed insert - a shop unable to take money - and that is a far worse
        | failure than a row that has to be assigned later.
        */
        foreach (array_keys(self::PER_SITE) as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            });

            // Every existing row belongs to the shop that has been trading all
            // along. DB::table(), so soft-deleted rows are assigned too.
            DB::table($table)->update(['branch_id' => $defaultId]);
        }

        /*
        | The composite indexes the scope actually uses.
        |
        | constrained() already indexed `branch_id` on its own, but every
        | branch-aware read is "this branch, over this date range", and a
        | single-column index makes the database choose between the two. Only the
        | tables big enough to care get one.
        */
        foreach (self::PER_SITE as $table => $dateColumn) {
            if ($dateColumn === null) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($dateColumn) {
                $blueprint->index(['branch_id', $dateColumn]);
            });
        }

        /*
        | Where a user works.
        |
        | `users.branch_id` is their *home* branch: where they are put when they
        | sign in. `branch_user` is for the people who genuinely work at several
        | sites - a relief cashier, a manager doing the rounds - and is what
        | decides whether someone may switch (see App\Models\User::canUseBranch).
        */
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });

        DB::table('users')->update(['branch_id' => $defaultId]);

        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One assignment per person per shop; "assigned twice" is meaningless.
            $table->unique(['branch_id', 'user_id']);
        });

        // Everyone already here works at the shop that already exists.
        $now = now();
        DB::table('branch_user')->insert(
            DB::table('users')->pluck('id')->map(fn ($id) => [
                'branch_id' => $defaultId,
                'user_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );

        /*
        | How much of each product is on each shelf. See the class docblock: this
        | is the split of `products.stock_quantity`, not a second opinion on it.
        |
        | `alert_quantity` is a per-branch override of the product's reorder
        | level, because a busy shop runs out faster than a quiet one. Null means
        | "use the product's", which is what every row starts as.
        */
        Schema::create('branch_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('stock_quantity')->default(0);
            $table->integer('alert_quantity')->nullable();
            $table->timestamps();

            // One row per product per shop. This is also what lets the observer
            // mirror a movement with insertOrIgnore + update and no race.
            $table->unique(['branch_id', 'product_id']);
            // "Where is this product held?" - the transfer screen's question.
            $table->index('product_id');
        });

        // All the stock there has ever been is standing in the only shop there
        // has ever been, so the invariant holds from the first moment.
        DB::table('branch_stock')->insert(
            DB::table('products')
                ->select('id', 'stock_quantity')
                ->get()
                ->map(fn ($product) => [
                    'branch_id' => $defaultId,
                    'product_id' => $product->id,
                    'stock_quantity' => (int) $product->stock_quantity,
                    'alert_quantity' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
        );

        /*
        | Moving stock between shops.
        |
        | Two timestamps rather than one, because the whole point is the gap
        | between them: stock leaves the source at `dispatched_at` and arrives at
        | the destination at `received_at`, and in between it is counted in
        | neither place. A single "transferred" event would have to pick one end
        | to be wrong about.
        */
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();

            $table->foreignId('from_branch_id')->constrained('branches');
            $table->foreignId('to_branch_id')->constrained('branches');

            // Not an enum: SQLite fakes one with a check constraint that is a
            // pain to change later. The allowed values live in
            // App\Models\StockTransfer::STATUSES.
            $table->string('status', 12)->default('in_transit');

            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('note')->nullable();
            $table->timestamps();

            // The two lists this screen has: what is coming to me, and what I
            // have sent. Both narrow by status first.
            $table->index(['to_branch_id', 'status']);
            $table->index(['from_branch_id', 'status']);
        });

        /*
        | One product on one transfer. `cost_price` is snapshotted for the same
        | reason stock-takes snapshot it: what the goods were worth when they
        | moved must not change when somebody edits the product next month.
        */
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->integer('quantity');
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->timestamps();

            // A product may only appear once on a transfer; two lines for the
            // same item is a mistake, not an order for more.
            $table->unique(['stock_transfer_id', 'product_id']);
        });
    }

    /**
     * The one branch an existing install has been trading as all along.
     *
     * Named from the shop's own settings rather than "Branch 1", because this
     * row is about to appear in a switcher and on every reference, and a shop
     * recognising its own name is the difference between the feature looking
     * finished and looking like a migration artefact.
     */
    private function createDefaultBranch(): int
    {
        $settings = DB::table('settings')->pluck('value', 'key');
        $now = now();

        return (int) DB::table('branches')->insertGetId([
            'name' => $this->firstFilled($settings, ['store_name']) ?? 'Main Branch',
            'code' => 'MAIN',
            'address' => $this->firstFilled($settings, ['store_address']),
            'phone' => $this->firstFilled($settings, ['store_phone']),
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * The first of these setting keys that holds something, trimmed.
     *
     * @param  \Illuminate\Support\Collection<string, mixed>  $settings
     * @param  array<int, string>  $keys
     */
    private function firstFilled($settings, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($settings[$key] ?? ''));

            if ($value !== '') {
                // `branches.name` is a plain varchar; a pathological setting
                // must not fail the migration.
                return mb_substr($value, 0, 255);
            }
        }

        return null;
    }

    /**
     * Rolling back has to work, and on SQLite that means taking things apart in
     * the opposite order they went together: an index before the column it
     * covers, a child table before its parent, the foreign key before the column
     * it constrains.
     */
    public function down(): void
    {
        // Children first, or the foreign keys refuse to go.
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('branch_stock');
        Schema::dropIfExists('branch_user');

        // The composite indexes, before the column they are built on.
        foreach (self::PER_SITE as $table => $dateColumn) {
            if ($dateColumn === null) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($dateColumn) {
                $blueprint->dropIndex(['branch_id', $dateColumn]);
            });
        }

        // Then the foreign key and the column together, one ALTER at a time:
        // SQLite rebuilds the table for each, and batching them in one closure
        // is what makes it fall over.
        foreach (array_keys(self::PER_SITE) as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('branch_id');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        // Last, now that nothing points at it.
        Schema::dropIfExists('branches');
    }
};
