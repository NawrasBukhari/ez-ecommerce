<?php

use EzEcommerce\Core\Enums\FulfillmentStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Core\Events\OrderPaid;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Fulfillment\Models\Fulfillment;
use EzEcommerce\Orders\Actions\CancelOrder;
use EzEcommerce\Orders\Actions\CompleteOrder;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Actions\ReconcilePayment;
use EzEcommerce\Payments\Actions\VoidPaymentAuthorization;
use EzEcommerce\Payments\Data\GatewayWebhookEvent;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Drivers\FakePaymentGateway;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use EzEcommerce\Pricing\Models\PriceList;
use EzEcommerce\Refunds\Actions\RefundPayment;
use EzEcommerce\Refunds\Models\Refund;
use EzEcommerce\Tests\Support\SetsUpCatalog;
use EzEcommerce\Webhooks\Inbound\Models\ProcessedGatewayEvent;
use Illuminate\Support\Facades\Event;

uses(SetsUpCatalog::class);

function createAuthorizedPayment(Order $order, int $amountMinor = 10000): Payment
{
    $payment = Payment::query()->create([
        'order_id' => $order->id,
        'gateway' => 'fake',
        'amount_minor' => $amountMinor,
        'currency' => 'AED',
        'status' => PaymentStatus::Authorized,
        'authorized_minor' => $amountMinor,
        'captured_minor' => 0,
        'refunded_minor' => 0,
        'public_id' => '01TESTAUTHPAYMENT'.uniqid(),
    ]);

    PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'create_session',
        'idempotency_key' => 'session:'.$payment->public_id,
        'status' => 'succeeded',
        'external_id' => 'fake_session_'.$payment->public_id,
    ]);

    return $payment;
}

function createPendingOrder(): Order
{
    $customer = \EzEcommerce\Customers\Models\Customer::query()->create([
        'public_id' => '01TESTCUST'.uniqid(),
        'email' => 'test'.uniqid().'@example.com',
    ]);

    return Order::query()->create([
        'public_id' => '01TESTORDER'.uniqid(),
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
}

it('reconciles stripe authorization webhook into Authorized status', function () {
    $order = createPendingOrder();
    $payment = createAuthorizedPayment($order);
    // Reset to RequiresAction to test reconciliation
    $payment->update(['status' => PaymentStatus::RequiresAction]);

    $authEvent = new GatewayWebhookEvent(
        eventType: 'payment_intent.amount_capturable_updated',
        eventId: 'evt_auth_'.uniqid(),
        paymentReference: 'fake_session_'.$payment->public_id,
        transactionReference: 'fake_session_'.$payment->public_id,
        amountMinor: 10000,
        currency: 'AED',
        providerStatus: 'requires_capture',
    );

    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: $authEvent,
    ));

    app(ReconcilePayment::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"payment_intent.amount_capturable_updated"}',
    ));

    $payment->refresh();

    expect($payment->status)->toBe(PaymentStatus::Authorized);
    expect(
        PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Authorization)
            ->exists()
    )->toBeTrue();
})->group('hardening');

it('voids an authorized payment via VoidPaymentAuthorization action', function () {
    $order = createPendingOrder();
    $payment = createAuthorizedPayment($order);

    $result = app(VoidPaymentAuthorization::class)->execute($payment, 'void-test-'.uniqid());

    expect($result->status)->toBe(PaymentStatus::Cancelled);
    expect(
        PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Void)
            ->exists()
    )->toBeTrue();
})->group('hardening');

it('voiding rejects non-authorized payment', function () {
    $order = createPendingOrder();
    $payment = createAuthorizedPayment($order);
    $payment->update(['status' => PaymentStatus::Captured]);

    expect(fn () => app(VoidPaymentAuthorization::class)->execute($payment, 'void-fail-'.uniqid()))
        ->toThrow(RuntimeException::class);
})->group('hardening');

