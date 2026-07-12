<?php

namespace EzEcommerce\Shipping;

use EzEcommerce\Shipping\Contracts\ShippingCalculator;
use Illuminate\Support\ServiceProvider;

class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShippingCalculator::class, function ($app): ShippingCalculator {
            return match (config('ez-ecommerce.drivers.shipping.default', 'flat')) {
                default => $app->make(FlatShippingCalculator::class),
            };
        });
    }
}
