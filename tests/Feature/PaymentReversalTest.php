<?php

use EzEcommerce\Core\Enums\OrderPaymentStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Actions\ReconcilePayment;
use EzEcommerce\Payments\Data\GatewayWebhookEvent;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Drivers\FakePaymentGateway;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;

function createReversalOrderWithPayment(PaymentStatus $status = PaymentStatus::Captured, int $amountMinor = 10000, int $capturedMinor = 10000): array
{
    $customer = Customer::query()->create([
        'public_id' => '01RVRCUST'.uniqid(),
        'email' => 'rvr'.uniqid().'@example.com',
    ]);

    $order = Order::query()->create([
        'public_id' => '01RVRORD'.uniqid(),
        'customer_id' => $customer->id,
        'status' => OrderStatus::PendingPayment,
        'payment_status' => 'unpaid',
        'fulfillment_status' => \EzEcommerce\Core\Enums\FulfillmentStatus::Unfulfilled,
        'currency' => 'AED',
        'subtotal_minor' => $amountMinor,
        'discount_total_minor' => 0,
        'tax_total_minor' => 0,
        'shipping_total_minor' => 0,
        'fee_total_minor' => 0,
        'grand_total_minor' => $amountMinor,
        'refunded_total_minor' => 0,
        'payment_method' => 'fake',
    ]);

    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'gateway' => 'fake',
        'amount_minor' => $amountMinor,
        'currency' => 'AED',
        'status' => $status,
        'authorized_minor' => 0,
        'captured_minor' => $capturedMinor,
        'refunded_minor' => 0,
        'public_id' => '01RVRPAY'.uniqid(),
    ]);

    PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'create_session',
        'idempotency_key' => 'session:'.$payment->public_id,
        'status' => 'succeeded',
        'external_id' => 'fake_session_'.$payment->public_id,
    ]);

    return [$order, $payment];
}

function reversalEvent(Payment $payment, string $reversalId, int $amountMinor): GatewayWebhookEvent
{
    return new GatewayWebhookEvent(
        eventType: 'payment.reversed',
        eventId: 'evt_reversed_'.uniqid(),
        paymentReference: $payment->public_id,
        transactionReference: $reversalId,
        amountMinor: $amountMinor,
        currency: 'AED',
        providerStatus: 'reversed',
    );
}

function captureCompletionEvent(Payment $payment, string $captureId, int $amountMinor): GatewayWebhookEvent
{
    return new GatewayWebhookEvent(
        eventType: 'payment.captured',
        eventId: 'evt_completed_'.uniqid(),
        paymentReference: $payment->public_id,
        transactionReference: $captureId,
        amountMinor: $amountMinor,
        currency: 'AED',
        providerStatus: 'succeeded',
    );
}

it('transitions a captured payment to Reversed on a reversal webhook', function () {
    [$order, $payment] = createReversalOrderWithPayment(PaymentStatus::Captured, 10000, 10000);
    PaymentTransaction::query()->create([
        'payment_id' => $payment->id,
        'type' => PaymentTransactionType::Capture,
        'amount_minor' => 10000,
        'currency' => 'AED',
        'external_id' => 'cap_orig_'.uniqid(),
        'status' => 'succeeded',
    ]);

    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: reversalEvent($payment, 'rev_'.uniqid(), 10000),
    ));

    app(ReconcilePayment::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"payment.reversed"}',
    ));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Reversed)
        ->and($order->fresh()->payment_status)->toBe(OrderPaymentStatus::Disputed);
})->group('hardening');

it('transitions a partially captured payment to Reversed on a reversal webhook', function () {
    [$order, $payment] = createReversalOrderWithPayment(PaymentStatus::PartiallyCaptured, 10000, 4000);
    PaymentTransaction::query()->create([
        'payment_id' => $payment->id,
        'type' => PaymentTransactionType::Capture,
        'amount_minor' => 4000,
        'currency' => 'AED',
        'external_id' => 'cap_partial_'.uniqid(),
        'status' => 'succeeded',
    ]);

    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: reversalEvent($payment, 'rev_'.uniqid(), 4000),
    ));

    app(ReconcilePayment::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"payment.reversed"}',
    ));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Reversed)
        ->and($order->fresh()->payment_status)->toBe(OrderPaymentStatus::Disputed);
})->group('hardening');

it('does not append a second reversal transaction for a duplicate reversal webhook', function () {
    [$order, $payment] = createReversalOrderWithPayment(PaymentStatus::Captured, 10000, 10000);
    PaymentTransaction::query()->create([
        'payment_id' => $payment->id,
        'type' => PaymentTransactionType::Capture,
        'amount_minor' => 10000,
        'currency' => 'AED',
        'external_id' => 'cap_dup_'.uniqid(),
        'status' => 'succeeded',
    ]);

    $reversalId = 'rev_dup_'.uniqid();
    $event = reversalEvent($payment, $reversalId, 10000);
    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(webhookEvent: $event));

    app(ReconcilePayment::class)->execute(new WebhookRequestData(gateway: 'fake', payload: '{"type":"payment.reversed"}'));
    app(ReconcilePayment::class)->execute(new WebhookRequestData(gateway: 'fake', payload: '{"type":"payment.reversed"}'));

    expect(PaymentTransaction::query()
        ->where('payment_id', $payment->id)
        ->where('type', PaymentTransactionType::Reversal)
        ->count())->toBe(1);
})->group('hardening');

