<?php

/**
 * Worker bootstrap for two-process race tests.
 *
 * Usage: php tests/bin/worker.php <action> <params-json>
 *
 * Actions:
 *   fulfill       <{"order_id":1,"item_id":1,"qty":2,"key":"..."}>      create a fulfillment
 *   outbox        <{"payment_id":1}>                                     insert an order.paid outbox row + dispatch
 *   outbox-claim  <{"outbox_id":1}>                                      claim + process one outbox row
 *   capture       <{"payment_id":1,"amount_minor":10000,"key":"..."}>    capture a payment
 *   void          <{"payment_id":1,"key":"..."}>                         void a payment authorization
 *   refund        <{"payment_id":1,"amount_minor":2500,"key":"..."}>     refund a payment
 *   checkout      <{"variant_id":1,"qty":1,"key":"...","payment_method":"manual"}> place a checkout order
 *   release-expired <>                                               release expired inventory reservations
 *
 * Reads DB_* env vars (same as CI hardening jobs) and connects to the shared
 * database the parent test already migrated. Does NOT run RefreshDatabase or
 * loadMigrationsFrom — the parent owns the schema. Boots only
 * EzEcommerceServiceProvider. Prints one JSON line to stdout and exits 0 on
 * success / expected idempotent replay / benign race-loser, 1 on unexpected
 * failure.
 */

require __DIR__.'/../../vendor/autoload.php';

use EzEcommerce\Catalog\Models\ProductVariant;
use EzEcommerce\Core\Jobs\ProcessOutboxJob;
use EzEcommerce\Core\Models\OutboxMessage;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\EzEcommerceServiceProvider;
use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Fulfillment\Actions\CreateFulfillment;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderItem;
use EzEcommerce\Payments\Actions\CapturePayment;
use EzEcommerce\Payments\Actions\VoidPaymentAuthorization;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Refunds\Actions\RefundPayment;
use Illuminate\Database\UniqueConstraintViolationException;
use Orchestra\Testbench\TestCase as TestbenchTestCase;

class RaceWorker extends TestbenchTestCase
{
    protected function getPackageProviders($app): array
    {
        return [EzEcommerceServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $connection = getenv('DB_CONNECTION') ?: '';

        if (in_array($connection, ['mysql', 'pgsql'], true)) {
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
                'foreign_key_constraints' => true,
            ]);
        } else {
            // SQLite fallback only when no real DB is configured.
            config()->set('database.default', 'testing');
            config()->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => getenv('DB_DATABASE') ?: ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }

