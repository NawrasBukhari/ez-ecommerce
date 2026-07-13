<?php

/**
 * Worker bootstrap for two-process race tests.
 *
 * Usage: php tests/bin/worker.php <action> <params-json>
 *
 * Actions:
 *   fulfill       <{"order_id":1,"item_id":1,"qty":2,"key":"..."}>     create a fulfillment
 *   outbox        <{"order_id":1,"order_public_id":"...","payment_id":1}> insert an OrderPaid outbox row
 *   void          <{"payment_id":1,"key":"..."}>                        void a payment authorization
 *
 * Each action boots a fresh Laravel app via Orchestra Testbench, runs the package
 * service provider, and executes the action against the shared database.
 */

require __DIR__.'/../../vendor/autoload.php';

use EzEcommerce\Core\Jobs\ProcessOutboxJob;
use EzEcommerce\Core\Models\OutboxMessage;
use EzEcommerce\Fulfillment\Actions\CreateFulfillment;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderItem;
use EzEcommerce\Payments\Actions\VoidPaymentAuthorization;
use EzEcommerce\Payments\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Concerns\CreatesApplication;

class WorkerTestbench extends Orchestra\Testbench\TestCase
{
    use CreatesApplication;
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [EzEcommerce\EzEcommerceServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Use the DATABASE_URL env var so both processes share the same DB.
        $dbUrl = getenv('DATABASE_URL');
        if ($dbUrl) {
            config()->set('database.default', 'race');
            config()->set('database.connections.race', [
                'driver' => str_starts_with($dbUrl, 'mysql') ? 'mysql' : 'pgsql',
                'url' => $dbUrl,
                'charset' => 'utf8',
            ]);
        } else {
            config()->set('database.default', 'testing');
            config()->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => getenv('TEST_DB_PATH') ?: ':memory:',
                'prefix' => '',
            ]);
        }

        config()->set('ez-ecommerce.currency.default', 'AED');
        config()->set('ez-ecommerce.features.api', true);
        config()->set('ez-ecommerce.api.token', 'test-api-token');
        config()->set('ez-ecommerce.api.scoped_tokens', ['test-api-token' => ['*']]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Use RefreshDatabase's default migration loading. The package's
        // runsMigrations() registers the migrations via the service provider.
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    public function runAction(string $action, array $params): int
    {
        $this->setUp();

        try {
            switch ($action) {
                case 'fulfill':
                    $order = Order::query()->findOrFail($params['order_id']);
                    $item = OrderItem::query()->findOrFail($params['item_id']);
                    app(CreateFulfillment::class)->execute(
                        $order,
                        $item,
                        $params['qty'],
                        $params['key'],
                    );
                    break;

                case 'outbox':
                    $payment = Payment::query()->findOrFail($params['payment_id']);
                    $order = $payment->order;
                    try {
                        $msg = \Illuminate\Support\Facades\DB::transaction(fn () => OutboxMessage::query()->create([
                            'event' => 'order.paid',
                            'key' => "order.paid:{$order->id}",
                            'status' => 'pending',
                            'payload' => [
                                'order_id' => $order->id,
                                'order_public_id' => $order->public_id,
                                'payment_id' => $payment->id,
                            ],
                        ]));
                        ProcessOutboxJob::dispatchSync($msg->id);
                    } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                        // Race loser — the other process already inserted.
                    }
                    break;

                case 'void':
                    $payment = Payment::query()->findOrFail($params['payment_id']);
                    app(VoidPaymentAuthorization::class)->execute($payment, $params['key']);
                    break;

                default:
                    fwrite(STDERR, "Unknown action: {$action}\n");

                    return 1;
            }

            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage()."\n");

            return 1;
        } finally {
            $this->tearDown();
        }
    }
}

$action = $argv[1] ?? '';
$params = json_decode($argv[2] ?? '{}', true) ?: [];

$worker = new WorkerTestbench('worker');
exit($worker->runAction($action, $params));
