<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Orders\Models\OrderTransition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderTransition */
final class OrderTransitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'dimension' => $this->dimension->value,
            'from_state' => $this->from_state,
            'to_state' => $this->to_state,
            'reason' => $this->reason,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
