<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Inventory\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryMovement */
final class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'on_hand_delta' => $this->on_hand_delta,
            'reserved_delta' => $this->reserved_delta,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
