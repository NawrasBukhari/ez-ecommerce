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
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('ez-ecommerce.currency.default', 'AED');
        config()->set('ez-ecommerce.tax.rate', 0.05);
        config()->set('ez-ecommerce.shipping.flat_rate_minor', 1000);
        config()->set('ez-ecommerce.features.api', true);
        config()->set('ez-ecommerce.api.token', 'test-api-token');
        config()->set('ez-ecommerce.api.scoped_tokens', [
            'test-api-token' => ['*'],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
