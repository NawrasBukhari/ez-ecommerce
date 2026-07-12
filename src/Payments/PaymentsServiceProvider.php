<?php

namespace EzEcommerce\Payments;

use EzEcommerce\Payments\Contracts\PaymentGateway;
use EzEcommerce\Payments\Drivers\FakePaymentGateway;
use EzEcommerce\Payments\Drivers\ManualPaymentGateway;
use EzEcommerce\Payments\Drivers\NullPaymentGateway;
use Illuminate\Support\ServiceProvider;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ManualPaymentGateway::class);
        $this->app->singleton(NullPaymentGateway::class);
        $this->app->singleton(FakePaymentGateway::class);

        $this->app->singleton(PaymentGateway::class, function ($app): PaymentGateway {
            $driver = config('ez-ecommerce.drivers.payment.default', 'manual');

            return match ($driver) {
                'null' => $app->make(NullPaymentGateway::class),
                'fake' => $app->make(FakePaymentGateway::class),
                default => $app->make(ManualPaymentGateway::class),
            };
        });
    }
}
