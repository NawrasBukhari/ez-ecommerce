<?php

namespace EzEcommerce\Customers\Data;

final readonly class CustomerIdentity
{
    /** @param  array<string, mixed>  $metadata */
    public function __construct(
        public ?string $actorType = null,
        public ?int $actorId = null,
        public ?string $email = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $phone = null,
        public array $metadata = [],
    ) {}

    public function isGuest(): bool
    {
        return $this->actorType === null && $this->actorId === null;
    }
}
