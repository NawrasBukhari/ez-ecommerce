<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Catalog\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductVariant */
final class ProductVariantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'sku' => $this->sku,
            'name' => $this->name,
            'metadata' => $this->metadata?->toArray() ?? [],
        ];
    }
}
