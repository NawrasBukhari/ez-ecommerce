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
use EzEcommerce\Commands\DedupeTransactionsCommand;
use EzEcommerce\Commands\ProcessOutboxCommand;
use EzEcommerce\Commands\PurgeExpiredCartsCommand;
use EzEcommerce\Commands\PurgeIdempotencyRecordsCommand;
use EzEcommerce\Commands\ReconcileFinalizationsCommand;
use EzEcommerce\Commands\ReconcilePaymentsCommand;
use EzEcommerce\Commands\ReconcileRefundsCommand;
use EzEcommerce\Commands\ReconcileVoidsCommand;
use EzEcommerce\Commands\ReleaseExpiredReservationsCommand;
use EzEcommerce\Commands\RenewSubscriptionsCommand;
use EzEcommerce\Commands\ReplayWebhooksCommand;
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
            ->discoversMigrations()
            ->runsMigrations()
            ->hasCommands([
                CommerceInstallCommand::class,
                ReleaseExpiredReservationsCommand::class,
                RenewSubscriptionsCommand::class,
                PurgeExpiredCartsCommand::class,
                PurgeIdempotencyRecordsCommand::class,
                ReconcilePaymentsCommand::class,
                ReconcileRefundsCommand::class,
                ReconcileFinalizationsCommand::class,
                ReconcileVoidsCommand::class,
                DedupeTransactionsCommand::class,
                ProcessOutboxCommand::class,
                ReplayWebhooksCommand::class,
            ]);
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
