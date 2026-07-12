<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Subscriptions\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subscription */
final class SubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'payment_method' => $this->payment_method,
            'current_period_start' => $this->current_period_start?->toIso8601String(),
            'current_period_end' => $this->current_period_end?->toIso8601String(),
            'plan' => $this->whenLoaded('plan', fn () => [
                'id' => $this->plan->public_id,
                'name' => $this->plan->name,
                'amount_minor' => $this->plan->amount_minor,
                'currency' => $this->plan->currency,
            ]),
            'metadata' => $this->metadata?->toArray() ?? [],
        ];
    }
}
