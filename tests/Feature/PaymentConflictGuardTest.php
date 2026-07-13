<?php

use EzEcommerce\Core\Enums\FulfillmentStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Actions\CapturePayment;
use EzEcommerce\Payments\Actions\RetryPaymentSession;
use EzEcommerce\Payments\Actions\VoidPaymentAuthorization;
use EzEcommerce\Payments\Exceptions\ConflictingPaymentOperationException;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Refunds\Actions\RefundPayment;

function createConflictPayment(PaymentStatus $status = PaymentStatus::Authorized, int $capturedMinor = 0): Payment
{
    $customer = Customer::query()->create([
        'public_id' => '01CFCUST'.uniqid(),
        'email' => 'cfc'.uniqid().'@example.com',
    ]);

    $order = Order::query()->create([
        'public_id' => '01CFORD'.uniqid(),
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
    ]);

    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'gateway' => 'fake',
        'amount_minor' => 10000,
        'currency' => 'AED',
        'status' => $status,
        'captured_minor' => $capturedMinor,
        'public_id' => '01CFPAY'.uniqid(),
    ]);

    PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'create_session',
        'idempotency_key' => 'session:'.$payment->public_id,
        'status' => 'requires_action',
        'external_id' => 'fake_session_'.$payment->public_id,
    ]);

    return $payment;
}

function inFlightAttempt(Payment $payment, string $operation): PaymentAttempt
{
    return PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => $operation,
        'idempotency_key' => $operation.':inflight:'.$payment->public_id.':'.uniqid(),
        'status' => 'pending',
        'external_id' => null,
    ]);
}

it('blocks a capture while a void is in flight', function () {
    $payment = createConflictPayment(PaymentStatus::Authorized);
    inFlightAttempt($payment, 'void');

    expect(fn () => app(CapturePayment::class)->executeForPayment($payment))
        ->toThrow(ConflictingPaymentOperationException::class);
})->group('hardening');

it('blocks a void while a capture is in flight', function () {
    $payment = createConflictPayment(PaymentStatus::Authorized);
    inFlightAttempt($payment, 'capture');

    expect(fn () => app(VoidPaymentAuthorization::class)->execute($payment, 'void-key-'.uniqid()))
        ->toThrow(ConflictingPaymentOperationException::class);
})->group('hardening');

it('blocks a refund while a void is in flight', function () {
    $payment = createConflictPayment(PaymentStatus::Captured, 10000);
    inFlightAttempt($payment, 'void');

    expect(fn () => app(RefundPayment::class)->execute(
        $payment,
        Money::fromMinor(2500, 'AED'),
        null,
        'refund-key-'.uniqid(),
    ))->toThrow(ConflictingPaymentOperationException::class);
})->group('hardening');

it('blocks a retry session while a capture is in flight', function () {
    $payment = createConflictPayment(PaymentStatus::RequiresAction);
    // Source session attempt must be retryable so RetryPaymentSession proceeds
    // past the early "requires_action" return into the conflict-guarded path.
    $payment->attempts()->where('operation', 'create_session')->update(['status' => 'failed']);
    inFlightAttempt($payment, 'capture');

    expect(fn () => app(RetryPaymentSession::class)->execute($payment, $payment->order, 'retry-key-'.uniqid()))
        ->toThrow(ConflictingPaymentOperationException::class);
})->group('hardening');

it('does not block a capture when a create_session is settled pending with an external id', function () {
    $payment = createConflictPayment(PaymentStatus::Pending);

    // A settled pending session (e.g. manual payment) has an external id — it is
    // not in flight and must not block a capture.
    PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'create_session',
        'idempotency_key' => 'session-settled:'.$payment->public_id,
        'status' => 'pending',
        'external_id' => 'manual_session_'.$payment->public_id,
    ]);

    // The capture should proceed past the conflict guard (it may fail later on
    // policy/amount, but not on the conflict guard).
    $threw = false;
    try {
        app(CapturePayment::class)->executeForPayment($payment);
    } catch (ConflictingPaymentOperationException $e) {
        $threw = true;
    } catch (\Throwable $e) {
        // Other failures (e.g. nothing left to capture) are acceptable — the
        // guard itself did not block.
    }

    expect($threw)->toBeFalse();
})->group('hardening');

it('blocks a void while a pending capture with an external id is still settling', function () {
    $payment = createConflictPayment(PaymentStatus::Pending);

    // A pending capture with an external reference (e.g. PayPal PENDING) is still
    // settling — it must block void, refund, and another capture.
    PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'capture',
        'idempotency_key' => 'cap-pending:'.$payment->public_id,
        'status' => 'pending',
        'external_id' => 'paypal_capture_ref_'.$payment->public_id,
    ]);

    expect(fn () => app(VoidPaymentAuthorization::class)->execute($payment, 'void-key-'.uniqid()))
        ->toThrow(ConflictingPaymentOperationException::class);
})->group('hardening');

it('blocks a second void with a different key while another void is pending', function () {
    $payment = createConflictPayment(PaymentStatus::Authorized);

    // First void is pending (in flight).
    PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'void',
        'idempotency_key' => 'void-first:'.$payment->public_id,
        'status' => 'pending',
    ]);

    // Second void with a different key must be blocked.
    expect(fn () => app(VoidPaymentAuthorization::class)->execute($payment, 'void-second-'.uniqid()))
        ->toThrow(RuntimeException::class, 'already in progress');
})->group('hardening');
