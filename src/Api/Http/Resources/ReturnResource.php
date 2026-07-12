<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Returns\Models\ReturnRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReturnRequest */
final class ReturnResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'order_id' => $this->order?->public_id,
            'customer_id' => $this->customer?->public_id,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'items' => ReturnItemResource::collection($this->whenLoaded('items')),
            'metadata' => $this->metadata?->toArray() ?? [],
        ];
    }
}
