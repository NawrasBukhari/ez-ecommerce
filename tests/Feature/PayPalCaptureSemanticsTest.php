<?php

use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Core\Enums\ReservationStatus;
use EzEcommerce\Core\Models\OutboxMessage;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Inventory\Models\InventoryReservation;
use EzEcommerce\Payments\Actions\ReconcilePayment;
use EzEcommerce\Payments\Data\GatewayWebhookEvent;
use EzEcommerce\Payments\Data\PaymentResult;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Drivers\FakePaymentGateway;
use EzEcommerce\Payments\Models\PaymentTransaction;
use EzEcommerce\Tests\Support\SetsUpCatalog;
use Illuminate\Support\Facades\Event;

uses(SetsUpCatalog::class);

/*
 * These tests prove that the gateway PaymentStatus (not a bare success flag)
 * drives capture finalization. PayPal can return a successful HTTP operation
 * with status PENDING; that must not append a capture ledger, commit inventory,
 * confirm the order, or enqueue order.paid. The fake gateway is configured to
 * return the same PaymentResult shape PayPal produces (success=true, Pending),
 * exercising the gateway-agnostic HandleCaptureResult dispatcher.
 */

function pendingCaptureResult(int $amountMinor, string $currency): PaymentResult
{
    return new PaymentResult(
        success: true,
        status: PaymentStatus::Pending,
        amount: Money::fromMinor($amountMinor, $currency),
        externalId: 'pending_cap_'.uniqid(),
    );
}

it('finalizes a completed capture (COMPLETED-shaped result)', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'cap-completed-'.uniqid(), paymentMethod: 'fake');

    $payment = $result->payment;
    $attempt = $payment->attempts()->first();

    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Captured)
        ->and($payment->fresh()->captured_minor)->toBe($payment->amount_minor)
        ->and($result->order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and(PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Capture)
            ->count())->toBe(1);
})->group('hardening');

it('does not append a capture transaction for a pending capture', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'cap-pending-'.uniqid(), paymentMethod: 'fake');

    $payment = $result->payment;
    $attempt = $payment->attempts()->first();

    $this->app->instance(
        FakePaymentGateway::class,
        new FakePaymentGateway(captureResult: pendingCaptureResult($payment->amount_minor, 'AED')),
    );

    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($payment->fresh()->captured_minor)->toBe(0)
        ->and(PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Capture)
            ->exists())->toBeFalse();
})->group('hardening');

it('does not commit inventory for a pending capture', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'cap-pending-inv-'.uniqid(), paymentMethod: 'fake');

    $payment = $result->payment;
    $attempt = $payment->attempts()->first();

    $this->app->instance(
        FakePaymentGateway::class,
        new FakePaymentGateway(captureResult: pendingCaptureResult($payment->amount_minor, 'AED')),
    );

    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);

    $reservations = InventoryReservation::query()->where('order_id', $result->order->id)->get();

    expect($reservations)->not->toBeEmpty()
        ->and($reservations->every(fn ($r) => $r->status === ReservationStatus::Active))->toBeTrue();
})->group('hardening');

it('does not confirm the order or enqueue order.paid for a pending capture', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'cap-pending-order-'.uniqid(), paymentMethod: 'fake');

    $payment = $result->payment;
    $attempt = $payment->attempts()->first();

    Event::fake();

    $this->app->instance(
        FakePaymentGateway::class,
        new FakePaymentGateway(captureResult: pendingCaptureResult($payment->amount_minor, 'AED')),
    );

    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);

    expect($result->order->fresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and(OutboxMessage::query()
            ->where('event', 'order.paid')
            ->where('key', 'order.paid:'.$result->order->id)
            ->exists())->toBeFalse();
})->group('hardening');

it('finalizes a pending capture exactly once at the ledger level via a later completion webhook', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'cap-pending-recover-'.uniqid(), paymentMethod: 'fake');

    $payment = $result->payment;
    $sessionAttempt = $payment->attempts()->first();

    // 1. Synchronous capture returns PENDING — finalizes nothing.
    $this->app->instance(
        FakePaymentGateway::class,
        new FakePaymentGateway(captureResult: pendingCaptureResult($payment->amount_minor, 'AED')),
    );
    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $sessionAttempt);

    expect(PaymentTransaction::query()
        ->where('payment_id', $payment->id)
        ->where('type', PaymentTransactionType::Capture)
        ->exists())->toBeFalse();

    // 2. Later completion webhook finalizes the capture.
    $captureExternalId = 'cap_completed_'.uniqid();
    $completionEvent = new GatewayWebhookEvent(
        eventType: 'payment.captured',
        eventId: 'evt_completed_'.uniqid(),
        paymentReference: $payment->public_id,
        transactionReference: $captureExternalId,
        amountMinor: $payment->amount_minor,
        currency: 'AED',
        providerStatus: 'succeeded',
    );
    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(webhookEvent: $completionEvent));

    app(ReconcilePayment::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"payment.captured"}',
    ));

    // 3. A duplicate delivery must not append a second capture transaction.
    app(ReconcilePayment::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"payment.captured"}',
    ));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Captured)
        ->and($payment->fresh()->captured_minor)->toBe($payment->amount_minor)
        ->and(PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Capture)
            ->count())->toBe(1)
        ->and($result->order->fresh()->status)->toBe(OrderStatus::Confirmed);
})->group('hardening');

