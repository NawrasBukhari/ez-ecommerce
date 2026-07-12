<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Inventory\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Warehouse */
final class WarehouseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'code' => $this->code,
            'is_active' => $this->is_active,
            'metadata' => $this->metadata?->toArray() ?? [],
        ];
    }
}
