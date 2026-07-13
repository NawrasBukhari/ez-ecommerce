<?php

use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Core\Events\OrderPaid;
use EzEcommerce\Core\Models\OutboxMessage;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Fulfillment\Actions\CreateFulfillment;
use EzEcommerce\Orders\Actions\CancelOrder;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderItem;
use EzEcommerce\Payments\Actions\ReconcilePayment;
use EzEcommerce\Payments\Actions\ReconcileVoidAttempt;
use EzEcommerce\Payments\Actions\VoidPaymentAuthorization;
use EzEcommerce\Payments\Data\GatewayWebhookEvent;
use EzEcommerce\Payments\Data\PaymentFailure;
use EzEcommerce\Payments\Data\PaymentResult;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Drivers\FakePaymentGateway;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use EzEcommerce\Tests\Support\SetsUpCatalog;
use EzEcommerce\Webhooks\Inbound\Models\ProcessedGatewayEvent;
use Illuminate\Support\Facades\Event;

uses(SetsUpCatalog::class);

function createOrderWithPayment(PaymentStatus $status = PaymentStatus::RequiresAction, int $amountMinor = 10000): array
{
    $customer = Customer::query()->create([
        'public_id' => '01SP3CUST'.uniqid(),
        'email' => 'sp3'.uniqid().'@example.com',
    ]);

    $order = Order::query()->create([
        'public_id' => '01SP3ORD'.uniqid(),
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
        'authorized_minor' => $status === PaymentStatus::Authorized ? $amountMinor : 0,
        'captured_minor' => 0,
        'refunded_minor' => 0,
        'public_id' => '01SP3PAY'.uniqid(),
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

it('stripe charge.captured webhook reads amount_captured for partial captures', function () {
    [$order, $payment] = createOrderWithPayment(PaymentStatus::Authorized, 10000);
    $payment->update(['captured_minor' => 0]);

    // Fake gateway returns the parsed event with amount_captured (4000) as amountMinor,
    // simulating the Stripe parser reading amount_captured instead of amount.
    $captureEvent = new GatewayWebhookEvent(
        eventType: 'payment.captured',
        eventId: 'evt_partial_'.uniqid(),
        paymentReference: 'fake_session_'.$payment->public_id,
        transactionReference: 'ch_partial_'.uniqid(),
        amountMinor: 4000,
        currency: 'AED',
        providerStatus: 'succeeded',
    );
    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(webhookEvent: $captureEvent));

    app(ReconcilePayment::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"payment.captured"}',
    ));

    $captureTxn = PaymentTransaction::query()
        ->where('payment_id', $payment->id)
        ->where('type', PaymentTransactionType::Capture)
        ->latest('id')->first();

    expect($captureTxn)->not->toBeNull()
        ->and((int) $captureTxn->amount_minor)->toBe(4000);
})->group('hardening');

it('authorization webhook after capture does not regress payment state', function () {
    [$order, $payment] = createOrderWithPayment(PaymentStatus::Captured, 10000);
    $payment->update(['captured_minor' => 10000]);

    $authEvent = new GatewayWebhookEvent(
        eventType: 'payment_intent.amount_capturable_updated',
        eventId: 'evt_late_auth_'.uniqid(),
        paymentReference: 'fake_session_'.$payment->public_id,
        transactionReference: 'fake_session_'.$payment->public_id,
        amountMinor: 10000,
        currency: 'AED',
        providerStatus: 'requires_capture',
    );
    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(webhookEvent: $authEvent));

    app(ReconcilePayment::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"payment_intent.amount_capturable_updated"}',
    ));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Captured);
})->group('hardening');

it('authorization webhook does not reactivate a cancelled order', function () {
    [$order, $payment] = createOrderWithPayment(PaymentStatus::RequiresAction, 10000);
    $order->update(['status' => OrderStatus::Cancelled]);

    $authEvent = new GatewayWebhookEvent(
        eventType: 'payment_intent.amount_capturable_updated',
        eventId: 'evt_cancel_auth_'.uniqid(),
        paymentReference: 'fake_session_'.$payment->public_id,
        transactionReference: 'fake_session_'.$payment->public_id,
        amountMinor: 10000,
        currency: 'AED',
        providerStatus: 'requires_capture',
    );
    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(webhookEvent: $authEvent));

    app(ReconcilePayment::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"payment_intent.amount_capturable_updated"}',
    ));

    expect($payment->fresh()->status)->toBe(PaymentStatus::RequiresAction)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);
})->group('hardening');

it('void with null failure throws RuntimeException not TypeError', function () {
    [$order, $payment] = createOrderWithPayment(PaymentStatus::Authorized, 10000);

    $fake = new FakePaymentGateway(
        voidResult: new PaymentResult(
            success: false,
            status: PaymentStatus::Authorized,
            amount: \EzEcommerce\Core\Money\Money::fromMinor(10000, 'AED'),
            failure: null,
        ),
    );
    $this->app->instance(FakePaymentGateway::class, $fake);

    expect(fn () => app(VoidPaymentAuthorization::class)->execute($payment, 'void-null-'.uniqid())
        )->toThrow(RuntimeException::class, 'Void failed');

    $attempt = PaymentAttempt::query()
        ->where('payment_id', $payment->id)
        ->where('operation', 'void')
        ->latest('id')->first();

    expect($attempt->status)->toBe('failed')
        ->and($attempt->error_code)->toBe('void_failed');
})->group('hardening');

