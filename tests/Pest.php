<?php

use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Tests\TestCase;

// TestCase + RefreshDatabase for ordinary feature tests. Race tests live in
// tests/Races and use RaceTestCase (no per-test transaction) so child worker
// processes can see committed setup data.
uses(TestCase::class)->in(__DIR__.'/Feature', __DIR__.'/Support');

function placeCheckoutOrder($cart, string $idempotencyKey, ?string $shippingMethod = 'flat', string $paymentMethod = 'manual')
{
    $cart = $cart->fresh();
    $cart = EzEcommerce::cart()->calculateTotals($cart, $shippingMethod);
    $hash = EzEcommerce::cart()->totalsHash($cart, $shippingMethod);

    return EzEcommerce::checkout()->for($cart->fresh())
        ->shippingMethod($shippingMethod)
        ->paymentMethod($paymentMethod)
        ->place(idempotencyKey: $idempotencyKey, expectedTotalsHash: $hash);
}
