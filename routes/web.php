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
    Route::resource('purchases', PurchaseController::class);
    Route::resource('expenses', ExpenseController::class);
    Route::get('reports/sales', [ReportController::class, 'index'])->name('reports.sales');
    Route::get('report/sales/pdf', [ReportController::class, 'exportPDF'])->name('sales.report.pdf');
    Route::get('report/sales/csv', [ReportController::class, 'exportCSV'])->name('sales.report.csv');
    Route::get('/reports/daily', [ReportController::class, 'dailySales'])->name('reports.daily');
    Route::get('/dashboard', [ReportController::class, 'dailySales'])->name('dashboard');
    Route::get('reports/movement', [ReportController::class, 'movement'])->name('reports.movement');

    Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
    Route::post('settings', [SettingController::class, 'update'])->name('settings.update');

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
