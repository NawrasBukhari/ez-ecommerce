<?php

namespace EzEcommerce\Webhooks\Outbound;

use EzEcommerce\Core\Events\OrderPaid;
use EzEcommerce\Core\Events\OrderPlaced;
use EzEcommerce\Webhooks\Outbound\Actions\DispatchWebhook;
use EzEcommerce\Webhooks\Outbound\Actions\SignWebhookPayload;
use EzEcommerce\Webhooks\Outbound\Listeners\QueueCommerceWebhooks;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class OutboundWebhooksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DispatchWebhook::class);
        $this->app->singleton(SignWebhookPayload::class);
        $this->app->singleton(QueueCommerceWebhooks::class);
    }

    public function boot(): void
    {
        if (! config('ez-ecommerce.features.outbound_webhooks', false)) {
            return;
        }

        $listener = QueueCommerceWebhooks::class;
        Event::listen(OrderPlaced::class, [$listener, 'handleOrderPlaced']);
        Event::listen(OrderPaid::class, [$listener, 'handleOrderPaid']);
    }
}
