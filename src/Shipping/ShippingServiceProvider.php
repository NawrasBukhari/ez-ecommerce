<?php

namespace EzEcommerce\Shipping;

use EzEcommerce\Shipping\Contracts\ShippingCalculator;
use Illuminate\Support\ServiceProvider;

class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FlatShippingCalculator::class);
        $this->app->singleton(WeightShippingCalculator::class);

        $this->app->bind(ShippingCalculator::class, DelegatingShippingCalculator::class);
    }
}
