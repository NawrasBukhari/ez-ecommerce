<?php

namespace EzEcommerce\Fulfillment;

use EzEcommerce\Fulfillment\Contracts\FulfillmentReleasePolicy;
use Illuminate\Support\ServiceProvider;

class FulfillmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FulfillmentReleasePolicy::class, DefaultFulfillmentReleasePolicy::class);
    }
}
