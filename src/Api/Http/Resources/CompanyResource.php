<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\B2B\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
final class CompanyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'tax_id' => $this->tax_id,
            'payment_terms_days' => $this->payment_terms_days,
            'metadata' => $this->metadata?->toArray() ?? [],
        ];
    }
}
