<?php

namespace EzEcommerce\Orders\Actions;

use EzEcommerce\Core\Enums\OrderPaymentStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\TransitionDimension;
use EzEcommerce\Orders\Models\Order;

final class ConfirmOrderOnPaymentAccepted
{
    public function __construct(
        private readonly RecordOrderTransition $recordOrderTransition,
    ) {}

    public function execute(Order $order): bool
    {
        $order->refresh();

        if ($order->status !== OrderStatus::PendingPayment) {
            return false;
        }

        if (! in_array($order->payment_status, [OrderPaymentStatus::Paid, OrderPaymentStatus::PartiallyPaid], true)) {
            return false;
        }

        $from = $order->status->value;
        $order->update(['status' => OrderStatus::Confirmed]);
        $this->recordOrderTransition->execute(
            $order,
            TransitionDimension::Commercial,
            $from,
            OrderStatus::Confirmed->value,
            'Order confirmed after payment accepted',
        );

        return true;
    }
}
