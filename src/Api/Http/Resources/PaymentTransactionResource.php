<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Payments\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentTransaction */
final class PaymentTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'status' => $this->status,
            'external_id' => $this->external_id,
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}
