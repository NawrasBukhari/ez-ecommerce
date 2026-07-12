<?php

namespace EzEcommerce\Payments\Data;

final readonly class PaymentGatewayCapabilities
{
    public function __construct(
        public bool $sessions = false,
        public bool $authorization = false,
        public bool $capture = false,
        public bool $refund = false,
        public bool $webhooks = false,
    ) {}
}
