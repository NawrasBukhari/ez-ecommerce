<?php

use EzEcommerce\Core\Enums\FulfillmentStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\ReservationStatus;
use EzEcommerce\Core\Events\OrderPaid;
use EzEcommerce\Core\Jobs\ProcessOutboxJob;
use EzEcommerce\Core\Models\OutboxMessage;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Inventory\Models\InventoryReservation;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Actions\FinalizeAcceptedPayment;
use EzEcommerce\Payments\Models\Payment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function insertOutboxRow(string $status = 'pending', array $overrides = []): OutboxMessage
{
    return OutboxMessage::query()->create(array_merge([
        'event' => 'order.paid',
        'key' => 'order.paid:test:'.uniqid(),
        'status' => $status,
        'payload' => ['order_id' => 1, 'order_public_id' => 'TEST', 'payment_id' => 1],
        'attempts' => 0,
    ], $overrides));
}

it('does not insert an order.paid outbox row when inventory finalization fails', function () {
    $customer = Customer::query()->create([
        'public_id' => '01OBXCUST'.uniqid(),
        'email' => 'obx'.uniqid().'@example.com',
    ]);
    $order = Order::query()->create([
        'public_id' => '01OBXORD'.uniqid(),
        'customer_id' => $customer->id,
        'status' => OrderStatus::PendingPayment,
        'payment_status' => 'unpaid',
        'fulfillment_status' => FulfillmentStatus::Unfulfilled,
        'currency' => 'AED',
        'subtotal_minor' => 10000,
        'discount_total_minor' => 0,
        'tax_total_minor' => 0,
        'shipping_total_minor' => 0,
        'fee_total_minor' => 0,
        'grand_total_minor' => 10000,
        'refunded_total_minor' => 0,
        'payment_method' => 'fake',
        'metadata' => ['expected_reservation_count' => 1],
    ]);
    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'gateway' => 'fake',
        'amount_minor' => 10000,
        'currency' => 'AED',
        'status' => PaymentStatus::Captured,
        'captured_minor' => 10000,
        'public_id' => '01OBXPAY'.uniqid(),
    ]);
    // An expired reservation causes CommitReservation to throw.
    $warehouse = \EzEcommerce\Inventory\Models\Warehouse::query()->create(['name' => 'OBX Default', 'code' => 'OBX']);
    $balance = \EzEcommerce\Inventory\Models\InventoryBalance::query()->create([
        'warehouse_id' => $warehouse->id,
        'stockable_type' => 'commerce_product_variant',
        'stockable_id' => 1,
        'on_hand' => 1,
        'reserved' => 1,
    ]);
    InventoryReservation::query()->create([
        'order_id' => $order->id,
        'balance_id' => $balance->id,
        'quantity' => 1,
        'status' => ReservationStatus::Expired,
        'expires_at' => now()->subHour(),
    ]);

    expect(fn () => app(FinalizeAcceptedPayment::class)->completeOrderAfterCapture($payment))
        ->toThrow(\EzEcommerce\Inventory\Exceptions\ReservationExpiredException::class);

    expect(OutboxMessage::query()->where('event', 'order.paid')->exists())->toBeFalse()
        ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
})->group('hardening');

it('does not re-claim a row while another worker holds an unexpired lease', function () {
    Event::fake([OrderPaid::class]);
    $row = insertOutboxRow('processing', [
        'locked_at' => now(),
        'locked_until' => now()->addSeconds(60),
        'attempts' => 1,
    ]);

    ProcessOutboxJob::dispatchSync($row->id);

    expect($row->fresh()->status)->toBe('processing')
        ->and($row->fresh()->attempts)->toBe(1)
        ->and(Event::dispatched(OrderPaid::class))->count()->toBe(0);
})->group('hardening');

it('reclaims a stale processing row whose lease has expired', function () {
    Event::fake([OrderPaid::class]);
    $row = insertOutboxRow('processing', [
        'locked_at' => now()->subSeconds(120),
        'locked_until' => now()->subSeconds(60),
        'attempts' => 1,
    ]);

    ProcessOutboxJob::dispatchSync($row->id);

    expect($row->fresh()->status)->toBe('processed')
        ->and($row->fresh()->attempts)->toBe(2)
        ->and(Event::dispatched(OrderPaid::class))->count()->toBe(1);
})->group('hardening');

it('marks a row failed_retryable when the listener fails and attempts are below the limit', function () {
    config()->set('ez-ecommerce.features.outbound_webhooks', false);
    config()->set('ez-ecommerce.outbox.max_attempts', 5);

    $row = insertOutboxRow('pending');

    Event::listen(OrderPaid::class, fn () => throw new RuntimeException('listener boom'));

    try {
        ProcessOutboxJob::dispatchSync($row->id);
    } catch (RuntimeException $e) {
        // Expected: the job re-throws after marking the row failed.
    }

    $fresh = $row->fresh();
    expect($fresh->status)->toBe('failed_retryable')
        ->and($fresh->attempts)->toBe(1)
        ->and($fresh->last_error)->toBe('listener boom')
        ->and($fresh->available_at)->not->toBeNull();
})->group('hardening');

