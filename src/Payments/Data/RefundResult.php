<?php

namespace EzEcommerce\Payments\Data;

use EzEcommerce\Core\Enums\RefundStatus;
use EzEcommerce\Core\Money\Money;

final readonly class RefundResult
{
    /** @param  array<string, mixed>  $metadata */
    public function __construct(
        public bool $success,
        public RefundStatus $status,
        public Money $amount,
        public ?string $externalId = null,
        public ?PaymentFailure $failure = null,
        public array $metadata = [],
    ) {
    }
}
