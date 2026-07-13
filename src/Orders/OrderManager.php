<?php

namespace EzEcommerce\Orders;

use EzEcommerce\Fulfillment\Actions\CreateFulfillment;
use EzEcommerce\Fulfillment\Models\Fulfillment;
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
    ) {
    }

    public function recalculatePaymentStatus(Order $order): Order
    {
        return $this->recalculateOrderPaymentStatus->execute($order);
    }

    public function recalculateFulfillmentStatus(Order $order): Order
    {
        return $this->recalculateOrderFulfillmentStatus->execute($order);
    }

    public function fulfill(Order $order, OrderItem $item, int $quantity, ?string $idempotencyKey = null): Fulfillment
    {
        $fulfillment = $this->createFulfillment->execute($order, $item, $quantity, $idempotencyKey);
        $this->recalculateOrderFulfillmentStatus->execute($order);

        return $fulfillment;
    }
}
