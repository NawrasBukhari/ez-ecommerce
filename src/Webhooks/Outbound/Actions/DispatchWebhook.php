<?php

namespace EzEcommerce\Webhooks\Outbound\Actions;

use EzEcommerce\Webhooks\Outbound\Jobs\DeliverWebhookJob;

final class DispatchWebhook
{
    public function execute(string $event, array $payload, ?string $endpoint = null): void
    {
        $endpoints = $endpoint !== null
            ? [['url' => $endpoint]]
            : config('ez-ecommerce.outbound_webhooks.endpoints', []);

        foreach ($endpoints as $config) {
            $url = $config['url'] ?? null;
            if ($url === null) {
                continue;
            }

            $events = $config['events'] ?? ['*'];
            if (! in_array('*', $events, true) && ! in_array($event, $events, true)) {
                continue;
            }

            DeliverWebhookJob::dispatch($url, $event, $payload);
        }
    }
}