        // Mark migrations as already run so Spatie's runsMigrations() /
        // loadMigrationsFrom() registers paths without creating
        // MigrateProcessor instances. The parent test already migrated the
        // shared DB; the worker must not re-run or roll back migrations.
        // Without this, loadMigrationsFrom dispatches 53 artisan migrate
        // commands per worker boot and migrate:rollback at tearDown, causing
        // exit 255 when any migration's down() hits a state the test modified.
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = true;

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
        config()->set('ez-ecommerce.api.scoped_tokens', ['test-api-token' => ['*']]);
        config()->set('ez-ecommerce.checkout.public_payment_methods', [
            'stripe', 'paypal', 'telr', 'manual', 'fake', 'null',
        ]);
    }

    public function runAction(string $action, array $params): int
    {
        try {
            $this->setUp();

            $result = match ($action) {
                'fulfill' => $this->fulfill($params),
                'outbox' => $this->outbox($params),
                'outbox-claim' => $this->outboxClaim($params),
                'capture' => $this->capture($params),
                'void' => $this->void($params),
                'refund' => $this->refund($params),
                'checkout' => $this->checkout($params),
                'release-expired' => $this->releaseExpired($params),
                default => throw new RuntimeException("Unknown action: {$action}"),
            };

            $this->emit(true, $action, $result, null);

            return 0;
        } catch (UniqueConstraintViolationException $e) {
            // Benign race-loser only for idempotent insert actions (fulfill,
            // outbox, checkout). For capture/void/refund, a unique violation
            // is unexpected and should be surfaced as an error, not hidden.
            $benignActions = ['fulfill', 'outbox', 'checkout'];
            if (in_array($action, $benignActions, true)) {
                $this->emit(true, $action, ['race_loser' => true], null);

                return 0;
            }

            $this->emit(false, $action, null, [
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return 1;
        } catch (\Throwable $e) {
            $this->emit(false, $action, null, [
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return 1;
        } finally {
            if ($this->app) {
                $this->tearDown();
            }
        }
    }

    /** @return array<string, mixed> */
    private function fulfill(array $params): array
    {
        $order = Order::query()->findOrFail($params['order_id']);
        $item = OrderItem::query()->findOrFail($params['item_id']);
        $fulfillment = app(CreateFulfillment::class)->execute(
            $order,
            $item,
            $params['qty'],
            $params['key'],
        );

        return ['fulfillment_id' => $fulfillment->id, 'public_id' => $fulfillment->public_id];
    }

    /** @return array<string, mixed> */
    private function outbox(array $params): array
    {
        $payment = Payment::query()->findOrFail($params['payment_id']);
        $order = $payment->order;

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

        return ['outbox_id' => $msg->id];
    }

    /** @return array<string, mixed> */
    private function outboxClaim(array $params): array
    {
        $id = (int) $params['outbox_id'];
        ProcessOutboxJob::dispatchSync($id);

        $msg = OutboxMessage::query()->find($id);

        return ['outbox_id' => $id, 'status' => $msg?->status];
    }

    /** @return array<string, mixed> */
    private function capture(array $params): array
    {
        $payment = Payment::query()->findOrFail($params['payment_id']);
        $amount = Money::fromMinor((int) $params['amount_minor'], $payment->currency);
        $result = app(CapturePayment::class)->executeForPayment($payment, $amount, $params['key'] ?? '');

        return ['external_id' => $result->externalId, 'status' => $result->status->value];
    }

    /** @return array<string, mixed> */
    private function void(array $params): array
    {
        $payment = Payment::query()->findOrFail($params['payment_id']);
        $payment = app(VoidPaymentAuthorization::class)->execute($payment, $params['key'] ?? '');

        return ['status' => $payment->status->value];
    }

    /** @return array<string, mixed> */
    private function refund(array $params): array
    {
        $payment = Payment::query()->findOrFail($params['payment_id']);
        $amount = Money::fromMinor((int) $params['amount_minor'], $payment->currency);
        $refund = app(RefundPayment::class)->execute(
            $payment,
            $amount,
            $params['reason'] ?? null,
            $params['key'] ?? '',
        );

        return ['refund_id' => $refund->id, 'status' => $refund->status->value];
    }

    /** @return array<string, mixed> */
    private function checkout(array $params): array
    {
        $variant = ProductVariant::query()->findOrFail($params['variant_id']);
        $cart = EzEcommerce::cart()->createGuest('AED');
        EzEcommerce::cart()->addItem($cart, $variant, (int) $params['qty']);
        $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
        $hash = EzEcommerce::cart()->totalsHash($cart, 'flat');

        $result = EzEcommerce::checkout()->for($cart->fresh())
            ->shippingMethod('flat')
            ->paymentMethod($params['payment_method'] ?? 'manual')
            ->place(idempotencyKey: $params['key'], expectedTotalsHash: $hash);

        return ['order_id' => $result->order->id, 'order_public_id' => $result->order->public_id];
    }

    /** @return array<string, mixed> */
    private function releaseExpired(array $params): array
    {
        $released = EzEcommerce::inventory()->releaseExpiredReservations();

        return ['released' => $released];
    }

    /** @param  array<string, mixed>|null  $result  @param  array<string, mixed>|null  $error */
    private function emit(bool $ok, string $action, ?array $result, ?array $error): void
    {
        fwrite(STDOUT, json_encode([
            'ok' => $ok,
            'action' => $action,
            'result' => $result,
            'error' => $error,
        ], JSON_THROW_ON_ERROR)."\n");
    }
}

$action = $argv[1] ?? '';
$params = json_decode($argv[2] ?? '{}', true) ?: [];

$worker = new RaceWorker('worker');
exit($worker->runAction($action, $params));
