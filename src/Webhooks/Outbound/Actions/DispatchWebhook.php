<?php

namespace EzEcommerce\Webhooks\Outbound\Actions;

use EzEcommerce\Core\Models\OutboxMessage;
use EzEcommerce\Webhooks\Outbound\Jobs\DeliverWebhookJob;
use EzEcommerce\Webhooks\Outbound\Models\WebhookEndpoint;

final class DispatchWebhook
{
    public function execute(string $event, array $payload, ?string $endpoint = null): void
    {
        OutboxMessage::query()->create([
            'event' => $event,
            'payload' => $payload,
        ]);

        $targets = $this->resolveTargets($event, $endpoint);

        foreach ($targets as $target) {
            DeliverWebhookJob::dispatch(
                $target['url'],
                $event,
                $payload,
                $target['endpoint_id'] ?? null,
                $target['secret'] ?? null,
            );
        }

        OutboxMessage::query()
            ->where('event', $event)
            ->whereNull('processed_at')
            ->latest('id')
            ->limit(1)
            ->update(['processed_at' => now()]);
    }

    /** @return list<array{url: string, endpoint_id?: int, secret?: string}> */
    private function resolveTargets(string $event, ?string $endpoint): array
    {
        if ($endpoint !== null) {
            return [['url' => $endpoint]];
        }

        $targets = [];

        foreach (config('ez-ecommerce.outbound_webhooks.endpoints', []) as $config) {
            $url = $config['url'] ?? null;
            if ($url === null) {
                continue;
            }

            $events = $config['events'] ?? ['*'];
            if (! in_array('*', $events, true) && ! in_array($event, $events, true)) {
                continue;
            }

            $targets[] = [
                'url' => $url,
                'secret' => $config['secret'] ?? null,
            ];
        }

        if (config('ez-ecommerce.features.outbound_webhooks', false)) {
            WebhookEndpoint::query()
                ->where('active', true)
                ->get()
                ->each(function (WebhookEndpoint $row) use ($event, &$targets): void {
                    $events = $row->events?->toArray() ?? ['*'];
                    if (! in_array('*', $events, true) && ! in_array($event, $events, true)) {
                        return;
                    }

                    $targets[] = [
                        'url' => $row->url,
                        'endpoint_id' => $row->id,
                        'secret' => $row->secret,
                    ];
                });
        }

        return $targets;
    }
}
