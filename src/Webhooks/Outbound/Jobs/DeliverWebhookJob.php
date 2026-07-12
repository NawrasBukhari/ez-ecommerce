<?php

namespace EzEcommerce\Webhooks\Outbound\Jobs;

use EzEcommerce\Webhooks\Outbound\Actions\SignWebhookPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

final class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public string $url,
        public string $event,
        public array $payload,
    ) {}

    public function handle(SignWebhookPayload $signWebhookPayload): void
    {
        $body = json_encode([
            'event' => $this->event,
            'payload' => $this->payload,
            'timestamp' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Commerce-Event' => $this->event,
            'X-Commerce-Signature' => $signWebhookPayload->execute($body),
        ])->timeout(15)->post($this->url, json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }
}
