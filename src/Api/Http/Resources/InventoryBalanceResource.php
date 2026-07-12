<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Inventory\Models\InventoryBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryBalance */
final class InventoryBalanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'warehouse_id' => $this->warehouse?->public_id,
            'stockable_type' => $this->stockable_type,
            'stockable_id' => $this->stockable_id,
            'on_hand' => $this->on_hand,
            'reserved' => $this->reserved,
        ];
    }
}