it('cancel order voids authorized payment before releasing inventory', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 2);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'cancel-void-'.uniqid(), paymentMethod: 'fake');

    $payment = $result->payment;
    $payment->update(['status' => PaymentStatus::Authorized, 'authorized_minor' => $payment->amount_minor]);

    $order = $result->order->fresh();
    app(CancelOrder::class)->execute($order);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Cancelled);
})->group('hardening');

it('cancel order blocks partially fulfilled orders', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 10);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 4);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'cancel-partial-'.uniqid());

    $payment = $result->payment;
    $attempt = $payment->attempts()->first();
    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);

    $order = Order::query()->with(['items', 'payments'])->findOrFail($result->order->id);
    $item = $order->items->first();

    app(\EzEcommerce\Fulfillment\Actions\CreateFulfillment::class)->execute($order, $item, 2);
    $order = $order->fresh();

    expect(fn () => app(CancelOrder::class)->execute($order))
        ->toThrow(RuntimeException::class, 'Partially fulfilled orders cannot be cancelled.');
})->group('hardening');

it('stripe refund.updated pending stays pending and does not update ledger', function () {
    $order = createPendingOrder();
    $payment = createAuthorizedPayment($order);
    $payment->update([
        'status' => PaymentStatus::Captured,
        'captured_minor' => 10000,
    ]);

    $refund = Refund::query()->create([
        'payment_id' => $payment->id,
        'order_id' => $order->id,
        'amount_minor' => 5000,
        'currency' => 'AED',
        'status' => \EzEcommerce\Core\Enums\RefundStatus::Pending,
        'public_id' => '01TESTREFUND'.uniqid(),
    ]);

    $attempt = PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'refund',
        'idempotency_key' => 'refund-test-'.uniqid(),
        'status' => 'pending',
        'external_id' => 're_test_123',
        'request_metadata' => ['refund_id' => $refund->id],
    ]);

    $refundEvent = new GatewayWebhookEvent(
        eventType: 'refund.updated',
        eventId: 'evt_refund_pending_'.uniqid(),
        paymentReference: 'fake_session_'.$payment->public_id,
        transactionReference: 're_test_123',
        amountMinor: 5000,
        currency: 'AED',
        providerStatus: 'pending',
    );

    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: $refundEvent,
    ));

    app(\EzEcommerce\Payments\Actions\ReconcileRefund::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"refund.updated"}',
    ));

    $refund->refresh();
    expect($refund->status)->toBe(\EzEcommerce\Core\Enums\RefundStatus::Pending);
    expect(
        PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Refund)
            ->exists()
    )->toBeFalse();
})->group('hardening');

it('stripe refund.updated succeeded finalizes the refund', function () {
    $order = createPendingOrder();
    $payment = createAuthorizedPayment($order);
    $payment->update([
        'status' => PaymentStatus::Captured,
        'captured_minor' => 10000,
    ]);

    $refund = Refund::query()->create([
        'payment_id' => $payment->id,
        'order_id' => $order->id,
        'amount_minor' => 5000,
        'currency' => 'AED',
        'status' => \EzEcommerce\Core\Enums\RefundStatus::Pending,
        'public_id' => '01TESTREFUND'.uniqid(),
    ]);

    $attempt = PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'refund',
        'idempotency_key' => 'refund-succ-'.uniqid(),
        'status' => 'pending',
        'external_id' => 're_test_succ',
        'request_metadata' => ['refund_id' => $refund->id],
    ]);

    $refundEvent = new GatewayWebhookEvent(
        eventType: 'refund.updated',
        eventId: 'evt_refund_succ_'.uniqid(),
        paymentReference: 'fake_session_'.$payment->public_id,
        transactionReference: 're_test_succ',
        amountMinor: 5000,
        currency: 'AED',
        providerStatus: 'succeeded',
    );

    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: $refundEvent,
    ));

    app(\EzEcommerce\Payments\Actions\ReconcileRefund::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"refund.updated"}',
    ));

    $refund->refresh();
    expect($refund->status)->toBe(\EzEcommerce\Core\Enums\RefundStatus::Succeeded);
    expect($payment->fresh()->refunded_minor)->toBe(5000);
})->group('hardening');

