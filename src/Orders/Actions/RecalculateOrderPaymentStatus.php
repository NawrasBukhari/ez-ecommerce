<?php

namespace EzEcommerce\Orders\Actions;

use EzEcommerce\Core\Enums\OrderPaymentStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\TransitionDimension;
use EzEcommerce\Orders\Models\Order;

final class RecalculateOrderPaymentStatus
{
    public function __construct(
        private readonly RecordOrderTransition $recordOrderTransition,
    ) {}

    public function execute(Order $order): Order
    {
        $order->load('payments');
        $from = $order->payment_status->value;

        $payments = $order->payments;
        if ($payments->isEmpty()) {
            $status = OrderPaymentStatus::Unpaid;
        } elseif ($payments->contains(fn ($p) => $p->status === PaymentStatus::Failed)) {
            $status = OrderPaymentStatus::Failed;
        } elseif ($payments->contains(fn ($p) => $p->status === PaymentStatus::RequiresAction)) {
            $status = OrderPaymentStatus::RequiresAction;
        } elseif ($order->refunded_total_minor >= $order->grand_total_minor && $order->refunded_total_minor > 0) {
            $status = OrderPaymentStatus::Refunded;
        } elseif ($order->refunded_total_minor > 0) {
            $status = OrderPaymentStatus::PartiallyRefunded;
        } elseif ($payments->contains(fn ($p) => $p->status === PaymentStatus::Captured)) {
            $status = OrderPaymentStatus::Paid;
        } elseif ($payments->contains(fn ($p) => $p->status === PaymentStatus::PartiallyCaptured)) {
            $status = OrderPaymentStatus::PartiallyPaid;
        } elseif ($payments->contains(fn ($p) => $p->status === PaymentStatus::Authorized)) {
            $status = OrderPaymentStatus::Authorized;
        } elseif ($payments->contains(fn ($p) => in_array($p->status, [PaymentStatus::Pending, PaymentStatus::Created], true))) {
            $status = OrderPaymentStatus::Unpaid;
        } else {
            $status = OrderPaymentStatus::Unpaid;
        }

        if ($status->value !== $from) {
            $order->update(['payment_status' => $status]);
            $this->recordOrderTransition->execute(
                $order,
                TransitionDimension::Payment,
                $from,
                $status->value,
                'Payment status recalculated',
            );
        }

        return $order->fresh();
    }
}
