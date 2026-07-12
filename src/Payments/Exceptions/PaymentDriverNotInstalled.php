<?php

namespace EzEcommerce\Payments\Exceptions;

use RuntimeException;

final class PaymentDriverNotInstalled extends RuntimeException
{
    public static function for(string $driver, string $package): self
    {
        return new self("Payment driver [{$driver}] requires package [{$package}]. Install it via composer suggest.");
    }

    public static function notConfigured(string $driver): self
    {
        return new self("Payment driver [{$driver}] is not configured.");
    }
}
