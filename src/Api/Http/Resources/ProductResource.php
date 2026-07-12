<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
final class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'type' => $this->type,
            'metadata' => $this->metadata?->toArray() ?? [],
            'variants' => ProductVariantResource::collection($this->whenLoaded('variants')),
        ];
    }
}
