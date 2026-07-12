<?php

use EzEcommerce\Core\Enums\IdempotencyStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Models\IdempotencyRecord;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Fulfillment\Actions\CreateFulfillment;
use EzEcommerce\Payments\Actions\CapturePayment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Refunds\Actions\RefundPayment;
use EzEcommerce\Tests\Support\SetsUpCatalog;

uses(SetsUpCatalog::class);

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

it('persists failed_retryable idempotency after gateway exception', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 100, stock: 5);
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);

    $key = 'idem-fail-'.uniqid();

    try {
        placeCheckoutOrder($cart, $key, 'flat', 'null');
    } catch (InvalidArgumentException) {
        // null gateway rejects non-zero totals
    }

    $record = IdempotencyRecord::query()->where('key', $key)->first();
    expect($record)->not->toBeNull();
    expect($record->status)->toBe(IdempotencyStatus::FailedRetryable);
})->group('hardening');