it('voids a requires-action payment via VoidPaymentAuthorization', function () {
    [$order, $payment] = createOrderWithPayment(PaymentStatus::RequiresAction, 10000);

    $result = app(VoidPaymentAuthorization::class)->execute($payment, 'void-ra-'.uniqid());

    expect($result->status)->toBe(PaymentStatus::Cancelled)
        ->and(PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Void)
            ->exists())->toBeTrue();
})->group('hardening');

it('cancel order voids a requires-action payment', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 2);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'cancel-ra-'.uniqid(), paymentMethod: 'fake');

    $payment = $result->payment;
    $payment->update(['status' => PaymentStatus::RequiresAction]);

    $order = $result->order->fresh();
    app(CancelOrder::class)->execute($order);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Cancelled);
})->group('hardening');

it('fulfillment rejects mismatched payload for reused idempotency key', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 10);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 4);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'ful-mismatch-'.uniqid());

    $payment = $result->payment;
    $attempt = $payment->attempts()->first();
    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);

    $order = Order::query()->with('items')->findOrFail($result->order->id);
    $item = $order->items->first();
    $key = 'ful-mismatch-'.uniqid();

    app(CreateFulfillment::class)->execute($order, $item, 2, $key);

    // Reusing the same key with a different quantity should reject.
    expect(fn () => app(CreateFulfillment::class)->execute($order, $item, 3, $key)
        )->toThrow(\EzEcommerce\Core\Exceptions\IdempotencyPayloadMismatchException::class);
})->group('hardening');

it('fulfillment returns existing record on concurrent insert with same key', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 10);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 4);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'ful-concurrent-'.uniqid());

    $payment = $result->payment;
    $attempt = $payment->attempts()->first();
    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);

    $order = Order::query()->with('items')->findOrFail($result->order->id);
    $item = $order->items->first();
    $key = 'ful-concurrent-'.uniqid();

    $first = app(CreateFulfillment::class)->execute($order, $item, 2, $key);
    $second = app(CreateFulfillment::class)->execute($order, $item, 2, $key);

    expect($second->id)->toBe($first->id);
})->group('hardening');

it('persists unmatched refund webhook for later replay', function () {
    [$order, $payment] = createOrderWithPayment(PaymentStatus::Captured, 10000);
    $payment->update(['captured_minor' => 10000]);

    $refundEvent = new GatewayWebhookEvent(
        eventType: 'refund.updated',
        eventId: 'evt_unmatched_'.uniqid(),
        paymentReference: 'unknown_refund_id',
        transactionReference: 'unknown_refund_id',
        amountMinor: 5000,
        currency: 'AED',
        providerStatus: 'succeeded',
    );
    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(webhookEvent: $refundEvent));

    app(\EzEcommerce\Payments\Actions\ReconcileRefund::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"refund.updated"}',
    ));

    $record = ProcessedGatewayEvent::query()
        ->where('gateway', 'fake')
        ->where('external_event_id', $refundEvent->eventId)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe('unmatched');
})->group('hardening');

it('reconciles an unknown void attempt via operator confirmation', function () {
    [$order, $payment] = createOrderWithPayment(PaymentStatus::Authorized, 10000);

    // Simulate a void that threw (network lost) -> attempt unknown.
    $attempt = PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'void',
        'idempotency_key' => 'void-recon-'.uniqid(),
        'status' => 'unknown',
        'error_code' => 'void_exception',
        'error_message' => 'network lost',
    ]);

    // Operator confirms the provider did cancel the PI.
    app(ReconcileVoidAttempt::class)->confirmProviderSucceeded($attempt, 'pi_canceled_'.uniqid());

    expect($payment->fresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and($attempt->fresh()->status)->toBe('succeeded')
        ->and(PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Void)
            ->exists())->toBeTrue();
})->group('hardening');

it('dispatches OrderPaid exactly once via outbox unique key', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'outbox-'.uniqid(), paymentMethod: 'fake');

    $payment = $result->payment;
    $attempt = $payment->attempts()->first();

    Event::fake([OrderPaid::class]);

    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);

    // Simulate a concurrent recovery finalization on the same payment.
    app(\EzEcommerce\Payments\Actions\FinalizeAcceptedPayment::class)->completeOrderAfterCapture($payment->fresh());

    $outboxCount = OutboxMessage::query()
        ->where('event', 'order.paid')
        ->where('key', 'order.paid:'.$result->order->id)
        ->count();

    expect($outboxCount)->toBe(1)
        ->and(Event::dispatched(OrderPaid::class))->count()->toBeLessThanOrEqual(1);
})->group('hardening');

it('stripe payment_intent.payment_failed webhook transitions payment to Failed', function () {
    [$order, $payment] = createOrderWithPayment(PaymentStatus::RequiresAction, 10000);

    $failEvent = new GatewayWebhookEvent(
        eventType: 'payment_intent.payment_failed',
        eventId: 'evt_fail_'.uniqid(),
        paymentReference: 'fake_session_'.$payment->public_id,
        transactionReference: 'fake_session_'.$payment->public_id,
        amountMinor: 10000,
        currency: 'AED',
        providerStatus: 'requires_payment_method',
    );
    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(webhookEvent: $failEvent));

    app(ReconcilePayment::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"payment_intent.payment_failed"}',
    ));

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);
})->group('hardening');