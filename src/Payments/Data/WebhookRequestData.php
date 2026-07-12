<?php

namespace EzEcommerce\Payments\Data;

final readonly class WebhookRequestData
{
    /** @param  array<string, string>  $headers */
    public function __construct(
        public string $gateway,
        public string $payload,
        public array $headers = [],
    ) {}
}
