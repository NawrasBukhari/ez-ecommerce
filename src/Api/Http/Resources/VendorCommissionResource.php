<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Marketplace\Models\VendorCommission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VendorCommission */
final class VendorCommissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order?->public_id,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'payout_id' => $this->payout?->public_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
