<?php

namespace EzEcommerce\Payments;

use EzEcommerce\Payments\Contracts\PaymentGateway;
use EzEcommerce\Payments\Drivers\FakePaymentGateway;
use EzEcommerce\Payments\Drivers\ManualPaymentGateway;
use EzEcommerce\Payments\Drivers\NullPaymentGateway;
use EzEcommerce\Payments\Drivers\PayPalPaymentGateway;
use EzEcommerce\Payments\Drivers\StripePaymentGateway;
use EzEcommerce\Payments\Drivers\TelrPaymentGateway;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

final class PaymentGatewayRegistry
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function for(string $driver): PaymentGateway
    {
        return match ($driver) {
            'null' => $this->app->make(NullPaymentGateway::class),
            'manual', 'net_terms' => $this->app->make(ManualPaymentGateway::class),
            'fake' => $this->app->make(FakePaymentGateway::class),
            'stripe' => $this->app->make(StripePaymentGateway::class),
            'paypal' => $this->app->make(PayPalPaymentGateway::class),
            'telr' => $this->app->make(TelrPaymentGateway::class),
            default => throw new InvalidArgumentException("Unknown payment gateway [{$driver}]."),
        };
    }
}
