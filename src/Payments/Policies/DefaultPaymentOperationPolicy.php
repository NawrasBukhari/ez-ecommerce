<?php

namespace EzEcommerce\Payments\Policies;

use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Payments\Contracts\PaymentOperationPolicy;
use EzEcommerce\Payments\Models\Payment;

final class DefaultPaymentOperationPolicy implements PaymentOperationPolicy
{
    public function canCreateSession(Payment $payment): bool
    {
        if ($payment->order === null) {
            return false;
        }

        if (in_array($payment->order->status, [OrderStatus::Cancelled, OrderStatus::Completed], true)) {
            return false;
        }

        return ! in_array($payment->status, [
            PaymentStatus::Captured,
            PaymentStatus::PartiallyCaptured,
            PaymentStatus::Cancelled,
            PaymentStatus::Refunded,
            PaymentStatus::PartiallyRefunded,
        ], true);
    }

    public function canCapture(Payment $payment): bool
    {
        if ($payment->order === null) {
            return false;
        }

        if (in_array($payment->order->status, [OrderStatus::Cancelled, OrderStatus::Completed], true)) {
            return false;
        }

        return ! in_array($payment->status, [
            PaymentStatus::Cancelled,
            PaymentStatus::Refunded,
            PaymentStatus::PartiallyRefunded,
            PaymentStatus::Failed,
        ], true);
    }

    public function canVoid(Payment $payment): bool
    {
        return in_array($payment->status, [
            PaymentStatus::Authorized,
            PaymentStatus::RequiresAction,
            PaymentStatus::Pending,
        ], true);
    }

    public function canRefund(Payment $payment): bool
    {
        if ($payment->order === null) {
            return false;
        }

        if ($payment->order->status === OrderStatus::Cancelled) {
            return false;
        }

        return in_array($payment->status, [
            PaymentStatus::Captured,
            PaymentStatus::PartiallyCaptured,
            PaymentStatus::PartiallyRefunded,
        ], true);
    }
}
