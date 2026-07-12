<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Customers\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
final class CustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'company_id' => $this->company?->public_id,
            'metadata' => $this->metadata?->toArray() ?? [],
            'addresses' => AddressResource::collection($this->whenLoaded('addresses')),
        ];
    }
}
