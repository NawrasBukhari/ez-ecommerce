<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Customers\Models\CustomerGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerGroup */
final class CustomerGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
        ];
    }
}
