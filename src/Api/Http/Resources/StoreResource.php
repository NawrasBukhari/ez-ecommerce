<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Stores\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Store */
final class StoreResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'currency' => $this->currency,
            'metadata' => $this->metadata?->toArray() ?? [],
        ];
    }
}
