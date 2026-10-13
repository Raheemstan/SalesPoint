<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Sale;
use App\Models\TableOrder;
use App\Services\TableOrderService;

/**
 * Closes a table order once the till has actually sold it.
 *
 * WHY THIS EXISTS AT ALL
 *
 * Settling a table order does not take any money. TableOrderController::settle()
 * only loads the order into the session cart and sends the server to the till;
 * the payment - split tenders, credit, change, the shift stamp, the stock
 * decrement - all happens in POSController::checkout(), which is the only place
 * in the app that knows how to do it properly and is owned by somebody else.
 *
 * That leaves one gap: when the cashier finishes, something has to go back and
 * mark the order paid. Doing it inside checkout() would mean editing a file this
 * feature does not own, and a `booted()` hook on TableOrder cannot help because
 * the model that gets saved is a Sale, not a TableOrder. So this observer
 * watches Sale instead, registered from App\Providers\TableServiceProvider.
 *
 * The session is the baton: settle() puts the order id in `table_order_id`, and
 * the first sale created while that key is set is the sale that paid for it.
 *
 * ON RUNNING INSIDE THE TILL'S TRANSACTION
 *
 * `created` fires inside POSController::checkout()'s DB::transaction, which is
 * what we want: if the checkout rolls back, the order stays open rather than
 * being marked paid for a sale that does not exist. The session keys are cleared
 * either way (a session write is not transactional), but that is harmless - the
 * order is still open, so the server simply settles it again.
 *
 * The keys are forgotten *before* the write, so that a sale created in a session
 * whose order has already been closed can never reopen the question: one baton,
 * one sale.
 */
class TableOrderSaleObserver
{
    public function __construct(private TableOrderService $orders)
    {
    }

    public function created(Sale $sale): void
    {
        $orderId = session('table_order_id');

        if (!$orderId) {
            return;
        }

        // Hand the baton back immediately: whatever happens below, the next sale
        // this cashier rings up is a fresh one. `cart_service_charge` goes with it
        // so a tip cannot follow a server into somebody else's bill.
        session()->forget(['table_order_id', 'cart_service_charge']);

        $order = TableOrder::find($orderId);

        // Gone, or already closed by another route. Nothing to do, and nothing
        // worth failing a completed sale over.
        if (!$order || !$order->isOpen()) {
            return;
        }

        $this->orders->markSettled($order, $sale->id);

        AuditLog::record(
            "Settled {$order->reference} as {$sale->invoice_number}",
            'table_orders',
            $order->id
        );
    }
}
