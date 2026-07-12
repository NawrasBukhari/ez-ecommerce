<?php

namespace EzEcommerce\Payments\Data;

use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Money\Money;

final readonly class PaymentResult
{
    /** @param  array<string, mixed>  $metadata */
    public function __construct(
        public bool $success,
        public PaymentStatus $status,
        public Money $amount,
        public ?string $externalId = null,
        public ?PaymentFailure $failure = null,
        public array $metadata = [],
    ) {}
}
