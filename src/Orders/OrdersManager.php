<?php

namespace EzEcommerce\Orders;

use EzEcommerce\Orders\Actions\RecalculateOrderFulfillmentStatus;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Orders\Models\Order;

final class OrdersManager
{
    public function __construct(
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
        private readonly RecalculateOrderFulfillmentStatus $recalculateOrderFulfillmentStatus,
    ) {}

    public function findByPublicId(string $publicId): ?Order
    {
        return Order::query()->where('public_id', $publicId)->first();
    }

    public function recalculatePaymentStatus(Order $order): Order
    {
        return $this->recalculateOrderPaymentStatus->execute($order);
    }

    public function recalculateFulfillmentStatus(Order $order): Order
    {
        return $this->recalculateOrderFulfillmentStatus->execute($order);
    }
}
