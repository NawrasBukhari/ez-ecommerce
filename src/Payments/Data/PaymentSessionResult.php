<?php

namespace EzEcommerce\Payments\Data;

use EzEcommerce\Core\Enums\PaymentStatus;

final readonly class PaymentSessionResult
{
    /** @param  array<string, mixed>  $metadata */
    public function __construct(
        public PaymentStatus $status,
        public ?string $externalId = null,
        public ?string $redirectUrl = null,
        public ?string $clientSecret = null,
        public ?PaymentFailure $failure = null,
        public array $metadata = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->failure === null;
    }
}
