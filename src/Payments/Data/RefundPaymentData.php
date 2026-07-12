<?php

namespace EzEcommerce\Payments\Data;

use EzEcommerce\Core\Money\Money;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Refunds\Models\Refund;

final readonly class RefundPaymentData
{
    /** @param  array<string, mixed>  $metadata */
    public function __construct(
        public Payment $payment,
        public Refund $refund,
        public PaymentAttempt $attempt,
        public Money $amount,
        public array $metadata = [],
        public ?string $providerReference = null,
    ) {
    }
}
