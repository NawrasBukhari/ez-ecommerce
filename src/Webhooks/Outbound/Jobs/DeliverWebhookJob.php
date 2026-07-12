<?php

namespace EzEcommerce\Webhooks\Outbound\Jobs;

use EzEcommerce\Core\Enums\WebhookDeliveryStatus;
use EzEcommerce\Webhooks\Outbound\Actions\SignWebhookPayload;
use EzEcommerce\Webhooks\Outbound\Models\WebhookDelivery;
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

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public string $url,
        public string $event,
        public array $payload,
        public ?int $endpointId = null,
        public ?string $secret = null,
    ) {}

    public function handle(SignWebhookPayload $signWebhookPayload): void
    {
        $delivery = null;
        if ($this->endpointId !== null) {
            $delivery = WebhookDelivery::query()->create([
                'endpoint_id' => $this->endpointId,
                'event' => $this->event,
                'payload' => $this->payload,
                'status' => WebhookDeliveryStatus::Pending,
                'attempts' => 1,
            ]);
        }

        $body = json_encode([
            'event' => $this->event,
            'payload' => $this->payload,
            'timestamp' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Commerce-Event' => $this->event,
                'X-Commerce-Signature' => $signWebhookPayload->execute($body, $this->secret),
            ])->timeout(15)->post($this->url, json_decode($body, true, 512, JSON_THROW_ON_ERROR));

            if ($delivery !== null) {
                $delivery->update([
                    'status' => $response->successful()
                        ? WebhookDeliveryStatus::Delivered
                        : WebhookDeliveryStatus::Failed,
                    'response_code' => $response->status(),
                    'delivered_at' => $response->successful() ? now() : null,
                ]);
            }
        } catch (\Throwable $e) {
            $delivery?->update(['status' => WebhookDeliveryStatus::Failed]);
            throw $e;
        }
    }
}
