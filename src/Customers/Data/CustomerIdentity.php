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
    ) {
    }

    public function isGuest(): bool
    {
        return $this->actorType === null && $this->actorId === null;
    }

    /** @return array<string, mixed> */
    public function idempotencyFingerprint(): array
    {
        return array_filter([
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'email' => $this->email,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'phone' => $this->phone,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
