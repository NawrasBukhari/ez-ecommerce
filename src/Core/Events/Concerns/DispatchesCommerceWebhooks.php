<?php

namespace EzEcommerce\Core\Events\Concerns;

use EzEcommerce\Webhooks\Outbound\Actions\DispatchWebhook;

trait DispatchesCommerceWebhooks
{
    protected function dispatchCommerceWebhook(string $event, array $payload): void
    {
        if (! config('ez-ecommerce.features.outbound_webhooks', false)) {
            return;
        }

        app(DispatchWebhook::class)->execute($event, $payload);
    }
}
