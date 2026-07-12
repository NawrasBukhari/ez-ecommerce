<?php

namespace EzEcommerce\Payments\Data;

use EzEcommerce\Core\Money\Money;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;

final readonly class CreatePaymentSessionData
{
    /** @param  array<string, mixed>  $metadata */
    public function __construct(
        public Payment $payment,
        public PaymentAttempt $attempt,
        public Order $order,
        public Money $amount,
        public array $metadata = [],
    ) {
    }
}
