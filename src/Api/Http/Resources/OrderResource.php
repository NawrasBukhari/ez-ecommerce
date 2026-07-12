<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Orders\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
final class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'payment_status' => $this->payment_status->value,
            'fulfillment_status' => $this->fulfillment_status->value,
            'currency' => $this->currency,
            'subtotal_minor' => $this->subtotal_minor,
            'discount_total_minor' => $this->discount_total_minor,
            'tax_total_minor' => $this->tax_total_minor,
            'shipping_total_minor' => $this->shipping_total_minor,
            'fee_total_minor' => $this->fee_total_minor,
            'grand_total_minor' => $this->grand_total_minor,
            'refunded_total_minor' => $this->refunded_total_minor,
            'shipping_method' => $this->shipping_method,
            'payment_method' => $this->payment_method,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'metadata' => $this->metadata?->toArray() ?? [],
        ];
    }
}
