<?php

namespace EzEcommerce\Api\Http\Resources;

use EzEcommerce\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubscriptionPlan */
final class SubscriptionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'interval' => $this->interval->value,
            'interval_count' => $this->interval_count,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
        ];
    }
}
