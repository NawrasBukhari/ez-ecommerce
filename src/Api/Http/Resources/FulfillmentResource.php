<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Fulfillment\Models\Fulfillment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Fulfillment */
final class FulfillmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'order_item_id' => $this->order_item_id,
            'quantity' => $this->quantity,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
