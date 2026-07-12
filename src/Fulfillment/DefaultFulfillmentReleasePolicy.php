<?php

namespace EzEcommerce\Fulfillment;

use EzEcommerce\Core\Enums\OrderPaymentStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Fulfillment\Contracts\FulfillmentReleasePolicy;
use EzEcommerce\Orders\Models\Order;

final class DefaultFulfillmentReleasePolicy implements FulfillmentReleasePolicy
{
    public function canFulfill(Order $order): bool
    {
        $order->loadMissing('payments');

        if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Draft], true)) {
            return false;
        }

        if ($order->payment_method === 'null' && $order->grand_total_minor === 0) {
            return true;
        }

        if ($order->payment_method === 'manual') {
            return in_array($order->payment_status, [
                OrderPaymentStatus::Paid,
                OrderPaymentStatus::PartiallyPaid,
                OrderPaymentStatus::Authorized,
            ], true);
        }

        return $order->payments->contains(fn ($p) => in_array($p->status, [
            PaymentStatus::Captured,
            PaymentStatus::PartiallyCaptured,
            PaymentStatus::Authorized,
        ], true));
    }
}
