<?php

namespace App\Providers;

use App\Models\Product;
use App\Observers\ProductStockObserver;
use App\Services\BranchContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * "Which branch am I acting as?" is one question with one answer per
         * request, and several things ask it on every query - the global scope,
         * the row stamp, the stock mirror. A singleton means it is worked out
         * once and, just as importantly, that App\Http\Middleware\SetActiveBranch
         * and everything downstream are talking about the same object.
         */
        $this->app->singleton(BranchContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Keeps per-branch stock in step with `products.stock_quantity`, which is
         * what lets the till, purchases, refunds, stock-takes and adjustments go
         * on moving stock exactly as they always have while still landing on the
         * right branch's shelf. The observer's docblock has the full argument.
         */
        Product::observe(ProductStockObserver::class);
    }
}
