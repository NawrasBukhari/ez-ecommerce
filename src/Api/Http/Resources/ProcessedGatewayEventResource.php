<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Webhooks\Inbound\Models\ProcessedGatewayEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProcessedGatewayEvent */
final class ProcessedGatewayEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gateway' => $this->gateway,
            'external_event_id' => $this->external_event_id,
            'event_type' => $this->event_type,
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}