it('preserves an earlier partial capture when a follow-up capture fails', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'cap-partial-then-fail-'.uniqid(), paymentMethod: 'fake');

    $payment = $result->payment;
    $sessionAttempt = $payment->attempts()->first();

    // First capture: partial (4000 of 10000).
    $this->app->instance(
        FakePaymentGateway::class,
        new FakePaymentGateway(captureResult: new PaymentResult(
            success: true,
            status: PaymentStatus::PartiallyCaptured,
            amount: Money::fromMinor(4000, 'AED'),
            externalId: 'partial_cap_'.uniqid(),
        )),
    );
    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->executeForPayment(
        $payment,
        Money::fromMinor(4000, 'AED'),
        'partial-'.uniqid(),
    );

    expect($payment->fresh()->status)->toBe(PaymentStatus::PartiallyCaptured)
        ->and($payment->fresh()->captured_minor)->toBe(4000);

    // Second capture: fails. The earlier partial capture must remain intact.
    $this->app->instance(
        FakePaymentGateway::class,
        new FakePaymentGateway(captureResult: new PaymentResult(
            success: false,
            status: PaymentStatus::Failed,
            amount: Money::fromMinor(6000, 'AED'),
            externalId: 'failed_cap_'.uniqid(),
            failure: new \EzEcommerce\Payments\Data\PaymentFailure('capture_failed', 'Declined.', false),
        )),
    );

    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->executeForPayment(
        $payment,
        Money::fromMinor(6000, 'AED'),
        'fail-'.uniqid(),
    );

    expect($payment->fresh()->status)->toBe(PaymentStatus::PartiallyCaptured)
        ->and($payment->fresh()->captured_minor)->toBe(4000)
        ->and((int) PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Capture)
            ->where('status', 'succeeded')
            ->sum('amount_minor'))->toBe(4000);
})->group('hardening');

it('marks a payment as Failed when the first capture attempt terminally declines with no prior captures', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'cap-initial-fail-'.uniqid(), paymentMethod: 'fake');

    $payment = $result->payment->fresh();
    $payment->update(['status' => PaymentStatus::Authorized, 'authorized_minor' => $payment->amount_minor]);

    $this->app->instance(
        FakePaymentGateway::class,
        new FakePaymentGateway(captureResult: new PaymentResult(
            success: false,
            status: PaymentStatus::Failed,
            amount: Money::fromMinor($payment->amount_minor, 'AED'),
            externalId: 'fail_cap_'.uniqid(),
            failure: new \EzEcommerce\Payments\Data\PaymentFailure('capture_declined', 'Card declined.', false),
        )),
    );

    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->executeForPayment(
        $payment,
        Money::fromMinor($payment->amount_minor, 'AED'),
        'initial-fail-'.uniqid(),
    );

    $paymentAfter = $payment->fresh();
    $attemptAfter = $paymentAfter->attempts()->where('operation', 'capture')->latest()->first();

    expect($paymentAfter->status)->toBe(PaymentStatus::Failed)
        ->and($paymentAfter->captured_minor)->toBe(0)
        ->and($attemptAfter->status)->toBe('failed');
})->group('hardening');

it('resolves the original pending capture attempt to succeeded when a completion webhook arrives', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'cap-pending-attempt-'.uniqid(), paymentMethod: 'fake');

    $payment = $result->payment;
    $sessionAttempt = $payment->attempts()->first();

    // 1. Synchronous capture returns PENDING with an external reference.
    $pendingExternalId = 'pending_cap_ref_'.uniqid();
    $this->app->instance(
        FakePaymentGateway::class,
        new FakePaymentGateway(captureResult: new PaymentResult(
            success: true,
            status: PaymentStatus::Pending,
            amount: Money::fromMinor($payment->amount_minor, 'AED'),
            externalId: $pendingExternalId,
        )),
    );
    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $sessionAttempt);

    // The capture attempt is pending with the provider reference stored.
    $captureAttempt = $payment->attempts()
        ->where('operation', 'capture')
        ->where('external_id', $pendingExternalId)
        ->first();
    expect($captureAttempt)->not->toBeNull()
        ->and($captureAttempt->status)->toBe('pending');

    // 2. Completion webhook finalizes the capture using the same external reference.
    $completionEvent = new GatewayWebhookEvent(
        eventType: 'payment.captured',
        eventId: 'evt_completed_'.uniqid(),
        paymentReference: $pendingExternalId,
        transactionReference: $pendingExternalId,
        amountMinor: $payment->amount_minor,
        currency: 'AED',
        providerStatus: 'succeeded',
    );
    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(webhookEvent: $completionEvent));

    app(ReconcilePayment::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"payment.captured"}',
    ));

    // 3. The original pending capture attempt is now resolved (no longer pending).
    $captureAttempt = $captureAttempt->fresh();
    expect($captureAttempt->status)->not->toBe('pending')
        ->and($captureAttempt->external_id)->toBe($pendingExternalId)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Captured);
})->group('hardening');
