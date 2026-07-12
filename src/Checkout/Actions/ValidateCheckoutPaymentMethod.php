<?php

namespace EzEcommerce\Checkout\Actions;

use EzEcommerce\Payments\PaymentGatewayRegistry;
use InvalidArgumentException;

final class ValidateCheckoutPaymentMethod
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
    ) {
    }

    public function forPublicCheckout(string $paymentMethod, int $grandTotalMinor): void
    {
        $allowed = config('ez-ecommerce.checkout.public_payment_methods', ['stripe', 'paypal', 'telr']);

        if (! in_array($paymentMethod, $allowed, true)) {
            throw new InvalidArgumentException("Payment method [{$paymentMethod}] is not allowed for public checkout.");
        }

        if ($paymentMethod === 'fake' && ! app()->environment('local', 'testing')) {
            throw new InvalidArgumentException('Payment method [fake] is not allowed in this environment.');
        }

        if ($paymentMethod === 'null' && $grandTotalMinor > 0) {
            throw new InvalidArgumentException('Payment method [null] is only allowed for zero-total orders.');
        }

        $this->gateways->for($paymentMethod);
    }
}
