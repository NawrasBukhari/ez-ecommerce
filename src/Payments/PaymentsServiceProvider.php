<?php

namespace EzEcommerce\Payments;

use EzEcommerce\Payments\Contracts\PaymentGateway;
use EzEcommerce\Payments\Drivers\FakePaymentGateway;
use EzEcommerce\Payments\Drivers\ManualPaymentGateway;
use EzEcommerce\Payments\Drivers\NullPaymentGateway;
use EzEcommerce\Payments\Drivers\PayPalPaymentGateway;
use EzEcommerce\Payments\Drivers\StripePaymentGateway;
use EzEcommerce\Payments\Drivers\TelrPaymentGateway;
use Illuminate\Support\ServiceProvider;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ManualPaymentGateway::class);
        $this->app->singleton(NullPaymentGateway::class);
        $this->app->singleton(FakePaymentGateway::class);
        $this->app->singleton(StripePaymentGateway::class);
        $this->app->singleton(PayPalPaymentGateway::class);
        $this->app->singleton(TelrPaymentGateway::class);
        $this->app->singleton(PaymentGatewayRegistry::class);

        $this->app->singleton(PaymentGateway::class, function ($app): PaymentGateway {
            $driver = config('ez-ecommerce.drivers.payment.default', 'manual');

            return $app->make(PaymentGatewayRegistry::class)->for($driver);
        });
    }
}
