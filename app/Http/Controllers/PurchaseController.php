<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PurchaseController extends Controller
{
    // Show all purchases with optional filtering
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Purchase::class);

        $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'status' => 'nullable|in:' . implode(',', Purchase::STATUSES),
        ]);

        $purchases = Purchase::with('supplier')
            ->when($request->filled('search'), fn($q) => $q->where('invoice_number', 'like', '%' . $request->search . '%'))
            ->when($request->filled('supplier_id'), fn($q) => $q->where('supplier_id', $request->supplier_id))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('from_date'), fn($q) => $q->whereDate('purchase_date', '>=', $request->from_date))
            ->when($request->filled('to_date'), fn($q) => $q->whereDate('purchase_date', '<=', $request->to_date))
            ->latest()
            ->paginate(10);

        $suppliers = Supplier::orderBy('name')->get();

        return view('purchases.index', compact('purchases', 'suppliers'));
    }

    // Show purchase creation form
    public function create()
    {
        Gate::authorize('create', Purchase::class);

        $suppliers = Supplier::active()->orderBy('name')->get();
        $products = Product::orderBy('name')->get();

        return view('purchases.create', compact('suppliers', 'products'));
    }

    // Store a new purchase
    public function store(Request $request)
    {
        Gate::authorize('create', Purchase::class);

        // Validate *before* opening the transaction so validation errors reach the
        // form instead of being swallowed by the generic catch below.
        $validated = $request->validate($this->rules($request));

        $items = $this->lines($validated);
        // Never the browser's total: the lines are what was actually agreed.
        $total = $this->total($items);
        $status = $validated['status'] ?? Purchase::STATUS_RECEIVED;
        $paid = min(round((float) ($validated['amount_paid'] ?? 0), 2), $total);

        try {
            $purchase = DB::transaction(function () use ($validated, $items, $total, $status, $paid) {
                $purchase = Purchase::create([
                    'supplier_id'    => $validated['supplier_id'],
                    'user_id'        => userId(),
                    'invoice_number' => $validated['invoice_number'],
                    'purchase_date'  => $validated['purchase_date'],
                    'note'           => $validated['note'] ?? null,
                    'reference'      => $validated['reference'] ?? null,
                    'expected_at'    => $validated['expected_at'] ?? null,
                    'total_amount'   => $total,
                    'status'         => $status,
                    'amount_paid'    => $paid,
                    'balance_due'    => round($total - $paid, 2),
                    // A purchase typed in as received arrived on its purchase date;
                    // one that is still on order has not arrived at all.
                    'received_at'    => $status === Purchase::STATUS_RECEIVED ? $validated['purchase_date'] : null,
                ]);

                foreach ($items as $item) {
                    PurchaseItem::create([
                        'purchase_id' => $purchase->id,
                        'product_id'  => $item['product_id'],
                        'quantity'    => $item['quantity'],
                        'cost_price'  => $item['cost_price'],
                        'total'       => round($item['quantity'] * $item['cost_price'], 2),
                    ]);
                }

                /*
                 * Stock only moves when the goods are actually in. An order that has
                 * been raised but not delivered must not inflate the shelf count -
                 * that was the whole point of giving a purchase a status.
                 */
                if ($purchase->hasMovedStock()) {
                    $this->moveStock([], $this->quantitiesByProduct($items), 'Purchase ' . $purchase->invoice_number);
                }

                AuditLog::record('Created purchase #' . $purchase->invoice_number, 'purchases', $purchase->id);

                return $purchase;
            });
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Purchase Error: ' . $e->getMessage());
            return redirect()->back()->withInput()->with('error', 'Failed to record purchase.');
        }

        return redirect()->route('purchases.index')->with('success', 'Purchase recorded successfully.');
    }

    // Show a specific purchase
    public function show(Purchase $purchase)
    {
        Gate::authorize('view', $purchase);

        $purchase->load(['supplier', 'user', 'purchaseItems.product', 'returns.user', 'returns.items.product']);

        return view('purchases.show', compact('purchase'));
    }

    public function edit(Purchase $purchase)
    {
        Gate::authorize('update', $purchase);

        $purchase->load('purchaseItems.product');

        $suppliers = Supplier::active()->orWhere('id', $purchase->supplier_id)->orderBy('name')->get();
        $products = Product::orderBy('name')->get();

        return view('purchases.edit', compact('purchase', 'suppliers', 'products'));
    }

    /**
     * Change a purchase after the fact: fix a quantity, fix a cost, or receive an
     * order that has arrived.
     *
     * The hard part is stock. A received purchase has already put its quantities
     * on the shelf, so the edit has to move only the *difference*: 10 down to 7
     * takes 3 back out, a removed line takes all of it out, a new line puts it in.
     * Re-adding the new quantities on top of the old ones (the obvious reading of
     * "save the form") would quietly double the stock every time anyone corrected
     * a typo.
     */
    public function update(Request $request, Purchase $purchase)
    {
        Gate::authorize('update', $purchase);

        $validated = $request->validate($this->rules($request, $purchase));

        $items = $this->lines($validated);
        $total = $this->total($items);
        $status = $validated['status'] ?? $purchase->status;
        $paid = min(round((float) ($validated['amount_paid'] ?? 0), 2), $total);

        try {
            DB::transaction(function () use ($purchase, $validated, $items, $total, $status, $paid) {
                $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);

                /*
                 * Part of this delivery has already gone back to the supplier. There
                 * is no honest way to reconcile a changed quantity against a line
                 * that is partly returned, so say so rather than guess.
                 */
                if ($purchase->hasReturns()) {
                    throw new DomainException(
                        'This purchase has goods returned against it and can no longer be edited. Record a further return instead.'
                    );
                }

                $before = $purchase->hasMovedStock()
                    ? $this->quantitiesByProduct($purchase->purchaseItems()->get()->map(fn($i) => [
                        'product_id' => $i->product_id,
                        'quantity' => $i->quantity,
                    ])->all())
                    : [];

                $after = $status === Purchase::STATUS_RECEIVED ? $this->quantitiesByProduct($items) : [];

                $this->moveStock($before, $after, 'Purchase edited ' . $purchase->invoice_number);

                // The lines are rewritten wholesale: matching them up one by one buys
                // nothing once the stock difference has already been settled above.
                $purchase->purchaseItems()->delete();

                foreach ($items as $item) {
                    PurchaseItem::create([
                        'purchase_id' => $purchase->id,
                        'product_id'  => $item['product_id'],
                        'quantity'    => $item['quantity'],
                        'cost_price'  => $item['cost_price'],
                        'total'       => round($item['quantity'] * $item['cost_price'], 2),
                    ]);
                }

                $becameReceived = $status === Purchase::STATUS_RECEIVED && !$purchase->hasMovedStock();

                $purchase->fill([
                    'supplier_id'    => $validated['supplier_id'],
                    'invoice_number' => $validated['invoice_number'],
                    'purchase_date'  => $validated['purchase_date'],
                    'note'           => $validated['note'] ?? null,
                    'reference'      => $validated['reference'] ?? null,
                    'expected_at'    => $validated['expected_at'] ?? null,
                    'total_amount'   => $total,
                    'status'         => $status,
                    'amount_paid'    => $paid,
                    'balance_due'    => round($total - $paid, 2),
                ]);

                if ($becameReceived) {
                    // It arrived today, whatever date the order was raised on.
                    $purchase->received_at = $purchase->received_at ?? now();
                } elseif ($status !== Purchase::STATUS_RECEIVED) {
                    $purchase->received_at = null;
                }

                $purchase->save();

                AuditLog::record('Updated purchase #' . $purchase->invoice_number, 'purchases', $purchase->id);
            });
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Update Purchase Error: ' . $e->getMessage());
            return redirect()->back()->withInput()->with('error', 'Failed to update purchase.');
        }

        return redirect()->route('purchases.show', $purchase)->with('success', 'Purchase updated.');
    }

    /**
     * Raise a purchase order from the reorder list.
     *
     * The reorder screen lives in another controller and may post its rows in
     * whichever shape suits its form, so this accepts the handful of reasonable
     * shapes rather than one (see normaliseLines). Quantities and ids are still
     * validated as hard as anything typed into the purchase form, and costs come
     * from the catalogue unless the form sent one.
     *
     * The result is always an `ordered` purchase: nothing has been delivered yet,
     * so nothing goes into stock.
     */
    public function storeFromReorder(Request $request)
    {
        Gate::authorize('create', Purchase::class);

        $header = $request->validate([
            'supplier_id'    => 'required|exists:suppliers,id',
            'invoice_number' => 'nullable|string|max:255|unique:purchases,invoice_number',
            'reference'      => 'nullable|string|max:255',
            'expected_at'    => 'nullable|date',
            'note'           => 'nullable|string|max:1000',
        ]);

        $lines = $this->normaliseLines($request);

        Validator::make(['lines' => $lines], [
            'lines'              => 'required|array|min:1|max:500',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.quantity'   => 'required|integer|min:1|max:1000000',
            'lines.*.cost_price' => 'nullable|numeric|min:0|max:99999999',
        ], [
            'lines.required' => 'Select at least one item to order.',
            'lines.min' => 'Select at least one item to order.',
        ])->validate();

        // Costs come from the catalogue unless the form sent one, so a tampered
        // field cannot write a price nobody agreed to.
        $costs = Product::whereIn('id', array_column($lines, 'product_id'))->pluck('cost_price', 'id');

        $items = [];
        foreach ($lines as $line) {
            $id = (int) $line['product_id'];

            $items[] = [
                'product_id' => $id,
                'quantity' => (int) $line['quantity'],
                'cost_price' => round((float) ($line['cost_price'] ?? $costs[$id] ?? 0), 2),
            ];
        }

        $items = $this->mergeDuplicateLines($items);
        $total = $this->total($items);

        try {
            $purchase = DB::transaction(function () use ($header, $items, $total) {
                $purchase = Purchase::create([
                    'supplier_id'    => $header['supplier_id'],
                    'user_id'        => userId(),
                    'invoice_number' => ($header['invoice_number'] ?? null) ?: $this->nextOrderNumber(),
                    'purchase_date'  => now(),
                    'note'           => $header['note'] ?? null,
                    'reference'      => $header['reference'] ?? null,
                    'expected_at'    => $header['expected_at'] ?? null,
                    'total_amount'   => $total,
                    'status'         => Purchase::STATUS_ORDERED,
                    'amount_paid'    => 0,
                    'balance_due'    => $total,
                    'received_at'    => null,
                ]);

                foreach ($items as $item) {
                    PurchaseItem::create([
                        'purchase_id' => $purchase->id,
                        'product_id'  => $item['product_id'],
                        'quantity'    => $item['quantity'],
                        'cost_price'  => $item['cost_price'],
                        'total'       => round($item['quantity'] * $item['cost_price'], 2),
                    ]);
                }

                AuditLog::record('Raised purchase order #' . $purchase->invoice_number . ' from the reorder list', 'purchases', $purchase->id);

                return $purchase;
            });
        } catch (\Throwable $e) {
            Log::error('Reorder Purchase Order Error: ' . $e->getMessage());
            return redirect()->back()->withInput()->with('error', 'Failed to raise the purchase order.');
        }

        return redirect()->route('purchases.show', $purchase)
            ->with('success', 'Purchase order ' . $purchase->invoice_number . ' raised. Receive it when the goods arrive.');
    }

    // Delete a purchase and take its stock back out
    public function destroy(Purchase $purchase)
    {
        Gate::authorize('delete', $purchase);

        try {
            DB::transaction(function () use ($purchase) {
                $purchase->load('purchaseItems');

                if ($purchase->hasReturns()) {
                    throw new DomainException(
                        'This purchase has goods returned against it, so its history has to stay. Cancel it instead.'
                    );
                }

                foreach ($purchase->purchaseItems as $item) {
                    // An order that never arrived added nothing to stock, so there is
                    // nothing to take back out.
                    if ($purchase->hasMovedStock()) {
                        $product = Product::withTrashed()->lockForUpdate()->find($item->product_id);

                        if ($product->stock_quantity < $item->quantity) {
                            throw new DomainException(
                                "Cannot delete: only {$product->stock_quantity} of {$product->name} left, but this purchase added {$item->quantity}."
                            );
                        }

                        $product->decrement('stock_quantity', $item->quantity);

                        InventoryLog::create([
                            'product_id' => $product->id,
                            'user_id'    => userId(),
                            'type'       => 'OUT',
                            'quantity'   => $item->quantity,
                            'reason'     => 'Purchase deleted ' . $purchase->invoice_number,
                        ]);
                    }

                    $item->delete();
                }

                AuditLog::record('Deleted purchase #' . $purchase->invoice_number, 'purchases', $purchase->id);

                $purchase->delete();
            });
        } catch (\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Delete Purchase Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to delete purchase.');
        }

        return redirect()->route('purchases.index')->with('success', 'Purchase deleted.');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** Validation for the purchase form, shared by store and update. */
    private function rules(Request $request, ?Purchase $purchase = null): array
    {
        $count = count((array) $request->input('product_ids', []));

        return [
            'supplier_id'    => 'required|exists:suppliers,id',
            'purchase_date'  => 'required|date',
            'invoice_number' => 'required|string|max:255|unique:purchases,invoice_number' . ($purchase ? ',' . $purchase->id : ''),
            'note'           => 'nullable|string',
            'reference'      => 'nullable|string|max:255',
            'expected_at'    => 'nullable|date',
            'status'         => 'nullable|in:' . implode(',', Purchase::STATUSES),
            'amount_paid'    => 'nullable|numeric|min:0',
            'product_ids'    => 'required|array|min:1',
            'product_ids.*'  => 'required|exists:products,id',
            'quantities'     => 'required|array|size:' . $count,
            'quantities.*'   => 'required|integer|min:1',
            'cost_prices'    => 'required|array|size:' . $count,
            'cost_prices.*'  => 'required|numeric|min:0',
        ];
    }

    /** The posted parallel arrays turned into one line per item. */
    private function lines(array $validated): array
    {
        $items = [];

        foreach (array_values($validated['product_ids']) as $i => $productId) {
            $items[] = [
                'product_id' => (int) $productId,
                'quantity' => (int) array_values($validated['quantities'])[$i],
                'cost_price' => round((float) array_values($validated['cost_prices'])[$i], 2),
            ];
        }

        return $items;
    }

    private function total(array $items): float
    {
        return round(collect($items)->sum(fn($item) => round($item['quantity'] * $item['cost_price'], 2)), 2);
    }

    /**
     * Stock is counted per product, not per line: the same product can appear on
     * two lines at two costs, and the shelf only knows the total.
     *
     * @return array<int, int> product id => quantity
     */
    private function quantitiesByProduct(array $items): array
    {
        $totals = [];

        foreach ($items as $item) {
            $id = (int) $item['product_id'];
            $totals[$id] = ($totals[$id] ?? 0) + (int) $item['quantity'];
        }

        return $totals;
    }

    /** Two lines for the same product become one, keeping the dearer cost. */
    private function mergeDuplicateLines(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            $id = (int) $item['product_id'];

            if (isset($merged[$id])) {
                $merged[$id]['quantity'] += (int) $item['quantity'];
                $merged[$id]['cost_price'] = max($merged[$id]['cost_price'], $item['cost_price']);
                continue;
            }

            $merged[$id] = $item;
        }

        return array_values($merged);
    }

    /**
     * Move stock from one set of quantities to another, writing one inventory row
     * per product that actually changed.
     *
     * @param  array<int, int>  $before  what this purchase currently has on the shelf
     * @param  array<int, int>  $after   what it should have
     *
     * @throws DomainException when taking the difference back out would go negative
     */
    private function moveStock(array $before, array $after, string $reason): void
    {
        $ids = array_unique(array_merge(array_keys($before), array_keys($after)));
        // A stable lock order: two people editing overlapping purchases queue up
        // instead of deadlocking on each other's rows.
        sort($ids);

        foreach ($ids as $id) {
            $delta = ($after[$id] ?? 0) - ($before[$id] ?? 0);

            if ($delta === 0) {
                continue;
            }

            // withTrashed: an archived product still needs its stock corrected.
            $product = Product::withTrashed()->lockForUpdate()->find($id);

            if (!$product) {
                throw new DomainException('A product on this purchase no longer exists.');
            }

            if ($delta < 0 && (int) $product->stock_quantity < -$delta) {
                $out = -$delta;

                throw new DomainException(
                    "Cannot apply this change: it takes {$out} of {$product->name} back out of stock, but only {$product->stock_quantity} is left - the rest has been sold."
                );
            }

            $delta > 0
                ? $product->increment('stock_quantity', $delta)
                : $product->decrement('stock_quantity', -$delta);

            InventoryLog::create([
                'product_id' => $id,
                'user_id'    => userId(),
                'type'       => $delta > 0 ? 'IN' : 'OUT',
                'quantity'   => abs($delta),
                'reason'     => $reason,
            ]);
        }
    }

    /**
     * Rows posted by the reorder screen, in whichever shape it chose to send
     * them. All of these end up as the same list:
     *
     *   items[0][product_id]=7&items[0][quantity]=12          (rows)
     *   items[7][quantity]=12                                 (keyed by product)
     *   items[7]=12                                           (keyed, bare quantity)
     *   product_ids[]=7&quantities[]=12                       (parallel, like the purchase form)
     *   products[]=7&quantities[7]=12                         (ids plus a quantity map)
     *   selected[]=7&quantities[7]=12                          (checkbox list plus a map)
     *
     * A row with no quantity, or a quantity of zero, is a row the user left
     * alone: a checkbox screen posts every product it listed. Anything else that
     * is not a whole number is left in place for the validator to reject rather
     * than being quietly dropped.
     *
     * @return list<array{product_id: mixed, quantity: mixed, cost_price: mixed}>
     */
    private function normaliseLines(Request $request): array
    {
        $rows = $request->input('items');
        $lines = [];

        if (is_array($rows)) {
            foreach ($rows as $key => $row) {
                if (!is_array($row)) {
                    $lines[] = ['product_id' => $key, 'quantity' => $row, 'cost_price' => null];
                    continue;
                }

                $lines[] = [
                    'product_id' => $row['product_id'] ?? $row['id'] ?? $key,
                    'quantity'   => $row['quantity'] ?? $row['qty'] ?? null,
                    'cost_price' => $row['cost_price'] ?? $row['cost'] ?? null,
                ];
            }
        } else {
            $ids = (array) ($request->input('product_ids') ?? $request->input('products') ?? $request->input('selected') ?? []);
            $quantities = (array) $request->input('quantities', []);
            $costs = (array) ($request->input('cost_prices') ?? $request->input('costs') ?? []);

            $positionalQuantities = array_values($quantities);
            $positionalCosts = array_values($costs);

            foreach (array_values($ids) as $i => $id) {
                // A quantity map keyed by product id wins over position: a checkbox
                // screen posts the ids it ticked and the quantities for every row.
                $lines[] = [
                    'product_id' => $id,
                    'quantity'   => array_key_exists($id, $quantities) ? $quantities[$id] : ($positionalQuantities[$i] ?? null),
                    'cost_price' => array_key_exists($id, $costs) ? $costs[$id] : ($positionalCosts[$i] ?? null),
                ];
            }
        }

        return array_values(array_filter(
            $lines,
            fn($line) => !in_array($line['quantity'], [null, '', 0, '0', 0.0], true) && $line['quantity'] !== false
        ));
    }

    /** PO-20261014-3: the shop's own order number when the form did not supply one. */
    private function nextOrderNumber(): string
    {
        $prefix = 'PO-' . now()->format('Ymd') . '-';
        $next = Purchase::withTrashed()->where('invoice_number', 'like', $prefix . '%')->count() + 1;

        while (Purchase::withTrashed()->where('invoice_number', $prefix . $next)->exists()) {
            $next++;
        }

        return $prefix . $next;
    }
}
