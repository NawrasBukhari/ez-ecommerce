<?php

use EzEcommerce\Core\Enums\CheckoutStatus;
use EzEcommerce\Core\Enums\IdempotencyStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Core\Enums\ReservationStatus;
use EzEcommerce\Core\Events\OrderPaid;
use EzEcommerce\Core\Exceptions\IdempotencyPayloadMismatchException;
use EzEcommerce\Core\Models\IdempotencyRecord;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Fulfillment\Actions\CreateFulfillment;
use EzEcommerce\Inventory\Exceptions\ReservationExpiredException;
use EzEcommerce\Payments\Actions\ApplyPaymentCapture;
use EzEcommerce\Payments\Actions\CapturePayment;
use EzEcommerce\Payments\Actions\FinalizeAcceptedPayment;
use EzEcommerce\Payments\Actions\ReconcileCaptureAttempt;
use EzEcommerce\Payments\Data\PaymentFailure;
use EzEcommerce\Payments\Data\PaymentResult;
use EzEcommerce\Payments\Drivers\FakePaymentGateway;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use EzEcommerce\Payments\Support\PaymentAttemptRequest;
use EzEcommerce\Refunds\Actions\RefundPayment;
use EzEcommerce\Tests\Support\SetsUpCatalog;
use Illuminate\Support\Facades\Event;

uses(SetsUpCatalog::class, \EzEcommerce\Tests\Support\UsesCommerceApi::class);

it('confirms order after manual capture', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'confirm-'.uniqid());

    $payment = $result->payment;
    $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->first();
    app(CapturePayment::class)->execute($payment, $attempt);

    expect($result->order->fresh()->status)->toBe(OrderStatus::Confirmed);
})->group('hardening');

it('prevents over-fulfillment on the same line', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 10);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 2);
    $result = placeCheckoutOrder($cart, 'fulfill-'.uniqid());

    $payment = $result->payment;
    $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->first();
    app(CapturePayment::class)->execute($payment, $attempt);

    $order = $result->order->fresh();
    $item = $order->items->first();

    app(CreateFulfillment::class)->execute($order, $item, 1);
    app(CreateFulfillment::class)->execute($order, $item, 1);

    expect(fn () => app(CreateFulfillment::class)->execute($order, $item, 1))
        ->toThrow(RuntimeException::class);
})->group('hardening');

it('rejects refund above captured balance', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'refund-cap-'.uniqid());

    $payment = $result->payment;
    $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->first();
    app(CapturePayment::class)->execute($payment, $attempt);

    expect(fn () => app(RefundPayment::class)->execute(
        $payment->fresh(),
        Money::fromMinor(999999, 'AED'),
        'too much',
        'refund-cap-'.uniqid(),
    ))->toThrow(InvalidArgumentException::class);
})->group('hardening');

it('returns cached checkout after payment session failure', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 100, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);

    $key = 'idem-fail-'.uniqid();
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $hash = EzEcommerce::cart()->totalsHash($cart, 'flat');

    $first = EzEcommerce::checkout()->for($cart)
        ->shippingMethod('flat')
        ->paymentMethod('null')
        ->place(idempotencyKey: $key, expectedTotalsHash: $hash);

    expect($first->status)->toBe(CheckoutStatus::PaymentSessionFailed);
    expect($first->order)->not->toBeNull();
    expect($first->payment)->not->toBeNull();

    $record = IdempotencyRecord::query()->where('key', $key)->first();
    expect($record)->not->toBeNull();
    expect($record->status)->toBe(IdempotencyStatus::Completed);

    $second = EzEcommerce::checkout()->for($cart->fresh())
        ->shippingMethod('flat')
        ->paymentMethod('null')
        ->place(idempotencyKey: $key, expectedTotalsHash: $hash);

    expect($second->order->id)->toBe($first->order->id);
    expect($second->payment->id)->toBe($first->payment->id);
    expect($second->status)->toBe(CheckoutStatus::PaymentSessionFailed);
})->group('hardening');

it('persists checkout payment session for redirect gateways', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 1000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);

    $key = 'idem-session-'.uniqid();
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $hash = EzEcommerce::cart()->totalsHash($cart, 'flat');

    $first = EzEcommerce::checkout()->for($cart)
        ->shippingMethod('flat')
        ->paymentMethod('fake')
        ->place(idempotencyKey: $key, expectedTotalsHash: $hash);

    expect($first->status)->toBe(CheckoutStatus::RequiresAction);
    expect($first->paymentSession)->not->toBeNull();
    expect($first->paymentSession->clientSecret)->not->toBeNull();

    $second = EzEcommerce::checkout()->for($cart->fresh())
        ->shippingMethod('flat')
        ->paymentMethod('fake')
        ->place(idempotencyKey: $key, expectedTotalsHash: $hash);

    expect($second->paymentSession?->externalId)->toBe($first->paymentSession?->externalId);
})->group('hardening');

