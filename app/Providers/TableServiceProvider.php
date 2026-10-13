<?php

namespace App\Providers;

use App\Models\Sale;
use App\Observers\TableOrderSaleObserver;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for restaurant table service.
 *
 * It exists for exactly one reason: to attach TableOrderSaleObserver to Sale.
 *
 * The observer has to hang off `Sale`, which this feature does not own, and
 * `#[ObservedBy]` would mean editing App\Models\Sale. A provider is the one way
 * to register it without touching another feature's files, so table service
 * brings its own rather than adding a line to AppServiceProvider (which the
 * whole app shares).
 *
 * No feature() check here: a shop can switch `tables` on and off in Settings at
 * any time, and the observer is harmless when it is off - it does nothing unless
 * a table order put its id in the session, which only the table screens do.
 */
class TableServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sale::observe(TableOrderSaleObserver::class);
    }
}
