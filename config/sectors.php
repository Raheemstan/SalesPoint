<?php

/*
|--------------------------------------------------------------------------
| Business types (trades)
|--------------------------------------------------------------------------
|
| SalesPoint is one codebase sold into very different shops. A pharmacy needs
| expiry dates, a salon sells services that have no stock at all, a wholesaler
| lives on credit, a restaurant runs tables. Rather than a different build per
| trade, the shop picks its business type in Settings > Business and that
| preset decides:
|
|   - terms:    the nouns shown in the interface (a pharmacy sells "drugs",
|               a restaurant sells "menu items", a salon sells "services")
|   - features: which optional screens and fields appear
|
| Read them with the sector(), term() and feature() helpers. Every feature can
| still be forced on or off per install with a "feature_<name>" setting, so a
| shop is never boxed in by the preset it chose.
|
| Adding a trade is just another entry here - no code changes.
|
*/

return [

    'default' => 'retail',

    /*
    | Fallback currency, used until the shop sets its own in Settings.
    | Never hardcode a symbol in a view; call money() / currency().
    */
    'currency' => [
        'symbol' => '₦',
        'code' => 'NGN',
        'decimals' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | The features a preset may switch on
    |--------------------------------------------------------------------------
    |
    | Keep this list as the single description of what each flag means: the
    | Settings screen builds its toggles from it.
    |
    */
    'features' => [
        'stock' => 'Track stock quantities and warn when an item runs low',
        'service_items' => 'Allow items with no stock at all (labour, services, fees)',
        'units' => 'Buy and sell in different units (carton, pack, piece)',
        'weight' => 'Sell by weight or length, with decimal quantities',
        'variants' => 'Variants of one item (size, colour, flavour)',
        'expiry' => 'Batch numbers and expiry dates, with expiry alerts',
        'serials' => 'Record a serial / IMEI for each unit sold',
        'credit' => 'Sell on credit and keep customer balances',
        'tables' => 'Table or room service: open an order, add to it, settle later',
        'kitchen' => 'Print a preparation ticket to the kitchen or bar',
        'appointments' => 'Book customers in for a time slot',
        'prescriptions' => 'Record the prescriber and prescription against a sale',
        'tips' => 'Take a tip or service charge at the till',
        'loyalty' => 'Award and redeem loyalty points',
    ],

    /*
    |--------------------------------------------------------------------------
    | Presets
    |--------------------------------------------------------------------------
    |
    | 'terms' keys used by the interface: product, sale, customer, supplier,
    | purchase, category, cashier. Add "<key>_plural" when the plural is not
    | simply the singular + s.
    |
    */
    'types' => [

        'retail' => [
            'label' => 'Retail / general store',
            'description' => 'Sells goods over a counter. The default: stock, suppliers and purchases, no extras.',
            'terms' => [
                'product' => 'Product',
                'sale' => 'Sale',
                'customer' => 'Customer',
            ],
            'features' => [
                'stock' => true,
                'units' => true,
                'credit' => true,
            ],
        ],

        'supermarket' => [
            'label' => 'Supermarket / grocery',
            'description' => 'High volume, barcode scanning, short shelf lives and goods sold by weight.',
            'terms' => [
                'product' => 'Product',
                'sale' => 'Sale',
                'customer' => 'Customer',
            ],
            'features' => [
                'stock' => true,
                'units' => true,
                'weight' => true,
                'expiry' => true,
                'loyalty' => true,
            ],
        ],

        'pharmacy' => [
            'label' => 'Pharmacy / chemist',
            'description' => 'Batch numbers and expiry dates are the point; sales can carry a prescriber.',
            'terms' => [
                'product' => 'Drug',
                'product_plural' => 'Drugs',
                'sale' => 'Sale',
                'customer' => 'Patient',
                'customer_plural' => 'Patients',
                'category' => 'Drug class',
                'category_plural' => 'Drug classes',
            ],
            'features' => [
                'stock' => true,
                'units' => true,
                'expiry' => true,
                'prescriptions' => true,
                'credit' => true,
            ],
        ],

        'restaurant' => [
            'label' => 'Restaurant / bar / cafe',
            'description' => 'Orders are opened against a table, added to through the meal, then settled.',
            'terms' => [
                'product' => 'Menu item',
                'product_plural' => 'Menu',
                'sale' => 'Order',
                'customer' => 'Guest',
                'category' => 'Menu section',
                'cashier' => 'Server',
            ],
            'features' => [
                'stock' => true,
                'service_items' => true,
                'tables' => true,
                'kitchen' => true,
                'tips' => true,
            ],
        ],

        'services' => [
            'label' => 'Services / repairs / professional',
            'description' => 'Sells labour and time rather than goods, so nothing is counted in or out of stock.',
            'terms' => [
                'product' => 'Service',
                'sale' => 'Invoice',
                'customer' => 'Client',
                'purchase' => 'Expense',
            ],
            'features' => [
                'stock' => false,
                'service_items' => true,
                'credit' => true,
                'appointments' => true,
            ],
        ],

        'salon' => [
            'label' => 'Salon / spa / barber',
            'description' => 'Services booked by appointment, products sold alongside, staff take tips.',
            'terms' => [
                'product' => 'Service',
                'sale' => 'Sale',
                'customer' => 'Client',
                'cashier' => 'Stylist',
            ],
            'features' => [
                'stock' => true,
                'service_items' => true,
                'appointments' => true,
                'tips' => true,
                'loyalty' => true,
            ],
        ],

        'wholesale' => [
            'label' => 'Wholesale / distribution',
            'description' => 'Sells by the carton to businesses that pay later, so credit and units matter most.',
            'terms' => [
                'product' => 'Product',
                'sale' => 'Invoice',
                'customer' => 'Account',
            ],
            'features' => [
                'stock' => true,
                'units' => true,
                'weight' => true,
                'credit' => true,
            ],
        ],

        'hardware' => [
            'label' => 'Hardware / building materials',
            'description' => 'Goods cut or measured to order, bought in bulk, often sold on account.',
            'terms' => [
                'product' => 'Item',
                'sale' => 'Sale',
                'customer' => 'Customer',
            ],
            'features' => [
                'stock' => true,
                'units' => true,
                'weight' => true,
                'credit' => true,
            ],
        ],

        'fashion' => [
            'label' => 'Fashion / clothing / footwear',
            'description' => 'One style in many sizes and colours, each counted separately.',
            'terms' => [
                'product' => 'Product',
                'sale' => 'Sale',
                'customer' => 'Customer',
            ],
            'features' => [
                'stock' => true,
                'variants' => true,
                'loyalty' => true,
            ],
        ],

        'electronics' => [
            'label' => 'Electronics / phones',
            'description' => 'Individually identifiable units: a serial or IMEI is recorded on every sale.',
            'terms' => [
                'product' => 'Product',
                'sale' => 'Sale',
                'customer' => 'Customer',
            ],
            'features' => [
                'stock' => true,
                'serials' => true,
                'variants' => true,
                'credit' => true,
            ],
        ],
    ],
];
