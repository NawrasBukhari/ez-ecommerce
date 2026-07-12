<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Marketplace\Models\VendorPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VendorPayout */
final class VendorPayoutResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'vendor_id' => $this->vendor?->public_id,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'commission_count' => $this->commission_count,
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
