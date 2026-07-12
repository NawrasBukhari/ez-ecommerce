<?php

use EzEcommerce\Payments\Drivers\FakePaymentGateway;
use EzEcommerce\Payments\Drivers\ManualPaymentGateway;
use EzEcommerce\Payments\Drivers\NullPaymentGateway;
use EzEcommerce\Payments\Drivers\PayPalPaymentGateway;
use EzEcommerce\Payments\Drivers\StripePaymentGateway;
use EzEcommerce\Payments\Drivers\TelrPaymentGateway;

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
            'default' => env('COMMERCE_PAYMENT_DRIVER', 'manual'),
            'gateways' => [
                'null' => NullPaymentGateway::class,
                'manual' => ManualPaymentGateway::class,
                'net_terms' => ManualPaymentGateway::class,
                'fake' => FakePaymentGateway::class,
                'stripe' => StripePaymentGateway::class,
                'paypal' => PayPalPaymentGateway::class,
                'telr' => TelrPaymentGateway::class,
            ],
            'stripe' => [
                'secret' => env('STRIPE_SECRET'),
                'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            ],
            'paypal' => [
                'client_id' => env('PAYPAL_CLIENT_ID'),
                'client_secret' => env('PAYPAL_CLIENT_SECRET'),
                'mode' => env('PAYPAL_MODE', 'sandbox'),
                'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            ],
            'telr' => [
                'store_id' => env('TELR_STORE_ID'),
                'auth_key' => env('TELR_AUTH_KEY'),
                'test_mode' => env('TELR_TEST_MODE', true),
                'endpoint' => env('TELR_ENDPOINT', 'https://secure.telr.com/gateway/order.json'),
                'return_url' => env('TELR_RETURN_URL'),
                'checkout_url' => env('TELR_CHECKOUT_URL', 'https://secure.telr.com/gateway/process.html'),
            ],
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
        'jurisdictions' => [
            'AE' => 0.05,
            'US' => 0.08,
        ],
    ],

    'shipping' => [
        'flat_rate_minor' => (int) env('COMMERCE_SHIPPING_FLAT_MINOR', 0),
        'methods' => ['flat', 'weight'],
        'weight' => [
            'base_minor' => (int) env('COMMERCE_SHIPPING_WEIGHT_BASE_MINOR', 1000),
            'per_kg_minor' => (int) env('COMMERCE_SHIPPING_WEIGHT_PER_KG_MINOR', 500),
        ],
    ],

    'inventory' => [
        'default_warehouse_id' => null,
        'reservation_ttl' => [
            'default' => 30,
            'manual' => 1440,
            'net_terms' => 10080,
            'card' => 30,
            'null' => 0,
        ],
    ],

    'cart' => [
        'guest_ttl_days' => 30,
    ],

    'multi_store' => [
        'default_store_id' => env('COMMERCE_DEFAULT_STORE_ID'),
    ],

    'features' => [
        'api' => true,
        'subscriptions' => false,
        'marketplace' => false,
        'multi_store' => false,
        'b2b' => false,
        'outbound_webhooks' => false,
    ],

    'api' => [
        'prefix' => 'api/ez-commerce/v1',
        'middleware' => ['api'],
        'token' => env('COMMERCE_API_TOKEN'),
        'allow_unauthenticated' => filter_var(env('COMMERCE_API_ALLOW_UNAUTHENTICATED', false), FILTER_VALIDATE_BOOLEAN),
        'scoped_tokens' => array_filter(array_merge(
            filled(env('COMMERCE_API_TOKEN')) ? [env('COMMERCE_API_TOKEN') => ['*']] : [],
            filled(env('COMMERCE_API_CATALOG_TOKEN')) ? [env('COMMERCE_API_CATALOG_TOKEN') => ['catalog.read', 'catalog.write']] : [],
            filled(env('COMMERCE_API_INVENTORY_TOKEN')) ? [env('COMMERCE_API_INVENTORY_TOKEN') => ['inventory.read', 'inventory.write']] : [],
            filled(env('COMMERCE_API_ORDERS_TOKEN')) ? [env('COMMERCE_API_ORDERS_TOKEN') => ['orders.read', 'orders.write', 'returns.read', 'returns.write']] : [],
            filled(env('COMMERCE_API_CUSTOMERS_TOKEN')) ? [env('COMMERCE_API_CUSTOMERS_TOKEN') => ['customers.read', 'customers.write']] : [],
            filled(env('COMMERCE_API_MARKETPLACE_TOKEN')) ? [env('COMMERCE_API_MARKETPLACE_TOKEN') => ['marketplace.read', 'marketplace.write']] : [],
            filled(env('COMMERCE_API_STORES_TOKEN')) ? [env('COMMERCE_API_STORES_TOKEN') => ['stores.read', 'stores.write']] : [],
            filled(env('COMMERCE_API_COMPANIES_TOKEN')) ? [env('COMMERCE_API_COMPANIES_TOKEN') => ['companies.read', 'companies.write']] : [],
            filled(env('COMMERCE_API_SUBSCRIPTIONS_TOKEN')) ? [env('COMMERCE_API_SUBSCRIPTIONS_TOKEN') => ['subscriptions.read', 'subscriptions.write']] : [],
            filled(env('COMMERCE_API_ADMIN_TOKEN')) ? [env('COMMERCE_API_ADMIN_TOKEN') => ['*']] : [],
        ), static fn (mixed $scopes, mixed $token): bool => is_string($token) && $token !== '' && is_array($scopes), ARRAY_FILTER_USE_BOTH),
    ],

    'inbound_webhooks' => [
        'shared_secret' => env('COMMERCE_INBOUND_WEBHOOK_SECRET'),
        'allow_unsigned' => filter_var(env('COMMERCE_INBOUND_WEBHOOK_ALLOW_UNSIGNED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'outbound_webhooks' => [
        'secret' => env('COMMERCE_WEBHOOK_SECRET'),
        'endpoints' => [
            // ['url' => 'https://example.com/webhooks/commerce', 'events' => ['order.placed', 'order.paid']],
        ],
    ],

    'idempotency' => [
        'ttl_minutes' => 1440,
        'lock_ttl_seconds' => 60,
    ],

];
