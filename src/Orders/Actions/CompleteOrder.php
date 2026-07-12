<?php

namespace EzEcommerce\Orders\Actions;

use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\TransitionDimension;
use EzEcommerce\Orders\Models\Order;
use RuntimeException;

final class CompleteOrder
{
    public function __construct(
        private readonly RecordOrderTransition $recordOrderTransition,
    ) {
    }

    public function execute(Order $order, ?string $reason = null): Order
    {
        if ($order->status === OrderStatus::Completed) {
            return $order;
        }

        if ($order->status === OrderStatus::Cancelled) {
            throw new RuntimeException('Cancelled orders cannot be completed.');
        }

        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing], true)) {
            throw new RuntimeException('Only confirmed or processing orders can be completed.');
        }

        $from = $order->status->value;

        $order->update(['status' => OrderStatus::Completed]);

        $this->recordOrderTransition->execute(
            $order,
            TransitionDimension::Commercial,
            $from,
            OrderStatus::Completed->value,
            $reason ?? 'Order completed',
        );

        return $order->fresh();
    }
}
