<?php

namespace EzEcommerce;

use EzEcommerce\Api\ApiServiceProvider;
use EzEcommerce\B2B\B2BServiceProvider;
use EzEcommerce\Cart\CartManager;
use EzEcommerce\Cart\CartServiceProvider;
use EzEcommerce\Catalog\CatalogManager;
use EzEcommerce\Catalog\CatalogServiceProvider;
use EzEcommerce\Checkout\CheckoutManager;
use EzEcommerce\Checkout\CheckoutServiceProvider;
use EzEcommerce\Commands\CommerceInstallCommand;
use EzEcommerce\Commands\PurgeExpiredCartsCommand;
use EzEcommerce\Commands\PurgeIdempotencyRecordsCommand;
use EzEcommerce\Commands\ReconcilePaymentsCommand;
use EzEcommerce\Commands\ReconcileRefundsCommand;
use EzEcommerce\Commands\ReleaseExpiredReservationsCommand;
use EzEcommerce\Commands\RenewSubscriptionsCommand;
use EzEcommerce\Core\CoreServiceProvider;
use EzEcommerce\Customers\CustomersServiceProvider;
use EzEcommerce\Discounts\DiscountsServiceProvider;
use EzEcommerce\Fulfillment\FulfillmentServiceProvider;
use EzEcommerce\Inventory\InventoryManager;
use EzEcommerce\Inventory\InventoryServiceProvider;
use EzEcommerce\Marketplace\MarketplaceServiceProvider;
use EzEcommerce\Orders\OrderManager;
use EzEcommerce\Orders\OrdersManager;
use EzEcommerce\Orders\OrdersServiceProvider;
use EzEcommerce\Payments\PaymentsServiceProvider;
use EzEcommerce\Pricing\PricingServiceProvider;
use EzEcommerce\Refunds\RefundsServiceProvider;
use EzEcommerce\Returns\ReturnsServiceProvider;
use EzEcommerce\Shipping\ShippingServiceProvider;
use EzEcommerce\Stores\StoresServiceProvider;
use EzEcommerce\Subscriptions\SubscriptionsServiceProvider;
use EzEcommerce\Taxes\TaxesServiceProvider;
use EzEcommerce\Webhooks\Inbound\InboundWebhooksServiceProvider;
use EzEcommerce\Webhooks\Outbound\OutboundWebhooksServiceProvider;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class EzEcommerceServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('ez-ecommerce')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasMigrations([
                'create_commerce_idempotency_records_table',
                'create_commerce_products_table',
                'create_commerce_product_variants_table',
                'create_commerce_categories_table',
                'create_commerce_category_product_table',
                'create_commerce_price_lists_table',
                'create_commerce_prices_table',
                'create_commerce_warehouses_table',
                'create_commerce_inventory_balances_table',
                'create_commerce_inventory_movements_table',
                'create_commerce_inventory_reservations_table',
                'create_commerce_customer_groups_table',
                'create_commerce_customers_table',
                'create_commerce_addresses_table',
                'create_commerce_carts_table',
                'create_commerce_cart_items_table',
                'create_commerce_cart_adjustments_table',
                'create_commerce_discounts_table',
                'create_commerce_orders_table',
                'create_commerce_order_items_table',
                'create_commerce_order_adjustments_table',
                'create_commerce_order_transitions_table',
                'create_commerce_payments_table',
                'create_commerce_payment_attempts_table',
                'create_commerce_payment_transactions_table',
                'create_commerce_refunds_table',
                'create_commerce_fulfillments_table',
                'create_commerce_inbound_webhooks_table',
                'create_commerce_processed_gateway_events_table',
                'create_commerce_stores_table',
                'add_store_id_to_commerce_tables',
                'create_commerce_companies_table',
                'add_company_id_to_commerce_customers_table',
                'create_commerce_returns_table',
                'create_commerce_return_items_table',
                'create_commerce_webhook_endpoints_table',
                'create_commerce_webhook_deliveries_table',
                'create_commerce_subscription_plans_table',
                'create_commerce_subscriptions_table',
                'create_commerce_subscription_items_table',
                'create_commerce_vendors_table',
                'add_vendor_id_to_commerce_products_table',
                'create_commerce_vendor_commissions_table',
                'create_commerce_outbox_messages_table',
                'add_customer_group_id_to_commerce_customers_table',
                'add_metadata_to_commerce_carts_table',
                'add_order_customer_snapshots_and_addresses_table',
                'add_status_to_commerce_processed_gateway_events_table',
            ])
            ->hasCommand(CommerceInstallCommand::class)
            ->hasCommand(ReleaseExpiredReservationsCommand::class)
            ->hasCommand(RenewSubscriptionsCommand::class)
            ->hasCommand(PurgeExpiredCartsCommand::class)
            ->hasCommand(PurgeIdempotencyRecordsCommand::class)
            ->hasCommand(ReconcilePaymentsCommand::class)
            ->hasCommand(ReconcileRefundsCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(CommerceManager::class);
        $this->app->alias(CommerceManager::class, 'ez-ecommerce');

        $this->app->singleton(CartManager::class);
        $this->app->singleton(CheckoutManager::class);
        $this->app->singleton(CatalogManager::class);
        $this->app->singleton(InventoryManager::class);
        $this->app->singleton(OrdersManager::class);
        $this->app->singleton(OrderManager::class);

        foreach ($this->moduleProviders() as $provider) {
            $this->app->register($provider);
        }

        if (config('ez-ecommerce.features.api', false)) {
            $this->app->register(ApiServiceProvider::class);
        }
    }

    /**
     * @return list<class-string>
     */
    private function moduleProviders(): array
    {
        $providers = [
            CoreServiceProvider::class,
            CatalogServiceProvider::class,
            PricingServiceProvider::class,
            InventoryServiceProvider::class,
            CustomersServiceProvider::class,
            CartServiceProvider::class,
            DiscountsServiceProvider::class,
            TaxesServiceProvider::class,
            ShippingServiceProvider::class,
            CheckoutServiceProvider::class,
            OrdersServiceProvider::class,
            PaymentsServiceProvider::class,
            FulfillmentServiceProvider::class,
            RefundsServiceProvider::class,
            ReturnsServiceProvider::class,
            InboundWebhooksServiceProvider::class,
            StoresServiceProvider::class,
        ];

        if (config('ez-ecommerce.features.b2b', false)) {
            $providers[] = B2BServiceProvider::class;
        }

        if (config('ez-ecommerce.features.subscriptions', false)) {
            $providers[] = SubscriptionsServiceProvider::class;
        }

        if (config('ez-ecommerce.features.marketplace', false)) {
            $providers[] = MarketplaceServiceProvider::class;
        }

        if (config('ez-ecommerce.features.outbound_webhooks', false)) {
            $providers[] = OutboundWebhooksServiceProvider::class;
        }

        return $providers;
    }
}
