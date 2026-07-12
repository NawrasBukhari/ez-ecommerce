<?php

use EzEcommerce\CommerceManager;
use EzEcommerce\Core\Enums\CheckoutStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Exceptions\IdempotencyPayloadMismatchException;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Tests\Support\SetsUpCatalog;

uses(SetsUpCatalog::class);

it('boots the package and resolves the commerce manager', function () {
    expect(config('ez-ecommerce.currency.default'))->toBe('AED');
    expect(app(CommerceManager::class))->toBeInstanceOf(CommerceManager::class);
    expect(EzEcommerce::cart())->not->toBeNull();
});

it('enables day-1 features by default', function () {
    expect(config('ez-ecommerce.features.api'))->toBeTrue();
    expect(config('ez-ecommerce.features.outbound_webhooks'))->toBeTrue();
});

it('completes the core transactional path', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000, stock: 5);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 2);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $hash = EzEcommerce::cart()->totalsHash($cart, 'flat');

    $result = EzEcommerce::checkout()->for($cart)
        ->shippingMethod('flat')
        ->paymentMethod('manual')
        ->place(idempotencyKey: 'checkout-'.uniqid(), expectedTotalsHash: $hash);

    expect($result->status)->toBe(CheckoutStatus::PendingPayment);
    expect($result->order->status)->toBe(OrderStatus::PendingPayment);
    expect($result->payment->amount_minor)->toBe($cart->grand_total_minor);
});

it('rejects idempotency key reuse with different payload', function () {
    ['variant' => $variant] = $this->createProductWithVariant();
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $key = 'idem-'.uniqid();

    EzEcommerce::checkout()->for($cart)
        ->shippingMethod('flat')
        ->paymentMethod('manual')
        ->place(idempotencyKey: $key, expectedTotalsHash: EzEcommerce::cart()->totalsHash($cart, 'flat'));

    ['cart' => $cart2] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart2, $variant, 2);
    $cart2 = EzEcommerce::cart()->calculateTotals($cart2, 'flat');

    EzEcommerce::checkout()->for($cart2)
        ->shippingMethod('flat')
        ->paymentMethod('manual')
        ->place(idempotencyKey: $key, expectedTotalsHash: EzEcommerce::cart()->totalsHash($cart2, 'flat'));
})->throws(IdempotencyPayloadMismatchException::class);

it('resolves money from minor units', function () {
    $money = Money::fromMinor(129900, 'AED');
    expect($money->minorAmount)->toBe(129900);
    expect($money->currency)->toBe('AED');
});
