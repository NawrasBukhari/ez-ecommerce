<?php

namespace EzEcommerce\Orders;

use EzEcommerce\Fulfillment\Actions\CreateFulfillment;
use EzEcommerce\Orders\Actions\RecalculateOrderFulfillmentStatus;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderItem;

final class OrderManager
{
    public function __construct(
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
        private readonly RecalculateOrderFulfillmentStatus $recalculateOrderFulfillmentStatus,
        private readonly CreateFulfillment $createFulfillment,
    ) {}

    public function recalculatePaymentStatus(Order $order): Order
    {
        return $this->recalculateOrderPaymentStatus->execute($order);
    }

    public function recalculateFulfillmentStatus(Order $order): Order
    {
        return $this->recalculateOrderFulfillmentStatus->execute($order);
    }

    public function fulfill(Order $order, OrderItem $item, int $quantity): void
    {
        $this->createFulfillment->execute($order, $item, $quantity);
        $this->recalculateOrderFulfillmentStatus->execute($order);
    }
}
