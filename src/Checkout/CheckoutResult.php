<?php

namespace EzEcommerce\Checkout;

use EzEcommerce\Core\Enums\CheckoutStatus;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Data\PaymentFailure;
use EzEcommerce\Payments\Data\PaymentSessionResult;
use EzEcommerce\Payments\Models\Payment;

final readonly class CheckoutResult
{
    public function __construct(
        public Order $order,
        public Payment $payment,
        public ?PaymentSessionResult $paymentSession,
        public CheckoutStatus $status,
        public ?PaymentFailure $paymentFailure = null,
    ) {}

    public function requiresCustomerAction(): bool
    {
        return $this->status === CheckoutStatus::RequiresAction;
    }

    public function isCompleted(): bool
    {
        return $this->status === CheckoutStatus::Completed;
    }
}
