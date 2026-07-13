<?php

namespace EzEcommerce\Tests;

use EzEcommerce\EzEcommerceServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Isolated Testbench base for the package-installation regression test.
 *
 * Unlike the general TestCase, this does NOT override defineDatabaseMigrations
 * and does NOT call loadMigrationsFrom. Migrations are discovered purely via
 * the package's Spatie runsMigrations() path — the same path a real host uses
 * when it runs `php artisan migrate`. This proves migration discovery works
 * without the test manually loading migrations.
 */
abstract class InstallationTestCase extends Orchestra
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
            // FK off so the unique-index duplicate-insert assertion can run
            // without standing up a full order/payment/attempt graph. This base
            // only verifies schema, indexes, and command registration.
            'foreign_key_constraints' => false,
        ]);

        config()->set('ez-ecommerce.currency.default', 'AED');
        config()->set('ez-ecommerce.features.api', true);
        config()->set('ez-ecommerce.api.token', 'test-api-token');
        config()->set('ez-ecommerce.api.scoped_tokens', ['test-api-token' => ['*']]);
    }
}
