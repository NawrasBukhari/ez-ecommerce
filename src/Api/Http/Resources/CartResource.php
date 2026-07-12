<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Cart\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Cart */
final class CartResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'version' => $this->version,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'subtotal_minor' => $this->subtotal_minor,
            'discount_total_minor' => $this->discount_total_minor,
            'tax_total_minor' => $this->tax_total_minor,
            'shipping_total_minor' => $this->shipping_total_minor,
            'fee_total_minor' => $this->fee_total_minor,
            'grand_total_minor' => $this->grand_total_minor,
            'items' => CartItemResource::collection($this->whenLoaded('items')),
            'metadata' => $this->metadata?->toArray() ?? [],
        ];
    }
}
