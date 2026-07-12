<?php

namespace EzEcommerce\Fulfillment\Actions;

use EzEcommerce\Fulfillment\Contracts\FulfillmentReleasePolicy;
use EzEcommerce\Fulfillment\Models\Fulfillment;
use EzEcommerce\Orders\Actions\RecalculateOrderFulfillmentStatus;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderItem;
use RuntimeException;

final class CreateFulfillment
{
    public function __construct(
        private readonly FulfillmentReleasePolicy $fulfillmentReleasePolicy,
        private readonly RecalculateOrderFulfillmentStatus $recalculateOrderFulfillmentStatus,
    ) {}

    public function execute(Order $order, OrderItem $item, int $quantity): Fulfillment
    {
        if (! $this->fulfillmentReleasePolicy->canFulfill($order)) {
            throw new RuntimeException('Order is not eligible for fulfillment.');
        }

        if ($quantity <= 0 || $quantity > $item->quantity) {
            throw new RuntimeException('Invalid fulfillment quantity.');
        }

        $fulfillment = Fulfillment::query()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'quantity' => $quantity,
        ]);

        $this->recalculateOrderFulfillmentStatus->execute($order);

        return $fulfillment;
    }
}
