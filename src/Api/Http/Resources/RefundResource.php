<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Refunds\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Refund */
final class RefundResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'reason' => $this->reason,
        ];
    }
}
