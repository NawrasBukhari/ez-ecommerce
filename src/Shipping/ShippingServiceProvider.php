<?php

namespace EzEcommerce\Shipping;

use EzEcommerce\Shipping\Contracts\ShippingCalculator;
use Illuminate\Support\ServiceProvider;

class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShippingCalculator::class, FlatShippingCalculator::class);
    }
}
