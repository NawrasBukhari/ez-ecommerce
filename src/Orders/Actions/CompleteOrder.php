<?php

namespace EzEcommerce\Orders\Actions;

use EzEcommerce\Core\Enums\FulfillmentStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\TransitionDimension;
use EzEcommerce\Orders\Models\Order;
use Illuminate\Support\Facades\DB;
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

        $this->assertCompletable($order);

        return DB::transaction(function () use ($order, $reason): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->status === OrderStatus::Completed) {
                return $locked;
            }

            $this->assertCompletable($locked);

            $from = $locked->status->value;

            $locked->update(['status' => OrderStatus::Completed]);

            $this->recordOrderTransition->execute(
                $locked,
                TransitionDimension::Commercial,
                $from,
                OrderStatus::Completed->value,
                $reason ?? 'Order completed',
            );

            return $locked->fresh();
        });
    }

    private function assertCompletable(Order $order): void
    {
        if ($order->status === OrderStatus::Cancelled) {
            throw new RuntimeException('Cancelled orders cannot be completed.');
        }

        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing], true)) {
            throw new RuntimeException('Only confirmed or processing orders can be completed.');
        }

        if ($order->fulfillment_status === FulfillmentStatus::PartiallyFulfilled) {
            throw new RuntimeException('Partially fulfilled orders cannot be completed.');
        }

        // ponytail: Default requires fulfillment because the package treats all order items as
        // fulfillable (no digital/shippable flag on orders). Hosts with digital/pickup flows set
        // ez-ecommerce.orders.require_fulfillment_for_completion=false.
        if ($order->fulfillment_status !== FulfillmentStatus::Fulfilled
            && config('ez-ecommerce.orders.require_fulfillment_for_completion', true)) {
            throw new RuntimeException('Order must be fulfilled before completion.');
        }
    }
}
