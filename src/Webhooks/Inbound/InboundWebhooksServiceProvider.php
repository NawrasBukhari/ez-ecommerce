<?php

namespace EzEcommerce\Webhooks\Inbound;

use Illuminate\Support\ServiceProvider;

class InboundWebhooksServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if (! config('ez-ecommerce.features.api', false)) {
            return;
        }

        $this->loadRoutesFrom(dirname(__DIR__, 3).'/routes/webhooks.php');
    }
}
