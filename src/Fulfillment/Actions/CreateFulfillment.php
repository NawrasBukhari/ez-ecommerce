<?php

namespace EzEcommerce\Fulfillment\Actions;

use EzEcommerce\Fulfillment\Contracts\FulfillmentReleasePolicy;
use EzEcommerce\Fulfillment\Models\Fulfillment;
use EzEcommerce\Orders\Actions\RecalculateOrderFulfillmentStatus;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderItem;
use Illuminate\Support\Facades\DB;
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

        if ($quantity <= 0) {
            throw new RuntimeException('Invalid fulfillment quantity.');
        }

        return DB::transaction(function () use ($order, $item, $quantity) {
            $lockedItem = OrderItem::query()
                ->where('id', $item->id)
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $alreadyFulfilled = (int) Fulfillment::query()
                ->where('order_item_id', $lockedItem->id)
                ->sum('quantity');

            $remaining = $lockedItem->quantity - $alreadyFulfilled;
            if ($quantity > $remaining) {
                throw new RuntimeException("Cannot fulfill {$quantity}; only {$remaining} remaining.");
            }

            $fulfillment = Fulfillment::query()->create([
                'order_id' => $order->id,
                'order_item_id' => $lockedItem->id,
                'quantity' => $quantity,
            ]);

            $this->recalculateOrderFulfillmentStatus->execute($order);

            return $fulfillment;
        });
    }
}
