<?php

use EzEcommerce\Core\Enums\CheckoutStatus;
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