it('records manual-review metadata on the order for a reversal', function () {
    [$order, $payment] = createReversalOrderWithPayment(PaymentStatus::Captured, 10000, 10000);
    PaymentTransaction::query()->create([
        'payment_id' => $payment->id,
        'type' => PaymentTransactionType::Capture,
        'amount_minor' => 10000,
        'currency' => 'AED',
        'external_id' => 'cap_meta_'.uniqid(),
        'status' => 'succeeded',
    ]);

    $reversalId = 'rev_meta_'.uniqid();
    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: reversalEvent($payment, $reversalId, 10000),
    ));

    app(ReconcilePayment::class)->execute(new WebhookRequestData(gateway: 'fake', payload: '{"type":"payment.reversed"}'));

    $metadata = $order->fresh()->metadata instanceof \ArrayObject
        ? $order->fresh()->metadata->getArrayCopy()
        : (array) ($order->fresh()->metadata ?? []);

    expect($metadata['manual_review_required'] ?? null)->toBe('payment_reversal')
        ->and($metadata['payment_reversal_reference'] ?? null)->toBe($reversalId);
})->group('hardening');

it('keeps one capture and one reversal transaction in the ledger after a reversal', function () {
    [$order, $payment] = createReversalOrderWithPayment(PaymentStatus::Captured, 10000, 10000);
    PaymentTransaction::query()->create([
        'payment_id' => $payment->id,
        'type' => PaymentTransactionType::Capture,
        'amount_minor' => 10000,
        'currency' => 'AED',
        'external_id' => 'cap_ledger_'.uniqid(),
        'status' => 'succeeded',
    ]);

    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: reversalEvent($payment, 'rev_ledger_'.uniqid(), 10000),
    ));

    app(ReconcilePayment::class)->execute(new WebhookRequestData(gateway: 'fake', payload: '{"type":"payment.reversed"}'));

    expect(PaymentTransaction::query()
        ->where('payment_id', $payment->id)
        ->where('type', PaymentTransactionType::Capture)
        ->count())->toBe(1)
        ->and(PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Reversal)
            ->count())->toBe(1);
})->group('hardening');

it('does not restore a reversed payment to Captured when a delayed completion webhook arrives', function () {
    [$order, $payment] = createReversalOrderWithPayment(PaymentStatus::Captured, 10000, 10000);
    PaymentTransaction::query()->create([
        'payment_id' => $payment->id,
        'type' => PaymentTransactionType::Capture,
        'amount_minor' => 10000,
        'currency' => 'AED',
        'external_id' => 'cap_restore_'.uniqid(),
        'status' => 'succeeded',
    ]);

    // Reversal first.
    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: reversalEvent($payment, 'rev_restore_'.uniqid(), 10000),
    ));
    app(ReconcilePayment::class)->execute(new WebhookRequestData(gateway: 'fake', payload: '{"type":"payment.reversed"}'));
    expect($payment->fresh()->status)->toBe(PaymentStatus::Reversed);

    // Delayed completion webhook must not restore Captured.
    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: captureCompletionEvent($payment, 'cap_late_'.uniqid(), 10000),
    ));
    app(ReconcilePayment::class)->execute(new WebhookRequestData(gateway: 'fake', payload: '{"type":"payment.captured"}'));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Reversed)
        ->and(PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Capture)
            ->count())->toBe(1);
})->group('hardening');

it('treats a reversal arriving before a capture as a no-op and lets the completion finalize', function () {
    [$order, $payment] = createReversalOrderWithPayment(PaymentStatus::Pending, 10000, 0);

    // Reversal arrives while the payment is still Pending (no capture to reverse).
    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: reversalEvent($payment, 'rev_early_'.uniqid(), 10000),
    ));
    app(ReconcilePayment::class)->execute(new WebhookRequestData(gateway: 'fake', payload: '{"type":"payment.reversed"}'));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Reversal)
            ->exists())->toBeFalse();

    // The delayed completion then finalizes the capture normally.
    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: captureCompletionEvent($payment, 'cap_after_early_'.uniqid(), 10000),
    ));
    app(ReconcilePayment::class)->execute(new WebhookRequestData(gateway: 'fake', payload: '{"type":"payment.captured"}'));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Captured)
        ->and($payment->fresh()->captured_minor)->toBe(10000);
})->group('hardening');