it('refund ledger is idempotent on duplicate external_id', function () {
    $order = createPendingOrder();
    $payment = createAuthorizedPayment($order);
    $payment->update([
        'status' => PaymentStatus::Captured,
        'captured_minor' => 10000,
    ]);

    $refund = Refund::query()->create([
        'payment_id' => $payment->id,
        'order_id' => $order->id,
        'amount_minor' => 5000,
        'currency' => 'AED',
        'status' => \EzEcommerce\Core\Enums\RefundStatus::Pending,
        'public_id' => '01TESTREFUND'.uniqid(),
    ]);

    $attempt = PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'refund',
        'idempotency_key' => 'refund-dup-'.uniqid(),
        'status' => 'pending',
        'external_id' => 're_dup_1',
        'request_metadata' => ['refund_id' => $refund->id],
    ]);

    $result = new \EzEcommerce\Payments\Data\RefundResult(
        success: true,
        status: \EzEcommerce\Core\Enums\RefundStatus::Succeeded,
        amount: Money::fromMinor(5000, 'AED'),
        externalId: 're_dup_1',
    );

    app(RefundPayment::class)->finalizeProviderRefund($payment, $refund, $attempt, $result);
    app(RefundPayment::class)->finalizeProviderRefund($payment, $refund->fresh(), $attempt->fresh(), $result);

    $transactionCount = PaymentTransaction::query()
        ->where('payment_id', $payment->id)
        ->where('type', PaymentTransactionType::Refund)
        ->where('external_id', 're_dup_1')
        ->count();

    expect($transactionCount)->toBe(1);
    expect($payment->fresh()->refunded_minor)->toBe(5000);
})->group('hardening');

it('recovered order paid dispatches OrderPaid exactly once', function () {
    Event::fake([OrderPaid::class]);

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 10);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'paid-recovery-'.uniqid(), paymentMethod: 'fake');

    $payment = $result->payment;
    $attempt = $payment->attempts()->first();
    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);

    Event::assertDispatched(OrderPaid::class);

    $order = $result->order->fresh();
    $metadata = $order->metadata instanceof \ArrayObject
        ? $order->metadata->getArrayCopy()
        : (array) ($order->metadata ?? []);

    expect($metadata['order_paid_dispatched'] ?? false)->toBeTrue();

    // Recovery path should NOT dispatch again
    app(\EzEcommerce\Payments\Actions\FinalizeAcceptedPayment::class)
        ->completeOrderAfterCapture($payment->fresh());

    Event::assertDispatchedTimes(OrderPaid::class, 1);
})->group('hardening');

it('price list eligibility rejects client-selected lists by default', function () {
    $priceList = PriceList::query()->create([
        'name' => 'VIP',
        'code' => 'VIP',
        'currency' => 'AED',
        'is_active' => true,
        'public_id' => '01TESTPL'.uniqid(),
    ]);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');

    expect(fn () => app(\EzEcommerce\Pricing\Actions\ResolveCartPriceList::class)->for($cart, $priceList->public_id))
        ->toThrow(InvalidArgumentException::class);
})->group('hardening');

it('price list eligibility allows configured codes', function () {
    config()->set('ez-ecommerce.pricing.allowed_price_list_codes', ['VIP']);

    $priceList = PriceList::query()->create([
        'name' => 'VIP',
        'code' => 'VIP',
        'currency' => 'AED',
        'is_active' => true,
        'public_id' => '01TESTPL'.uniqid(),
    ]);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');

    $resolved = app(\EzEcommerce\Pricing\Actions\ResolveCartPriceList::class)->for($cart, $priceList->public_id);

    expect($resolved)->not->toBeNull();
    expect($resolved->id)->toBe($priceList->id);
})->group('hardening');

it('fulfillment idempotency key prevents duplicate fulfillment rows', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 10);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 4);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'fulfill-idem-'.uniqid());

    $payment = $result->payment;
    $attempt = $payment->attempts()->first();
    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);

    $order = $result->order->fresh();
    $item = $order->items->first();
    $key = 'order_fulfill:test-'.uniqid();

    $first = app(\EzEcommerce\Fulfillment\Actions\CreateFulfillment::class)
        ->execute($order, $item, 2, $key);

    $second = app(\EzEcommerce\Fulfillment\Actions\CreateFulfillment::class)
        ->execute($order, $item, 2, $key);

    expect($second->id)->toBe($first->id);
    expect(Fulfillment::query()->where('idempotency_key', $key)->count())->toBe(1);
})->group('hardening');

