<?php

use EzEcommerce\Core\Models\OutboxMessage;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Tests\Support\SetsUpCatalog;

uses(SetsUpCatalog::class);

function supportsTwoProcessRaces(): bool
{
    $driver = config('database.connections.testing.driver')
        ?? config('database.default');

    return in_array($driver, ['mysql', 'pgsql'], true);
}

function skipOnSQLite(string $test): void
{
    if (! supportsTwoProcessRaces()) {
        test()->markTestSkipped("{$test} requires MySQL or PostgreSQL (got SQLite).");
    }
}

function workerPath(): string
{
    return dirname(__DIR__).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'worker.php';
}

function runWorker(string $action, array $params): array
{
    $cmd = sprintf(
        'php %s %s %s 2>&1',
        escapeshellarg(workerPath()),
        escapeshellarg($action),
        escapeshellarg(json_encode($params, JSON_THROW_ON_ERROR)),
    );

    $output = shell_exec($cmd);
    $exitCode = 0;

    return ['output' => (string) $output, 'exitCode' => $exitCode];
}

it('fulfillment concurrent insert with the same idempotency key produces one fulfillment', function () {
    skipOnSQLite('fulfillment race');

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 10);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 4);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'race-ful-'.uniqid());
    $payment = $result->payment;
    $attempt = $payment->attempts()->first();
    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);

    $order = Order::query()->with('items')->findOrFail($result->order->id);
    $item = $order->items->first();
    $key = 'race-ful-'.uniqid();

    // Run two worker processes concurrently.
    $pipes = [];
    $procs = [];
    for ($i = 0; $i < 2; $i++) {
        $cmd = sprintf(
            'php %s fulfill %s',
            escapeshellarg(workerPath()),
            escapeshellarg(json_encode([
                'order_id' => $order->id,
                'item_id' => $item->id,
                'qty' => 2,
                'key' => $key,
            ], JSON_THROW_ON_ERROR)),
        );
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }

    foreach ($procs as $proc) {
        proc_close($proc);
    }

    $fulfillments = \EzEcommerce\Fulfillment\Models\Fulfillment::query()
        ->where('order_id', $order->id)
        ->get();

    expect($fulfillments->count())->toBe(1);
})->group('hardening');

it('OrderPaid outbox race produces exactly one outbox row', function () {
    skipOnSQLite('outbox race');

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'race-outbox-'.uniqid());
    $payment = $result->payment;

    $params = json_encode([
        'payment_id' => $payment->id,
    ], JSON_THROW_ON_ERROR);

    $procs = [];
    for ($i = 0; $i < 2; $i++) {
        $cmd = sprintf('php %s outbox %s', escapeshellarg(workerPath()), escapeshellarg($params));
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    foreach ($procs as $proc) {
        proc_close($proc);
    }

    $count = OutboxMessage::query()
        ->where('event', 'order.paid')
        ->where('key', 'order.paid:'.$result->order->id)
        ->count();

    expect($count)->toBe(1);
})->group('hardening');

it('void replay with the same idempotency key voids once', function () {
    skipOnSQLite('void race');

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'race-void-'.uniqid(), paymentMethod: 'fake');
    $payment = $result->payment;

    $key = 'race-void-'.uniqid();
    $params = json_encode([
        'payment_id' => $payment->id,
        'key' => $key,
    ], JSON_THROW_ON_ERROR);

    $procs = [];
    $pipes = [];
    for ($i = 0; $i < 2; $i++) {
        $cmd = sprintf('php %s void %s', escapeshellarg(workerPath()), escapeshellarg($params));
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    foreach ($procs as $proc) {
        proc_close($proc);
    }

    $voidAttempts = \EzEcommerce\Payments\Models\PaymentAttempt::query()
        ->where('payment_id', $payment->id)
        ->where('operation', 'void')
        ->where('idempotency_key', $key)
        ->count();

    expect($voidAttempts)->toBeLessThanOrEqual(1);
})->group('hardening');
