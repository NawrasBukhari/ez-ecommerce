<?php

use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function placeCheckoutOrder($cart, string $idempotencyKey, ?string $shippingMethod = 'flat', string $paymentMethod = 'manual')
{
    $cart = EzEcommerce::cart()->calculateTotals($cart, $shippingMethod);
    $hash = EzEcommerce::cart()->totalsHash($cart, $shippingMethod);

    return EzEcommerce::checkout()->for($cart)
        ->shippingMethod($shippingMethod)
        ->paymentMethod($paymentMethod)
        ->place(idempotencyKey: $idempotencyKey, expectedTotalsHash: $hash);
}
