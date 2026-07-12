<?php

namespace EzEcommerce\Payments\Data;

use EzEcommerce\Core\Money\Money;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;

final readonly class CapturePaymentData
{
    /** @param  array<string, mixed>  $metadata */
    public function __construct(
        public Payment $payment,
        public PaymentAttempt $attempt,
        public Money $amount,
        public array $metadata = [],
        public ?string $providerReference = null,
    ) {
    }
}