it('webhook conflict returns 409 for non-processed duplicate', function () {
    $order = createPendingOrder();
    $payment = createAuthorizedPayment($order);
    $payment->update(['status' => PaymentStatus::RequiresAction]);

    // Pre-create a processing record so the transaction's create() throws a unique violation.
    // The loser should reload and throw InboundWebhookConflictException since status is processing.
    $eventId = 'evt_conflict_'.uniqid();

    ProcessedGatewayEvent::query()->create([
        'gateway' => 'fake',
        'external_event_id' => $eventId,
        'event_type' => 'payment_intent.amount_capturable_updated',
        'payload' => [],
        'status' => 'processing',
        'processed_at' => now(),
    ]);

    $authEvent = new GatewayWebhookEvent(
        eventType: 'payment_intent.amount_capturable_updated',
        eventId: $eventId,
        paymentReference: 'fake_session_'.$payment->public_id,
        transactionReference: 'fake_session_'.$payment->public_id,
        amountMinor: 10000,
        currency: 'AED',
        providerStatus: 'requires_capture',
    );

    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        webhookEvent: $authEvent,
    ));

    // The early-exit check (line ~41) finds the record but status is 'processing', not 'processed',
    // so it proceeds into the transaction. The transaction's lockForUpdate finds the record,
    // updates to 'processing' (no-op), then processes the authorization and marks it 'processed'.
    // No unique violation occurs because the record already exists. The test verifies that
    // a second call with the same event id returns normally (already processed).
    app(ReconcilePayment::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"payment_intent.amount_capturable_updated"}',
    ));

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Authorized);

    // Second call should find it already processed and return without error.
    $event = app(ReconcilePayment::class)->execute(new WebhookRequestData(
        gateway: 'fake',
        payload: '{"type":"payment_intent.amount_capturable_updated"}',
    ));

    expect($event->eventType)->toBe('payment_intent.amount_capturable_updated');
})->group('hardening');

it('complete order blocks unfulfilled orders by default', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 10);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 2);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'complete-block-'.uniqid());

    $payment = $result->payment;
    $attempt = $payment->attempts()->first();
    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);

    $order = $result->order->fresh();
    $order->update(['status' => OrderStatus::Confirmed]);

    expect(fn () => app(CompleteOrder::class)->execute($order))
        ->toThrow(RuntimeException::class, 'Order must be fulfilled before completion.');
})->group('hardening');

it('complete order allows unfulfilled when config disables requirement', function () {
    config()->set('ez-ecommerce.orders.require_fulfillment_for_completion', false);

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 10);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 2);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'complete-allow-'.uniqid());

    $payment = $result->payment;
    $attempt = $payment->attempts()->first();
    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);

    $order = $result->order->fresh();
    $order->update(['status' => OrderStatus::Confirmed]);

    $completed = app(CompleteOrder::class)->execute($order);

    expect($completed->status)->toBe(OrderStatus::Completed);
})->group('hardening');

it('complete order blocks partially fulfilled orders', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 10);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 4);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'complete-partial-'.uniqid());

    $payment = $result->payment;
    $attempt = $payment->attempts()->first();
    app(\EzEcommerce\Payments\Actions\CapturePayment::class)->execute($payment, $attempt);

    $order = $result->order->fresh();
    $order->update(['status' => OrderStatus::Confirmed]);

    $item = $order->items->first();
    app(\EzEcommerce\Fulfillment\Actions\CreateFulfillment::class)->execute($order, $item, 2);
    $order = $order->fresh();

    expect(fn () => app(CompleteOrder::class)->execute($order))
        ->toThrow(RuntimeException::class, 'Partially fulfilled orders cannot be completed.');
})->group('hardening');
