<?php

namespace EzEcommerce\Core\Exceptions;

use RuntimeException;

final class IdempotencyConflictException extends RuntimeException
{
    public static function for(string $scope, string $key): self
    {
        return new self("Idempotency key in progress: {$scope}:{$key}");
    }
}
