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
        $connection = getenv('DB_CONNECTION') ?: '';

        if (in_array($connection, ['mysql', 'pgsql'], true)) {
            // Honor CI DB_* env vars so the installation test runs on the same
            // MySQL/PostgreSQL connection the rest of the hardening suite uses.
            // Mirrors tests/bin/worker.php. foreign_key_constraints is not a
            // per-connection flag on MySQL/PG, so the duplicate-insert test
            // disables FKs explicitly via Schema::disableForeignKeyConstraints().
            config()->set('database.default', $connection);
            config()->set("database.connections.{$connection}", [
                'driver' => $connection,
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: ($connection === 'pgsql' ? '5432' : '3306'),
                'database' => getenv('DB_DATABASE') ?: 'testing',
                'username' => getenv('DB_USERNAME') ?: ($connection === 'pgsql' ? 'postgres' : 'root'),
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => $connection === 'pgsql' ? 'utf8' : 'utf8mb4',
                'prefix' => '',
            ]);
        } else {
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
        }

        config()->set('ez-ecommerce.currency.default', 'AED');
        config()->set('ez-ecommerce.features.api', true);
        config()->set('ez-ecommerce.api.token', 'test-api-token');
        config()->set('ez-ecommerce.api.scoped_tokens', ['test-api-token' => ['*']]);
    }
}
