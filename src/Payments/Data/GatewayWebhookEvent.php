<?php

namespace EzEcommerce\Payments\Data;

final readonly class GatewayWebhookEvent
{
    /** @param  array<string, mixed>  $metadata */
    public function __construct(
        public string $eventType,
        public string $externalId,
        public ?string $paymentExternalId = null,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public array $metadata = [],
    ) {}
}
