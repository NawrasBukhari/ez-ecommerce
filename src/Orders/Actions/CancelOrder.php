<?php

namespace EzEcommerce\Orders\Actions;

use EzEcommerce\Core\Enums\FulfillmentStatus;
use EzEcommerce\Core\Enums\OrderPaymentStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\ReservationStatus;
use EzEcommerce\Core\Enums\TransitionDimension;
use EzEcommerce\Inventory\Actions\ReleaseInventoryReservation;
use EzEcommerce\Orders\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CancelOrder
{
    public function __construct(
        private readonly RecordOrderTransition $recordOrderTransition,
        private readonly ReleaseInventoryReservation $releaseInventoryReservation,
    ) {
    }

    public function execute(Order $order, ?string $reason = null): Order
    {
        if ($order->status === OrderStatus::Cancelled) {
            return $order;
        }

        if (in_array($order->status, [OrderStatus::Completed], true)) {
            throw new RuntimeException('Completed orders cannot be cancelled.');
        }

        if ($order->fulfillment_status === FulfillmentStatus::Fulfilled) {
            throw new RuntimeException('Fulfilled orders cannot be cancelled.');
        }

        if (in_array($order->payment_status, [OrderPaymentStatus::Paid, OrderPaymentStatus::PartiallyPaid], true)) {
            throw new RuntimeException('Paid orders must be refunded before cancellation.');
        }

        return DB::transaction(function () use ($order, $reason): Order {
            $from = $order->status->value;

            $order->reservations()
                ->where('status', ReservationStatus::Active)
                ->get()
                ->each(fn ($reservation) => $this->releaseInventoryReservation->execute($reservation));

            $order->update([
                'status' => OrderStatus::Cancelled,
                'fulfillment_status' => FulfillmentStatus::Cancelled,
            ]);

            $this->recordOrderTransition->execute(
                $order,
                TransitionDimension::Commercial,
                $from,
                OrderStatus::Cancelled->value,
                $reason ?? 'Order cancelled',
            );

            return $order->fresh();
        });
    }
}
