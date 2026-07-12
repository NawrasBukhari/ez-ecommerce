<?php

namespace EzEcommerce\Core\Exceptions;

use RuntimeException;

final class CurrencyMismatchException extends RuntimeException
{
    public static function between(string $a, string $b): self
    {
        return new self("Currency mismatch: {$a} vs {$b}");
    }
}
