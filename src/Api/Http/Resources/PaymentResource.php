<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Payments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
final class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'gateway' => $this->gateway,
            'status' => $this->status->value,
            'amount_minor' => $this->amount_minor,
            'captured_minor' => $this->captured_minor,
            'refunded_minor' => $this->refunded_minor,
            'currency' => $this->currency,
        ];
    }
}
