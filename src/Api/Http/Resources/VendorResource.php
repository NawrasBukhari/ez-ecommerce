<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Marketplace\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Vendor */
final class VendorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'commission_rate' => (float) $this->commission_rate,
            'metadata' => $this->metadata?->toArray() ?? [],
        ];
    }
}
