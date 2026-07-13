<?php

namespace EzEcommerce\Payments\Exceptions;

use RuntimeException;

/**
 * Thrown when a payment operation cannot proceed because another incompatible
 * operation is in flight on the same payment (e.g. a capture while a void is
 * pending). Mapped to HTTP 409 by the API exception handler.
 */
final class ConflictingPaymentOperationException extends RuntimeException
{
    public static function for(string $operation, string $conflictingOperation): self
    {
        return new self(
            "Cannot start {$operation}: a {$conflictingOperation} is in progress for this payment."
        );
    }
}
