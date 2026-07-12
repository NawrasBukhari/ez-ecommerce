<?php

use EzEcommerce\Webhooks\Inbound\Http\Controllers\InboundWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix(config('ez-ecommerce.api.prefix', 'api/ez-commerce/v1'))
    ->middleware(config('ez-ecommerce.api.middleware', ['api']))
    ->group(function (): void {
        Route::post('webhooks/{gateway}', InboundWebhookController::class)
            ->where('gateway', 'stripe|paypal|telr|manual|fake|null');
    });
