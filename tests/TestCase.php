<?php

namespace EzEcommerce\Tests;

use EzEcommerce\EzEcommerceServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [EzEcommerceServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $connection = env('DB_CONNECTION', 'testing');

        if (in_array($connection, ['mysql', 'pgsql'], true)) {
            config()->set('database.default', $connection);
            config()->set("database.connections.{$connection}", [
                'driver' => $connection,
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', $connection === 'pgsql' ? '5432' : '3306'),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', $connection === 'pgsql' ? 'postgres' : 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => $connection === 'pgsql' ? 'utf8' : 'utf8mb4',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        } else {
            config()->set('database.default', 'testing');
            config()->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }

        config()->set('ez-ecommerce.currency.default', 'AED');
        config()->set('ez-ecommerce.tax.rate', 0.05);
        config()->set('ez-ecommerce.shipping.flat_rate_minor', 1000);
        config()->set('ez-ecommerce.features.api', true);
        config()->set('ez-ecommerce.features.subscriptions', true);
        config()->set('ez-ecommerce.features.marketplace', true);
        config()->set('ez-ecommerce.features.multi_store', true);
        config()->set('ez-ecommerce.features.b2b', true);
        config()->set('ez-ecommerce.features.outbound_webhooks', true);
        config()->set('ez-ecommerce.api.token', 'test-api-token');
        config()->set('ez-ecommerce.api.scoped_tokens', [
            'test-api-token' => ['*'],
        ]);
        config()->set('ez-ecommerce.checkout.public_payment_methods', [
            'stripe', 'paypal', 'telr', 'manual', 'fake', 'null',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
