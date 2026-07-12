<?php

namespace EzEcommerce\Orders\Actions;

use EzEcommerce\Core\Enums\FulfillmentStatus;
use EzEcommerce\Core\Enums\TransitionDimension;
use EzEcommerce\Orders\Models\Order;

final class RecalculateOrderFulfillmentStatus
{
    public function __construct(
        private readonly RecordOrderTransition $recordOrderTransition,
    ) {
    }

    public function execute(Order $order): Order
    {
        $order->load(['items', 'fulfillments']);
        $from = $order->fulfillment_status->value;

        $totalOrdered = $order->items->sum('quantity');
        $totalFulfilled = $order->fulfillments->sum('quantity');

        $status = match (true) {
            $totalFulfilled === 0 => FulfillmentStatus::Unfulfilled,
            $totalFulfilled >= $totalOrdered => FulfillmentStatus::Fulfilled,
            default => FulfillmentStatus::PartiallyFulfilled,
        };

        if ($status->value !== $from) {
            $order->update(['fulfillment_status' => $status]);
            $this->recordOrderTransition->execute(
                $order,
                TransitionDimension::Fulfillment,
                $from,
                $status->value,
                'Fulfillment status recalculated',
            );
        }

        return $order->fresh();
    }
}
