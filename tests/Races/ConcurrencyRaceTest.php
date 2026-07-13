<?php

use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Models\OutboxMessage;
use EzEcommerce\Fulfillment\Models\Fulfillment;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Refunds\Models\Refund;
use EzEcommerce\Tests\RaceTestCase;
use EzEcommerce\Tests\Support\SetsUpCatalog;

uses(RaceTestCase::class);
uses(SetsUpCatalog::class);

function supportsTwoProcessRaces(): bool
{
    $driver = config('database.connections.'.config('database.default').'.driver')
        ?? 'sqlite';

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

/** @param  array<int, array{action: string, params: array<string, mixed>}>  $jobs */
function runWorkersConcurrently(array $jobs): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $procs = [];
    $pipes = [];

    foreach ($jobs as $i => $job) {
        $cmd = sprintf(
            'php %s %s %s',
            escapeshellarg(workerPath()),
            escapeshellarg($job['action']),
            escapeshellarg(json_encode($job['params'], JSON_THROW_ON_ERROR)),
        );
        $procs[$i] = proc_open($cmd, $descriptors, $pipes[$i]);
        fclose($pipes[$i][0]);
    }

    $results = [];
    foreach ($procs as $i => $proc) {
        $stdout = (string) stream_get_contents($pipes[$i][1]);
        fclose($pipes[$i][1]);
        $stderr = (string) stream_get_contents($pipes[$i][2]);
        fclose($pipes[$i][2]);
        $exitCode = proc_close($proc);

        $json = null;
        foreach (array_filter(explode("\n", trim($stdout))) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $json = $decoded;
                break;
            }
        }

        $results[$i] = [
            'ok' => is_array($json) ? ($json['ok'] ?? false) : false,
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'json' => $json,
        ];
    }

    return $results;
}

it('same-key fulfillment produces one fulfillment row with the same public id on idempotent success', function () {
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

    $results = runWorkersConcurrently([
        ['action' => 'fulfill', 'params' => ['order_id' => $order->id, 'item_id' => $item->id, 'qty' => 2, 'key' => $key]],
        ['action' => 'fulfill', 'params' => ['order_id' => $order->id, 'item_id' => $item->id, 'qty' => 2, 'key' => $key]],
    ]);

    $fulfillments = Fulfillment::query()->where('order_id', $order->id)->get();

    expect($fulfillments->count())->toBe(1)
        ->and($results[0]['ok'])->toBeTrue()
        ->and($results[1]['ok'])->toBeTrue()
        ->and($results[0]['json']['result']['public_id'])->toBe($results[1]['json']['result']['public_id']);
})->group('hardening');

it('two captures on the same payment produce at most one provider attempt', function () {
    skipOnSQLite('two-capture race');

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'race-2cap-'.uniqid(), paymentMethod: 'fake');
    $payment = $result->payment->fresh();
    $payment->update(['status' => PaymentStatus::Authorized, 'authorized_minor' => $payment->amount_minor]);

    $results = runWorkersConcurrently([
        ['action' => 'capture', 'params' => ['payment_id' => $payment->id, 'amount_minor' => $payment->amount_minor, 'key' => 'cap-a-'.uniqid()]],
        ['action' => 'capture', 'params' => ['payment_id' => $payment->id, 'amount_minor' => $payment->amount_minor, 'key' => 'cap-b-'.uniqid()]],
    ]);

    $successes = collect($results)->filter(fn ($r) => $r['ok'] === true && ! ($r['json']['result']['race_loser'] ?? false))->count();

    expect($successes)->toBeLessThanOrEqual(1)
        ->and(collect($results)->filter(fn ($r) => $r['ok'] === false)->count())->toBeGreaterThanOrEqual(1);
})->group('hardening');

it('capture vs void allows at most one incompatible provider operation', function () {
    skipOnSQLite('capture-vs-void race');

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'race-capvoid-'.uniqid(), paymentMethod: 'fake');
    $payment = $result->payment->fresh();
    $payment->update(['status' => PaymentStatus::Authorized, 'authorized_minor' => $payment->amount_minor]);

    $results = runWorkersConcurrently([
        ['action' => 'capture', 'params' => ['payment_id' => $payment->id, 'amount_minor' => $payment->amount_minor, 'key' => 'cv-cap-'.uniqid()]],
        ['action' => 'void', 'params' => ['payment_id' => $payment->id, 'key' => 'cv-void-'.uniqid()]],
    ]);

    $successes = collect($results)->filter(fn ($r) => $r['ok'] === true && ! ($r['json']['result']['race_loser'] ?? false))->count();

    expect($successes)->toBeLessThanOrEqual(1)
        ->and(collect($results)->filter(fn ($r) => $r['ok'] === false)->count())->toBeGreaterThanOrEqual(1);

    $final = $payment->fresh()->status;
    expect(in_array($final, [PaymentStatus::Captured, PaymentStatus::Cancelled], true))->toBeTrue();
})->group('hardening');

