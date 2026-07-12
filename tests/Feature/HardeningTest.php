<?php

use EzEcommerce\Core\Enums\OrderPaymentStatus;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Fulfillment\Actions\CreateFulfillment;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Actions\CapturePayment;
use EzEcommerce\Payments\Data\CreatePaymentSessionData;
use EzEcommerce\Payments\Drivers\NullPaymentGateway;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Refunds\Actions\RefundPayment;
use EzEcommerce\Tests\Support\SetsUpCatalog;

uses(SetsUpCatalog::class);

it('captures manual payment, fulfills, and partially refunds', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 10);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 2);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');

    $result = EzEcommerce::checkout()->for($cart)
        ->shippingMethod('flat')
        ->paymentMethod('manual')
        ->place(idempotencyKey: 'full-flow-'.uniqid());

    $payment = $result->payment;
    $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->first();

    app(CapturePayment::class)->execute($payment, $attempt);
    $payment->refresh();
    $order = $result->order->fresh();

    expect($payment->status->value)->toBe('captured');
    expect($order->payment_status)->toBe(OrderPaymentStatus::Paid);

    $item = $order->items->first();
    app(CreateFulfillment::class)->execute($order, $item, 1);

    $refund = app(RefundPayment::class)->execute(
        $payment,
        Money::fromMinor(2500, 'AED'),
        'partial refund test',
        'refund-'.uniqid(),
    );

    expect($refund->status->value)->toBe('succeeded');
    expect($payment->fresh()->refunded_minor)->toBe(2500);
})->group('hardening');

it('returns same order for repeated idempotency key', function () {
    ['variant' => $variant] = $this->createProductWithVariant();
    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');
    $hash = EzEcommerce::cart()->totalsHash($cart, 'flat');
    $key = 'repeat-'.uniqid();

    $first = EzEcommerce::checkout()->for($cart)
        ->shippingMethod('flat')
        ->paymentMethod('manual')
        ->place(idempotencyKey: $key, expectedTotalsHash: $hash);

    $second = EzEcommerce::checkout()->for($cart->fresh())
        ->shippingMethod('flat')
        ->paymentMethod('manual')
        ->place(idempotencyKey: $key, expectedTotalsHash: $hash);

    expect($second->order->id)->toBe($first->order->id);
    expect($second->payment->id)->toBe($first->payment->id);
})->group('hardening');

it('rejects checkout when inventory is insufficient', function () {
    ['variant' => $variant] = $this->createProductWithVariant(stock: 1);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 5);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');

    EzEcommerce::checkout()->for($cart)
        ->shippingMethod('flat')
        ->paymentMethod('manual')
        ->place(idempotencyKey: 'insufficient-'.uniqid());
})->throws(RuntimeException::class)->group('hardening');

it('null gateway rejects non-zero totals', function () {
    $payment = Payment::query()->make([
        'amount_minor' => 100,
        'currency' => 'AED',
        'public_id' => '01TESTNULLGATEWAY00000001',
    ]);

    expect(fn () => app(NullPaymentGateway::class)
        ->createSession(new CreatePaymentSessionData(
            payment: $payment,
            attempt: PaymentAttempt::query()->make(),
            order: Order::query()->make(),
            amount: Money::fromMinor(100, 'AED'),
        )))->toThrow(InvalidArgumentException::class);
});
