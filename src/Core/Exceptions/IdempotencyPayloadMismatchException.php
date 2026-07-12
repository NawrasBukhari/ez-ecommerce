<?php

namespace EzEcommerce\Core\Exceptions;

use RuntimeException;

final class IdempotencyPayloadMismatchException extends RuntimeException
{
    public static function for(string $scope, string $key): self
    {
        return new self("Idempotency key reused with different payload: {$scope}:{$key}");
    }
}
