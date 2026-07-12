<?php

return [

    'currency' => [
        'default' => env('COMMERCE_CURRENCY', 'AED'),
        'rounding' => 'half_up',
    ],

    'pricing' => [
        'precedence' => ['customer', 'customer_group', 'price_list', 'sale', 'base'],
        'tax_after_discounts' => true,
        'shipping_taxable' => true,
    ],

    'drivers' => [
        'payment' => [
            'default' => 'manual',
        ],
        'shipping' => [
            'default' => 'flat',
        ],
        'tax' => [
            'default' => 'simple',
        ],
    ],

    'tax' => [
        'rate' => (float) env('COMMERCE_TAX_RATE', 0.05),
    ],

    'shipping' => [
        'flat_rate_minor' => (int) env('COMMERCE_SHIPPING_FLAT_MINOR', 0),
    ],

    'inventory' => [
        'default_warehouse_id' => null,
        'reservation_ttl' => [
            'default' => 30,
            'manual' => 1440,
            'card' => 30,
            'null' => 0,
        ],
    ],

    'features' => [
        'api' => false,
        'subscriptions' => false,
        'marketplace' => false,
        'multi_store' => false,
        'b2b' => false,
        'outbound_webhooks' => false,
    ],

    'api' => [
        'prefix' => 'api/ez-commerce/v1',
        'middleware' => ['api'],
    ],

    'idempotency' => [
        'ttl_minutes' => 1440,
        'lock_ttl_seconds' => 60,
    ],

];
