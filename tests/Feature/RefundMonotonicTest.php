<?php

use EzEcommerce\Core\Enums\FulfillmentStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Core\Enums\RefundStatus;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Actions\ReconcileRefund;
use EzEcommerce\Payments\Data\GatewayWebhookEvent;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Drivers\FakePaymentGateway;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use EzEcommerce\Refunds\Actions\RefundPayment;
use EzEcommerce\Refunds\Models\Refund;

function createRefundFixture(RefundStatus $refundStatus, string $attemptStatus = 'succeeded'): array
{
    $customer = Customer::query()->create([
        'public_id' => '01RMNCUST'.uniqid(),
        'email' => 'rmn'.uniqid().'@example.com',
    ]);

    $order = Order::query()->create([
        'public_id' => '01RMNORD'.uniqid(),
        'customer_id' => $customer->id,
        'status' => OrderStatus::Confirmed,
        'payment_status' => 'paid',
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
        'status' => PaymentStatus::Captured,
        'captured_minor' => 10000,
        'refunded_minor' => $refundStatus === RefundStatus::Succeeded ? 5000 : 0,
        'public_id' => '01RMNPAY'.uniqid(),
    ]);

    PaymentTransaction::query()->create([
        'payment_id' => $payment->id,
        'type' => PaymentTransactionType::Capture,
        'amount_minor' => 10000,
        'currency' => 'AED',
        'external_id' => 'cap_rmn_'.uniqid(),
        'status' => 'succeeded',
    ]);

    $externalId = 'rfd_rmn_'.uniqid();
    $refund = Refund::query()->create([
        'payment_id' => $payment->id,
        'order_id' => $order->id,
        'amount_minor' => 5000,
        'currency' => 'AED',
        'status' => $refundStatus,
        'external_id' => $refundStatus === RefundStatus::Succeeded ? $externalId : null,
        'public_id' => '01RMNRFD'.uniqid(),
    ]);

    $attempt = PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'refund',
        'idempotency_key' => 'rfd:'.$refund->public_id,
        'status' => $attemptStatus,
        'external_id' => $externalId,
        'request_metadata' => [
            'refund_id' => $refund->id,
            'requested_amount_minor' => 5000,
            'currency' => 'AED',
            'provider_operation' => 'refund',
        ],
    ]);

    if ($refundStatus === RefundStatus::Succeeded) {
        PaymentTransaction::query()->create([
            'payment_id' => $payment->id,
            'type' => PaymentTransactionType::Refund,
            'amount_minor' => 5000,
            'currency' => 'AED',
            'external_id' => $externalId,
            'status' => 'succeeded',
        ]);
    }

    return [$order, $payment, $refund, $attempt, $externalId];
}

function refundWebhook(Payment $payment, string $externalId, string $providerStatus): GatewayWebhookEvent
{
    return new GatewayWebhookEvent(
        eventType: 'refund.updated',
        eventId: 'evt_rmn_'.uniqid(),
        paymentReference: $payment->public_id,
        transactionReference: $externalId,
        amountMinor: 5000,
        currency: 'AED',
        providerStatus: $providerStatus,
    );
}

it('does not regress a succeeded refund to pending on a late pending webhook', function () {
    [$order, $payment, $refund, $attempt, $externalId] = createRefundFixture(RefundStatus::Succeeded, 'succeeded');

    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: refundWebhook($payment, $externalId, 'pending'),
    ));

    app(ReconcileRefund::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"refund.updated"}',
    ));

    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($attempt->fresh()->status)->toBe('succeeded');
})->group('hardening');

it('does not regress a succeeded refund to failed on a late failed webhook', function () {
    [$order, $payment, $refund, $attempt, $externalId] = createRefundFixture(RefundStatus::Succeeded, 'succeeded');

    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: refundWebhook($payment, $externalId, 'failed'),
    ));

    app(ReconcileRefund::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"refund.updated"}',
    ));

    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($attempt->fresh()->status)->toBe('succeeded');
})->group('hardening');

it('reconciles a failed refund to succeeded via an explicit succeeded webhook', function () {
    [$order, $payment, $refund, $attempt, $externalId] = createRefundFixture(RefundStatus::Failed, 'failed');

    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: refundWebhook($payment, $externalId, 'succeeded'),
    ));

    app(ReconcileRefund::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"refund.updated"}',
    ));

    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->fresh()->external_id)->toBe($externalId)
        ->and($payment->fresh()->refunded_minor)->toBe(5000)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded);
})->group('hardening');

