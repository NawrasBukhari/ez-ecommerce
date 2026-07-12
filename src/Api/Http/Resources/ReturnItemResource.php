<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Returns\Models\ReturnItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReturnItem */
final class ReturnItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_item_id' => $this->order_item_id,
            'quantity' => $this->quantity,
            'restock' => $this->restock,
            'damaged' => $this->damaged,
        ];
    }
}
