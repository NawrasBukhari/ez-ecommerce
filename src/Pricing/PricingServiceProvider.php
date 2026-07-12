<?php

namespace EzEcommerce\Pricing;

use EzEcommerce\Pricing\Contracts\PriceListEligibility;
use EzEcommerce\Pricing\Contracts\PriceResolver;
use Illuminate\Support\ServiceProvider;

class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PriceResolver::class, DefaultPriceResolver::class);
        $this->app->bind(PriceListEligibility::class, DefaultPriceListEligibility::class);
    }
}