it('demotes stripe charge.refunded to an informational event that does not apply refund state', function () {
    expect(app(ReconcileRefund::class)->isRefundEvent('stripe', 'charge.refunded'))->toBeFalse()
        ->and(app(ReconcileRefund::class)->isRefundEvent('stripe', 'refund.updated'))->toBeTrue();
})->group('hardening');

function createPaymentForRefundPolicy(PaymentStatus $paymentStatus, ?OrderStatus $orderStatus = null, int $capturedMinor = 0, int $refundedMinor = 0): array
{
    $customer = Customer::query()->create([
        'public_id' => '01RPOLCUST'.uniqid(),
        'email' => 'rpol'.uniqid().'@example.com',
    ]);

    $order = Order::query()->create([
        'public_id' => '01RPOLORD'.uniqid(),
        'customer_id' => $customer->id,
        'status' => $orderStatus ?? OrderStatus::Confirmed,
        'payment_status' => 'paid',
        'fulfillment_status' => FulfillmentStatus::Unfulfilled,
        'currency' => 'AED',
        'subtotal_minor' => 10000,
        'discount_total_minor' => 0,
        'tax_total_minor' => 0,
        'shipping_total_minor' => 0,
        'fee_total_minor' => 0,
        'grand_total_minor' => 10000,
        'refunded_total_minor' => $refundedMinor,
        'payment_method' => 'fake',
    ]);

    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'gateway' => 'fake',
        'amount_minor' => 10000,
        'currency' => 'AED',
        'status' => $paymentStatus,
        'authorized_minor' => $paymentStatus === PaymentStatus::Authorized ? 10000 : 0,
        'captured_minor' => $capturedMinor,
        'refunded_minor' => $refundedMinor,
        'public_id' => '01RPOLPAY'.uniqid(),
    ]);

    return [$order, $payment];
}

it('rejects a refund on a cancelled order via the payment operation policy', function () {
    [$order, $payment] = createPaymentForRefundPolicy(PaymentStatus::Captured, OrderStatus::Cancelled, 10000);

    expect(fn () => app(RefundPayment::class)->execute($payment, Money::fromMinor(2500, 'AED'), null, 'rpol-cancel-'.uniqid()))
        ->toThrow(RuntimeException::class, 'not in a refundable state');
})->group('hardening');

it('rejects a refund on a failed payment', function () {
    [$order, $payment] = createPaymentForRefundPolicy(PaymentStatus::Failed, null, 0);

    expect(fn () => app(RefundPayment::class)->execute($payment, Money::fromMinor(2500, 'AED'), null, 'rpol-failed-'.uniqid()))
        ->toThrow(RuntimeException::class, 'not in a refundable state');
})->group('hardening');

it('rejects a refund on an authorized-only payment', function () {
    [$order, $payment] = createPaymentForRefundPolicy(PaymentStatus::Authorized, null, 0);

    expect(fn () => app(RefundPayment::class)->execute($payment, Money::fromMinor(2500, 'AED'), null, 'rpol-auth-'.uniqid()))
        ->toThrow(RuntimeException::class, 'not in a refundable state');
})->group('hardening');

it('rejects a refund on a pending payment', function () {
    [$order, $payment] = createPaymentForRefundPolicy(PaymentStatus::Pending, null, 0);

    expect(fn () => app(RefundPayment::class)->execute($payment, Money::fromMinor(2500, 'AED'), null, 'rpol-pending-'.uniqid()))
        ->toThrow(RuntimeException::class, 'not in a refundable state');
})->group('hardening');

it('rejects a refund on a fully refunded payment', function () {
    [$order, $payment] = createPaymentForRefundPolicy(PaymentStatus::Refunded, null, 10000, 10000);

    expect(fn () => app(RefundPayment::class)->execute($payment, Money::fromMinor(2500, 'AED'), null, 'rpol-full-'.uniqid()))
        ->toThrow(RuntimeException::class, 'not in a refundable state');
})->group('hardening');

it('rejects a refund with no captured balance via the ledger refundable check', function () {
    [$order, $payment] = createPaymentForRefundPolicy(PaymentStatus::Captured, null, 0);

    expect(fn () => app(RefundPayment::class)->execute($payment, Money::fromMinor(2500, 'AED'), null, 'rpol-zero-'.uniqid()))
        ->toThrow(InvalidArgumentException::class, 'exceeds refundable');
})->group('hardening');

it('allows a refund on a partially captured payment with a positive captured balance', function () {
    [$order, $payment] = createPaymentForRefundPolicy(PaymentStatus::PartiallyCaptured, null, 6000, 0);

    $refund = app(RefundPayment::class)->execute($payment, Money::fromMinor(2500, 'AED'), null, 'rpol-ok-'.uniqid());

    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($payment->fresh()->refunded_minor)->toBe(2500);
})->group('hardening');
