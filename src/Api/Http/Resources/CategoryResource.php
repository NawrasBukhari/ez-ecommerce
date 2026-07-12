<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Catalog\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Category */
final class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'parent_id' => $this->parent?->public_id,
            'description' => $this->description,
        ];
    }
}