it('does not confirm order when reservations are expired', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'expired-res-'.uniqid());

    $order = $result->order->fresh();
    $order->reservations()->update(['status' => ReservationStatus::Expired]);

    $payment = $result->payment;
    $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->first();

    expect(fn () => app(CapturePayment::class)->execute($payment, $attempt))
        ->toThrow(ReservationExpiredException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
})->group('hardening');

it('keeps partially captured payment status when a follow-up capture fails', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'partial-cap-'.uniqid());

    $payment = $result->payment;
    $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->first();

    app(ApplyPaymentCapture::class)->execute(
        $payment,
        $attempt,
        4000,
        'AED',
        'partial-capture-1',
    );
    $attempt->update(['status' => 'succeeded']);

    $payment = $payment->fresh();
    expect($payment->status)->toBe(PaymentStatus::PartiallyCaptured);

    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        captureResult: new PaymentResult(
            success: false,
            status: PaymentStatus::Failed,
            amount: Money::fromMinor(6000, 'AED'),
            failure: new PaymentFailure('declined', 'Capture declined', false),
        ),
    ));

    $secondAttempt = PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'create_session',
        'idempotency_key' => 'partial-capture-2',
        'status' => 'succeeded',
    ]);

    app(CapturePayment::class)->execute($payment, $secondAttempt, Money::fromMinor(6000, 'AED'));

    expect($payment->fresh()->status)->toBe(PaymentStatus::PartiallyCaptured);
})->group('hardening');

it('dispatches OrderPaid when partial capture is later completed', function () {
    Event::fake([OrderPaid::class]);

    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'partial-paid-'.uniqid());

    $payment = $result->payment->fresh();
    $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->first();
    $partialAmount = (int) floor($payment->amount_minor / 2);
    $remainder = $payment->amount_minor - $partialAmount;

    app(ApplyPaymentCapture::class)->execute(
        $payment,
        $attempt,
        $partialAmount,
        $payment->currency,
        'partial-capture-1',
    );
    $attempt->update(['status' => 'succeeded']);

    $payment = $payment->fresh();
    app(FinalizeAcceptedPayment::class)->completeOrderAfterCapture($payment);

    Event::assertNotDispatched(OrderPaid::class);

    $secondAttempt = PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'create_session',
        'idempotency_key' => 'partial-capture-2',
        'status' => 'succeeded',
    ]);

    app(CapturePayment::class)->execute(
        $payment,
        $secondAttempt,
        Money::fromMinor($remainder, $payment->currency),
    );

    expect($payment->fresh()->status)->toBe(PaymentStatus::Captured);
    Event::assertDispatched(OrderPaid::class, 1);
})->group('hardening');

it('blocks a new capture while an unknown capture requires reconciliation', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'unknown-cap-'.uniqid());

    $payment = $result->payment;
    $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->first();

    $attempt->update([
        'operation' => 'capture',
        'status' => 'unknown',
        'idempotency_key' => 'capture-unknown-1',
    ]);

    $secondAttempt = PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'create_session',
        'idempotency_key' => 'capture-unknown-2',
        'status' => 'succeeded',
    ]);

    expect(fn () => app(CapturePayment::class)->execute($payment->fresh(), $secondAttempt))
        ->toThrow(RuntimeException::class, 'reconciliation');
})->group('hardening');

it('rejects refund idempotency key reuse with a different amount', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'refund-idem-'.uniqid());

    $payment = $result->payment;
    $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->first();
    app(CapturePayment::class)->execute($payment, $attempt);

    $key = 'refund-idem-key';
    app(RefundPayment::class)->execute(
        $payment->fresh(),
        Money::fromMinor(1000, 'AED'),
        'partial',
        $key,
    );

    expect(fn () => app(RefundPayment::class)->execute(
        $payment->fresh(),
        Money::fromMinor(2000, 'AED'),
        'partial',
        $key,
    ))->toThrow(IdempotencyPayloadMismatchException::class);
})->group('hardening');

