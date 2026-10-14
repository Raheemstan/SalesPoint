<?php

use App\Http\Controllers\AdjustmentController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPaymentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\OfflineSyncController;
use App\Http\Controllers\ParkedSaleController;
use App\Http\Controllers\POSController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\ReceiptShareController;
use App\Http\Controllers\ReorderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SerialController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\StockTakeController;
use App\Http\Controllers\TableController;
use App\Http\Controllers\TableOrderController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect(route('pos.index'));
});

/*
|--------------------------------------------------------------------------
| Two-factor challenge
|--------------------------------------------------------------------------
|
| Sits outside the 'auth' group on purpose: the password has been accepted but
| the user is not signed in yet, so these run for a half-authenticated session.
|
*/
Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
Route::post('/two-factor-challenge', [TwoFactorController::class, 'verify'])->name('two-factor.verify');

Route::middleware('auth')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Any signed-in user (cashier, manager, admin)
    |--------------------------------------------------------------------------
    */
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Opt-in two-factor for the signed-in user's own account.
    Route::prefix('profile/two-factor')->name('two-factor.')->group(function () {
        Route::get('/', [TwoFactorController::class, 'show'])->name('show');
        Route::post('/', [TwoFactorController::class, 'enable'])->name('enable');
        Route::post('/confirm', [TwoFactorController::class, 'confirm'])->name('confirm');
        Route::post('/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('recovery-codes');
        Route::delete('/', [TwoFactorController::class, 'disable'])->name('disable');
    });

    Route::get('/dashboard', [ReportController::class, 'dashboard'])->name('dashboard');
    Route::post('/settings/theme', [SettingController::class, 'updateTheme'])->name('settings.theme');

    Route::prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [POSController::class, 'index'])->name('index');
        Route::post('/add/{id}', [POSController::class, 'addToCart'])->name('add');
        Route::post('/remove/{id}', [POSController::class, 'removeFromCart'])->name('remove');
        Route::post('/update/{id}', [POSController::class, 'updateCartItem'])->name('update');
        Route::post('/discount/{id}', [POSController::class, 'applyLineDiscount'])->name('discount.line');
        Route::post('/discount', [POSController::class, 'applyOrderDiscount'])->name('discount.order');
        Route::post('/checkout', [POSController::class, 'checkout'])->name('checkout');
        Route::post('/clear', [POSController::class, 'clearCart'])->name('clear');
        Route::get('/print/{sale}', [POSController::class, 'printReceipt'])->name('print');
        Route::get('/search', [POSController::class, 'searchProducts'])->name('search');

        // Barcode scanner: resolve one exact barcode and add it in a single step.
        Route::post('/scan', [POSController::class, 'scan'])->name('scan');
        // Attach / detach the customer a sale belongs to (needed for credit sales).
        Route::post('/customer', [POSController::class, 'setCustomer'])->name('customer');

        /*
        | Offline sales. Power and network cuts are routine in the markets this
        | app is sold into, so the till queues sales locally and replays them here
        | when the connection returns. Every sale carries a client-generated id so
        | a replay can never bank the same sale twice.
        */
        Route::post('/sync', [OfflineSyncController::class, 'store'])->name('sync');
        Route::get('/sync/status', [OfflineSyncController::class, 'status'])->name('sync.status');

        // Held ("parked") carts: park a sale, serve someone else, resume it later.
        Route::get('/parked', [ParkedSaleController::class, 'index'])->name('parked');
        Route::post('/park', [ParkedSaleController::class, 'store'])->name('park');
        Route::post('/parked/{parkedSale}/resume', [ParkedSaleController::class, 'resume'])->name('parked.resume');
        Route::delete('/parked/{parkedSale}', [ParkedSaleController::class, 'destroy'])->name('parked.destroy');
    });

    // Sales history: managers see everything, cashiers only their own sales today (see SalePolicy).
    Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
    Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('sales.show');

    // Send a receipt instead of printing it. (WhatsApp needs no route - the
    // share service returns a plain wa.me link a view renders as an anchor.)
    Route::post('/sales/{sale}/receipt/email', [ReceiptShareController::class, 'email'])->name('sales.receipt.email');

    /*
    | Till shifts. A cashier opens and closes their own shift; managers see
    | everyone's and can review the cash count (see ShiftPolicy).
    */
    Route::prefix('shifts')->name('shifts.')->group(function () {
        Route::get('/', [ShiftController::class, 'index'])->name('index');
        Route::post('/open', [ShiftController::class, 'open'])->name('open');
        Route::get('/{shift}', [ShiftController::class, 'show'])->name('show');
        Route::post('/{shift}/close', [ShiftController::class, 'close'])->name('close');
    });

    /*
    | Branches. Switching is for anyone who works at more than one site; the
    | branches themselves, and stock moving between them, are a manager's job.
    | All of it 404s for a single-site shop (feature('branches')).
    */
    Route::post('/branches/switch', [BranchController::class, 'switch'])->name('branches.switch');

    // Customer lookup at the till needs to work for cashiers; managing them does not.
    Route::get('/customers/search', [CustomerController::class, 'search'])->name('customers.search');

    /*
    | Table service (restaurants, bars, cafes).
    |
    | A different shape of sale: the order is opened against a table, added to
    | through the meal, and only settled at the end. Serving staff need all of
    | this, so it sits with the any-signed-in-user routes; only managing the
    | tables themselves is restricted. The controllers 404 when the business type
    | has no tables (feature('tables')).
    */
    Route::get('/tables', [TableController::class, 'index'])->name('tables.index');

    Route::prefix('table-orders')->name('table-orders.')->group(function () {
        Route::post('/table/{table}', [TableOrderController::class, 'store'])->name('store');
        Route::get('/{tableOrder}', [TableOrderController::class, 'show'])->name('show');
        Route::post('/{tableOrder}/items', [TableOrderController::class, 'addItem'])->name('items.store');
        Route::patch('/{tableOrder}/items/{item}', [TableOrderController::class, 'updateItem'])->name('items.update');
        Route::delete('/{tableOrder}/items/{item}', [TableOrderController::class, 'removeItem'])->name('items.destroy');
        Route::post('/{tableOrder}/kitchen', [TableOrderController::class, 'sendToKitchen'])->name('kitchen');
        Route::post('/{tableOrder}/transfer', [TableOrderController::class, 'transfer'])->name('transfer');
        Route::post('/{tableOrder}/settle', [TableOrderController::class, 'settle'])->name('settle');
        Route::delete('/{tableOrder}', [TableOrderController::class, 'destroy'])->name('destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Managers and admins
    |
    | NOTE: middleware must be applied *before* ->group(). The old code chained
    | ->middleware() after ->group(), which runs after the routes are already
    | registered and therefore protected nothing.
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin|manager')->group(function () {
        /*
        | Declared before the products resource so "products/import" is never
        | taken for a product id.
        */
        Route::prefix('products')->name('products.')->group(function () {
            Route::get('/import', [ProductImportController::class, 'form'])->name('import.form');
            Route::post('/import', [ProductImportController::class, 'store'])->name('import.store');
            Route::get('/import/template', [ProductImportController::class, 'template'])->name('import.template');
            Route::get('/export', [ProductImportController::class, 'export'])->name('export');
        });

        Route::resource('products', ProductController::class)->except(['show', 'create', 'edit']);
        Route::delete('products/{product}/image', [ProductController::class, 'destroyImage'])->name('products.image.destroy');

        /*
        | Variants. A variant IS a product (it has its own SKU, barcode, price and
        | stock), so editing and deleting one is the ordinary product update and
        | delete above. Only creating has its own door, because that is where the
        | one-level-only rule is enforced.
        */
        Route::post('products/{product}/variants', [ProductController::class, 'storeVariant'])->name('products.variants.store');
        Route::post('products/{product}/variants/generate', [ProductController::class, 'generateVariants'])->name('products.variants.generate');

        // Serial / IMEI numbers: entered or corrected against a sale, and looked up
        // afterwards, which is the whole point of recording them (warranty claims).
        Route::get('serials', [SerialController::class, 'index'])->name('serials.index');
        Route::get('sales/{sale}/serials', [SerialController::class, 'edit'])->name('serials.edit');
        Route::put('sales/{sale}/serials', [SerialController::class, 'update'])->name('serials.update');

        Route::resource('categories', CategoryController::class)->except(['show', 'create', 'edit']);

        // The tables themselves (adding, renaming, retiring) are a manager's job;
        // using them is not (see the table-service routes above).
        Route::resource('tables', TableController::class)->only(['store', 'update', 'destroy']);

        // Suppliers were add-only; they now have a full list, edit, delete and a ledger.
        Route::resource('suppliers', SupplierController::class)->except(['create', 'edit']);
        Route::post('suppliers/{supplier}/payments', [SupplierPaymentController::class, 'store'])->name('suppliers.payments.store');
        Route::delete('supplier-payments/{payment}', [SupplierPaymentController::class, 'destroy'])->name('suppliers.payments.destroy');

        // Customers, their credit balances and the payments against them.
        Route::resource('customers', CustomerController::class)->except(['create', 'edit']);
        Route::post('customers/{customer}/payments', [CustomerPaymentController::class, 'store'])->name('customers.payments.store');
        Route::delete('customer-payments/{payment}', [CustomerPaymentController::class, 'destroy'])->name('customers.payments.destroy');

        Route::post('/sales/{sale}/refund', [SaleController::class, 'refund'])->name('sales.refund');
        Route::post('/sales/{sale}/void', [SaleController::class, 'void'])->name('sales.void');

        Route::prefix('purchases')->name('purchases.')->group(function () {
            Route::get('/', [PurchaseController::class, 'index'])->name('index');
            Route::get('/create', [PurchaseController::class, 'create'])->name('create');
            Route::post('/', [PurchaseController::class, 'store'])->name('store');
            Route::get('/{purchase}', [PurchaseController::class, 'show'])->name('show');
            Route::get('/{purchase}/edit', [PurchaseController::class, 'edit'])->name('edit');
            Route::put('/{purchase}', [PurchaseController::class, 'update'])->name('update');
            Route::delete('/{purchase}', [PurchaseController::class, 'destroy'])->name('destroy');

            // Send stock back to the supplier (damaged, wrong item, over-delivery).
            Route::post('/{purchase}/returns', [PurchaseReturnController::class, 'store'])->name('returns.store');
        });

        Route::get('/purchase-returns', [PurchaseReturnController::class, 'index'])->name('purchases.returns.index');

        /*
        | Stock control: a counted stock-take that is reconciled and approved,
        | plus one-off adjustments with a reason (damage, theft, expiry).
        */
        Route::prefix('stock-takes')->name('stock-takes.')->group(function () {
            Route::get('/', [StockTakeController::class, 'index'])->name('index');
            Route::get('/create', [StockTakeController::class, 'create'])->name('create');
            Route::post('/', [StockTakeController::class, 'store'])->name('store');
            Route::get('/{stockTake}', [StockTakeController::class, 'show'])->name('show');
            Route::put('/{stockTake}', [StockTakeController::class, 'update'])->name('update');
            Route::post('/{stockTake}/approve', [StockTakeController::class, 'approve'])->name('approve');
            Route::delete('/{stockTake}', [StockTakeController::class, 'destroy'])->name('destroy');
        });

        Route::resource('branches', BranchController::class)->except(['create', 'edit']);

        // Moving stock from one branch to another: raised at one end, received at
        // the other, so stock in transit is never counted in two places at once.
        Route::prefix('transfers')->name('transfers.')->group(function () {
            Route::get('/', [StockTransferController::class, 'index'])->name('index');
            Route::get('/create', [StockTransferController::class, 'create'])->name('create');
            Route::post('/', [StockTransferController::class, 'store'])->name('store');
            Route::get('/{transfer}', [StockTransferController::class, 'show'])->name('show');
            Route::post('/{transfer}/receive', [StockTransferController::class, 'receive'])->name('receive');
            Route::post('/{transfer}/cancel', [StockTransferController::class, 'cancel'])->name('cancel');
        });

        Route::post('/adjustments', [AdjustmentController::class, 'store'])->name('adjustments.store');
        Route::get('/adjustments', [AdjustmentController::class, 'index'])->name('adjustments.index');

        // What to reorder, grouped by supplier, and a purchase order built from it.
        Route::get('/reorder', [ReorderController::class, 'index'])->name('reorder.index');
        Route::post('/reorder/purchase-order', [PurchaseController::class, 'storeFromReorder'])->name('reorder.purchase-order');

        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/sales', [ReportController::class, 'index'])->name('sales');
            Route::get('/sales/pdf', [ReportController::class, 'exportPDF'])->name('sales.report.pdf');
            Route::get('/sales/csv', [ReportController::class, 'exportCSV'])->name('sales.report.csv');
            Route::get('/daily', [ReportController::class, 'dailySales'])->name('daily');
            Route::get('/movement', [ReportController::class, 'movement'])->name('movement');
            Route::get('/profit-loss', [ReportController::class, 'profitLoss'])->name('profit_loss');

            Route::get('/cashiers', [ReportController::class, 'byCashier'])->name('cashiers');
            Route::get('/categories', [ReportController::class, 'byCategory'])->name('categories');
            Route::get('/products', [ReportController::class, 'byProduct'])->name('products');
            Route::get('/inventory-valuation', [ReportController::class, 'inventoryValuation'])->name('valuation');
            Route::get('/receivables', [ReportController::class, 'receivables'])->name('receivables');
            Route::get('/payables', [ReportController::class, 'payables'])->name('payables');
            Route::get('/expiry', [ReportController::class, 'expiry'])->name('expiry');
        });

        Route::prefix('expenses')->name('expenses.')->group(function () {
            Route::get('/', [ExpenseController::class, 'index'])->name('index');
            Route::post('/', [ExpenseController::class, 'store'])->name('store');
            Route::put('/{expense}', [ExpenseController::class, 'update'])->name('update');
            Route::delete('/{expense}', [ExpenseController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/', [SettingController::class, 'index'])->name('index');
            Route::post('/', [SettingController::class, 'update'])->name('update');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Admins only: user management and raw data access
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')->prefix('settings')->name('settings.')->group(function () {
        Route::post('users', [UserController::class, 'store'])->name('users.create');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
        Route::post('users/{user}/status', [UserController::class, 'toggleStatus'])->name('users.status');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.delete');

        // The audit log is written all over the app but could not be read in it.
        Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit');
        Route::get('audit-log/csv', [AuditLogController::class, 'exportCsv'])->name('audit.csv');

        Route::post('/backup/download', [SettingController::class, 'downloadBackup'])->name('backup.download');
        Route::get('/backup/export/{table}', [SettingController::class, 'exportCsv'])->name('backup.export');
        Route::get('/backups', [SettingController::class, 'backups'])->name('backups');
        Route::post('/backups/run', [SettingController::class, 'runBackup'])->name('backups.run');
        Route::get('/backups/{backup}/download', [SettingController::class, 'downloadStoredBackup'])->name('backups.download');
    });
});

require __DIR__ . '/auth.php';