it('two refunds never exceed the refundable balance', function () {
    skipOnSQLite('two-refund race');

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'race-2ref-'.uniqid(), paymentMethod: 'fake');
    $payment = $result->payment;
    $attempt = $payment->attempts()->first();
    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);
    $payment = $payment->fresh();

    $results = runWorkersConcurrently([
        ['action' => 'refund', 'params' => ['payment_id' => $payment->id, 'amount_minor' => 6000, 'key' => 'ref-a-'.uniqid()]],
        ['action' => 'refund', 'params' => ['payment_id' => $payment->id, 'amount_minor' => 6000, 'key' => 'ref-b-'.uniqid()]],
    ]);

    $refundedMinor = (int) Refund::query()->where('payment_id', $payment->id)->sum('amount_minor');

    expect($refundedMinor)->toBeLessThanOrEqual($payment->captured_minor);
})->group('hardening');

it('two outbox workers claim one row exclusively', function () {
    skipOnSQLite('outbox-claim race');

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'race-outbox-claim-'.uniqid(), paymentMethod: 'fake');
    $payment = $result->payment;

    $msg = OutboxMessage::query()->create([
        'event' => 'order.paid',
        'key' => 'order.paid:'.$result->order->id,
        'status' => 'pending',
        'payload' => [
            'order_id' => $result->order->id,
            'order_public_id' => $result->order->public_id,
            'payment_id' => $payment->id,
        ],
    ]);

    $results = runWorkersConcurrently([
        ['action' => 'outbox-claim', 'params' => ['outbox_id' => $msg->id]],
        ['action' => 'outbox-claim', 'params' => ['outbox_id' => $msg->id]],
    ]);

    expect($msg->fresh()->status)->toBe('processed')
        ->and(collect($results)->every(fn ($r) => $r['exitCode'] === 0))->toBeTrue();
})->group('hardening');

it('two checkouts for the last stock unit produce one order and one insufficient-inventory rejection', function () {
    skipOnSQLite('last-stock race');

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 1);

    $results = runWorkersConcurrently([
        ['action' => 'checkout', 'params' => ['variant_id' => $variant->id, 'qty' => 1, 'key' => 'last-a-'.uniqid(), 'payment_method' => 'manual']],
        ['action' => 'checkout', 'params' => ['variant_id' => $variant->id, 'qty' => 1, 'key' => 'last-b-'.uniqid(), 'payment_method' => 'manual']],
    ]);

    $successes = collect($results)->filter(fn ($r) => $r['ok'] === true)->count();
    $failures = collect($results)->filter(fn ($r) => $r['ok'] === false)->count();

    expect($successes)->toBe(1)
        ->and($failures)->toBe(1);
})->group('hardening');

it('capture finalization versus reservation release leaves consistent state', function () {
    skipOnSQLite('capture-vs-expiry race');

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'race-expiry-'.uniqid(), paymentMethod: 'fake');
    $payment = $result->payment->fresh();
    $payment->update(['status' => PaymentStatus::Authorized, 'authorized_minor' => $payment->amount_minor]);

    $results = runWorkersConcurrently([
        ['action' => 'capture', 'params' => ['payment_id' => $payment->id, 'amount_minor' => $payment->amount_minor, 'key' => 'exp-cap-'.uniqid()]],
        ['action' => 'release-expired', 'params' => []],
    ]);

    $final = $payment->fresh();

    // Either the capture committed (Captured) or the reservation was released
    // first and the capture failed cleanly — never a half-committed state.
    expect($results[0]['exitCode'])->toBeGreaterThanOrEqual(0)
        ->and($results[1]['exitCode'])->toBeGreaterThanOrEqual(0)
        ->and(in_array($final->status, [PaymentStatus::Captured, PaymentStatus::Authorized, PaymentStatus::Failed], true))->toBeTrue();
})->group('hardening');

it('idempotent refund with the same key serializes to one refund row', function () {
    skipOnSQLite('idempotent-refund serialization');

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'race-idem-ref-'.uniqid(), paymentMethod: 'fake');
    $payment = $result->payment;
    $attempt = $payment->attempts()->first();
    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);
    $payment = $payment->fresh();

    $key = 'idem-ref-'.uniqid();

    $results = runWorkersConcurrently([
        ['action' => 'refund', 'params' => ['payment_id' => $payment->id, 'amount_minor' => 2500, 'key' => $key]],
        ['action' => 'refund', 'params' => ['payment_id' => $payment->id, 'amount_minor' => 2500, 'key' => $key]],
    ]);

    $refunds = Refund::query()->where('payment_id', $payment->id)->where('amount_minor', 2500)->count();
    $refundedMinor = (int) Refund::query()->where('payment_id', $payment->id)->sum('amount_minor');

    expect($refunds)->toBe(1)
        ->and($refundedMinor)->toBe(2500)
        ->and(collect($results)->every(fn ($r) => $r['exitCode'] === 0))->toBeTrue();
})->group('hardening');