it('records provider-confirmed unknown capture in the ledger', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'ledger-cap-'.uniqid());

    $payment = $result->payment->fresh();
    $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->first();
    $attempt->update([
        'operation' => 'capture',
        'status' => 'unknown',
        'idempotency_key' => 'ledger-cap-attempt',
    ]);
    PaymentAttemptRequest::merge($attempt, [
        'requested_amount_minor' => $payment->amount_minor,
        'currency' => $payment->currency,
        'provider_operation' => 'capture',
    ]);

    app(ReconcileCaptureAttempt::class)
        ->confirmProviderSucceeded($attempt->fresh(), $payment->amount_minor, $payment->currency, 'provider_capture_1');

    $payment = $payment->fresh();
    expect($payment->captured_minor)->toBe($payment->amount_minor);
    expect($payment->status)->toBe(PaymentStatus::Captured);
    expect($result->order->fresh()->status)->toBe(OrderStatus::Confirmed);
})->group('hardening');

it('retries unknown capture using the stored request amount snapshot', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'snapshot-cap-'.uniqid());

    $payment = $result->payment;
    app(ApplyPaymentCapture::class)->execute($payment, null, 3000, 'AED', 'partial-1');

    $attempt = PaymentAttempt::query()->create([
        'payment_id' => $payment->id,
        'operation' => 'capture',
        'status' => 'unknown',
        'idempotency_key' => 'snapshot-cap-attempt',
    ]);
    PaymentAttemptRequest::merge($attempt, [
        'requested_amount_minor' => 4000,
        'currency' => 'AED',
        'provider_operation' => 'capture',
    ]);

    $this->app->instance(FakePaymentGateway::class, new FakePaymentGateway(
        captureResult: new PaymentResult(
            success: true,
            status: PaymentStatus::Captured,
            amount: Money::fromMinor(4000, 'AED'),
            externalId: 'snapshot_capture_ok',
        ),
    ));

    app(ReconcileCaptureAttempt::class)->retry($attempt->fresh());

    expect($payment->fresh()->captured_minor)->toBe(7000);
})->group('hardening');

it('treats post-capture webhook as idempotent when payment is already fully captured', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $result = placeCheckoutOrder($cart, 'webhook-idem-'.uniqid());

    $payment = $result->payment->fresh();
    $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->first();

    app(ApplyPaymentCapture::class)->execute(
        $payment,
        $attempt,
        $payment->amount_minor,
        $payment->currency,
        'ch_direct_capture',
    );

    $payment = $payment->fresh();
    expect($payment->status)->toBe(PaymentStatus::Captured);

    app(ApplyPaymentCapture::class)->execute(
        $payment,
        null,
        $payment->amount_minor,
        $payment->currency,
        'ch_webhook_retry',
    );

    expect($payment->fresh()->captured_minor)->toBe($payment->amount_minor);
    expect(PaymentTransaction::query()
        ->where('payment_id', $payment->id)
        ->where('type', PaymentTransactionType::Capture)
        ->count())->toBe(1);
})->group('hardening');

it('rejects concurrent cart version bumps', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 1000, stock: 10);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = $cart->fresh();
    $version = $cart->version;

    EzEcommerce::cart()->addItem($cart, $variant, 1, $version);

    expect(fn () => EzEcommerce::cart()->addItem($cart->fresh(), $variant, 1, $version))
        ->toThrow(\EzEcommerce\Cart\Exceptions\CartVersionConflictException::class);
})->group('hardening');

it('includes shipping address in totals hash', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);

    $address = new \EzEcommerce\Customers\Models\Address([
        'type' => 'shipping',
        'line1' => '1 Test St',
        'city' => 'Dubai',
        'country_code' => 'AE',
    ]);

    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat', $address);
    $hashWithAddress = EzEcommerce::cart()->totalsHash($cart, 'flat', $address);
    $hashWithoutAddress = EzEcommerce::cart()->totalsHash($cart, 'flat');

    expect($hashWithAddress)->not->toBe($hashWithoutAddress);
})->group('hardening');

it('replays cancel with the same idempotency key', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $result = placeCheckoutOrder($cart, 'cancel-idem-'.uniqid());

    $key = 'cancel-key-'.uniqid();
    $headers = array_merge($this->commerceApiHeaders(), ['Idempotency-Key' => $key]);

    $this->withHeaders($headers)
        ->postJson("/api/ez-commerce/v1/orders/{$result->order->public_id}/cancel", ['reason' => 'Changed mind'])
        ->assertOk();

    $this->withHeaders($headers)
        ->postJson("/api/ez-commerce/v1/orders/{$result->order->public_id}/cancel", ['reason' => 'Changed mind'])
        ->assertOk()
        ->assertJsonPath('status', OrderStatus::Cancelled->value);
})->group('hardening');