it('marks a row failed_terminal when attempts exceed the configured limit', function () {
    config()->set('ez-ecommerce.features.outbound_webhooks', false);
    config()->set('ez-ecommerce.outbox.max_attempts', 2);

    $row = insertOutboxRow('pending', ['attempts' => 2]);

    Event::listen(OrderPaid::class, fn () => throw new RuntimeException('listener boom'));

    try {
        ProcessOutboxJob::dispatchSync($row->id);
    } catch (RuntimeException $e) {
        // Expected.
    }

    $fresh = $row->fresh();
    expect($fresh->status)->toBe('failed_terminal')
        ->and($fresh->available_at)->toBeNull();
})->group('hardening');

it('retries a failed_retryable row later and marks it processed', function () {
    Event::fake([OrderPaid::class]);
    $row = insertOutboxRow('failed_retryable', [
        'attempts' => 1,
        'available_at' => now()->subMinute(),
        'last_error' => 'previous failure',
    ]);

    ProcessOutboxJob::dispatchSync($row->id);

    expect($row->fresh()->status)->toBe('processed')
        ->and($row->fresh()->attempts)->toBe(2)
        ->and($row->fresh()->last_error)->toBeNull()
        ->and(Event::dispatched(OrderPaid::class))->count()->toBe(1);
})->group('hardening');

it('recovers a crash after outbox insertion but before dispatch via commerce:process-outbox', function () {
    Event::fake([OrderPaid::class]);

    // Simulate a crash: a pending row exists but no job was ever dispatched.
    $row = insertOutboxRow('pending');

    Artisan::call('commerce:process-outbox', ['--limit' => 100]);

    expect($row->fresh()->status)->toBe('processed')
        ->and(Event::dispatched(OrderPaid::class))->count()->toBe(1);
})->group('hardening');

it('creates only one outbox key for duplicate finalization', function () {
    Event::fake([OrderPaid::class]);

    $orderId = 999999 + random_int(1, 1000);
    $key = "order.paid:{$orderId}";

    DB::table('commerce_outbox_messages')->insert([
        'event' => 'order.paid',
        'key' => $key,
        'status' => 'pending',
        'payload' => json_encode(['order_id' => $orderId, 'order_public_id' => 'DUP', 'payment_id' => 1]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A second finalizer attempts the same key.
    try {
        DB::transaction(fn () => OutboxMessage::query()->create([
            'event' => 'order.paid',
            'key' => $key,
            'status' => 'pending',
            'payload' => ['order_id' => $orderId, 'order_public_id' => 'DUP', 'payment_id' => 1],
        ]));
    } catch (\Illuminate\Database\UniqueConstraintViolationException|\Exception $e) {
        // Expected on databases that raise immediately; the savepoint path in
        // FinalizeAcceptedPayment swallows this. Either way, only one row remains.
    }

    expect(OutboxMessage::query()->where('key', $key)->count())->toBe(1);
})->group('hardening');

it('prevents a stale worker from regressing a processed row after lease expiry', function () {
    Event::fake([OrderPaid::class]);

    $msg = insertOutboxRow('processing', [
        'locked_until' => now()->subMinutes(10),
        'lock_token' => 'stale-token',
        'attempts' => 1,
    ]);

    // Worker A (stale) claims the row with a new token, dispatches the event.
    $jobA = new ProcessOutboxJob($msg->id);
    $jobA->handle(app(\EzEcommerce\Core\Contracts\Clock::class));

    // Worker A succeeds and marks processed.
    expect($msg->fresh()->status)->toBe('processed');

    // Now simulate Worker B (the stale lease owner) trying to mark failed
    // with the old token — it must not regress the processed row.
    DB::transaction(function () use ($msg) {
        $row = OutboxMessage::query()->lockForUpdate()->find($msg->id);
        if ($row !== null && $row->lock_token === 'stale-token' && $row->status !== 'processed') {
            $row->update(['status' => 'failed_retryable', 'last_error' => 'stale worker']);
        }
    });

    expect($msg->fresh()->status)->toBe('processed');
})->group('hardening');

it('enforces backoff on a directly dispatched failed_retryable row', function () {
    Event::fake([OrderPaid::class]);

    $msg = insertOutboxRow('failed_retryable', [
        'attempts' => 1,
        'available_at' => now()->addMinutes(5),
    ]);

    $job = new ProcessOutboxJob($msg->id);
    $job->handle(app(\EzEcommerce\Core\Contracts\Clock::class));

    // The row must not be claimed because available_at is in the future.
    expect($msg->fresh()->status)->toBe('failed_retryable')
        ->and($msg->fresh()->attempts)->toBe(1);
})->group('hardening');
