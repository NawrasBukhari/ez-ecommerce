<?php

namespace EzEcommerce\Orders\Actions;

use EzEcommerce\Core\Enums\TransitionDimension;
use EzEcommerce\Core\Support\MorphMap;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderTransition;

final class RecordOrderTransition
{
    public function execute(
        Order $order,
        TransitionDimension $dimension,
        string $fromState,
        string $toState,
        ?string $reason = null,
        ?object $actor = null,
        array $metadata = [],
    ): OrderTransition {
        return OrderTransition::query()->create([
            'order_id' => $order->id,
            'dimension' => $dimension,
            'from_state' => $fromState,
            'to_state' => $toState,
            'actor_type' => $actor !== null ? MorphMap::aliasFor($actor) : null,
            'actor_id' => $actor?->getKey(),
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }
}
