<?php

namespace EzEcommerce\Webhooks\Inbound;

use EzEcommerce\Webhooks\Inbound\Http\Controllers\InboundWebhookController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class InboundWebhooksServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if (! config('ez-ecommerce.features.api', false)) {
            return;
        }

        $gateways = app()->environment('local', 'testing')
            ? 'stripe|paypal|telr|fake|null|manual'
            : 'stripe|paypal|telr';

        Route::prefix(config('ez-ecommerce.api.prefix', 'api/ez-commerce/v1'))
            ->middleware(config('ez-ecommerce.api.middleware', ['api']))
            ->group(function () use ($gateways): void {
                Route::post('webhooks/{gateway}', InboundWebhookController::class)
                    ->where('gateway', $gateways);
            });
    }
}
