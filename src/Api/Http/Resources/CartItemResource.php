<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Cart\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CartItem */
final class CartItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            'unit_price_minor' => $this->unit_price_minor,
            'currency' => $this->currency,
            'purchasable_type' => $this->purchasable_type,
            'purchasable_id' => $this->purchasable?->public_id ?? $this->purchasable_id,
            'metadata' => $this->metadata?->toArray() ?? [],
        ];
    }
}
