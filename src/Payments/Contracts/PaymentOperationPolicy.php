<?php

namespace EzEcommerce\Payments\Contracts;

use EzEcommerce\Payments\Models\Payment;

interface PaymentOperationPolicy
{
    public function canCreateSession(Payment $payment): bool;

    public function canCapture(Payment $payment): bool;

    public function canVoid(Payment $payment): bool;

    public function canRefund(Payment $payment): bool;
}
