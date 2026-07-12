<?php

namespace EzEcommerce\Payments\Data;

final readonly class PaymentFailure
{
    public function __construct(
        public string $code,
        public string $message,
        public bool $retryable = false,
    ) {}
}
