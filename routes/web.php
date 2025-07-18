<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\POSController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect(route('pos.index'));
});


Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::resource('products', ProductController::class)->except(['show', 'create', 'edit']);
    Route::resource('categories', CategoryController::class)->except(['show', 'create', 'edit']);
    Route::resource('suppliers', SupplierController::class);

    Route::prefix('purchases')->name('purchases.')->group(function () {
        Route::get('/', [PurchaseController::class, 'index'])->name('index');
        Route::get('/create', [PurchaseController::class, 'create'])->name('create');
        Route::post('/', [PurchaseController::class, 'store'])->name('store');
        Route::get('/{purchase}/edit', [PurchaseController::class, 'edit'])->name('edit');
        Route::get('/{purchase}', [PurchaseController::class, 'show'])->name('show');
        Route::delete('/{purchase}', [PurchaseController::class, 'destroy'])->name('destroy');
    });

    Route::get('/dashboard', [ReportController::class, 'dailySales'])->name('dashboard');

    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/sales', [ReportController::class, 'index'])->name('sales');
        Route::get('/sales/pdf', [ReportController::class, 'exportPDF'])->name('sales.report.pdf');
        Route::get('/sales/csv', [ReportController::class, 'exportCSV'])->name('sales.report.csv');
        Route::get('/daily', [ReportController::class, 'dailySales'])->name('daily');
        Route::get('/movement', [ReportController::class, 'movement'])->name('movement');
        Route::get('/profit-loss', [ReportController::class, 'profitLoss'])->name('profit_loss');
    })->middleware(['role:admin|manager']);

    Route::prefix('expenses')->name('expenses.')->group(function () {
        Route::get('/', [ExpenseController::class, 'index'])->name('index');
        Route::post('/', [ExpenseController::class, 'store'])->name('store');
        Route::put('/{expense}', [ExpenseController::class, 'update'])->name('update');
        Route::delete('/{expense}', [ExpenseController::class, 'destroy'])->name('destroy');
    })->middleware(['role:admin|manager']);

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingController::class, 'index'])->name('index');
        Route::post('/', [SettingController::class, 'update'])->name('update');
        Route::get('users', [SettingController::class, 'index'])->name('users.index');
        Route::post('users', [SettingController::class, 'createUser'])->name('users.create');
        Route::delete('users/{user}', [SettingController::class, 'deleteUser'])->name('users.delete')->middleware('role:admin');
        Route::post('/backup/download', [SettingController::class, 'downloadBackup'])->name('backup.download');
        Route::get('/backup/export/{table}', [SettingController::class, 'exportCsv'])->name('backup.export');
    })->middleware(['role:admin|manager']);

    Route::prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [POSController::class, 'index'])->name('index');
        Route::post('/add/{id}', [POSController::class, 'addToCart'])->name('add');
        Route::post('/remove/{id}', [POSController::class, 'removeFromCart'])->name('remove');
        Route::post('/update/{id}', [POSController::class, 'updateCartItem'])->name('update');
        Route::post('/checkout', [POSController::class, 'checkout'])->name('checkout');
        Route::post('/clear', [POSController::class, 'clearCart'])->name('clear');
        Route::get('/print/{sale}', [POSController::class, 'printReceipt'])->name('print');
        Route::get('/search', [POSController::class, 'searchProducts'])->name('search');
    });
});



require __DIR__ . '/auth.php';
