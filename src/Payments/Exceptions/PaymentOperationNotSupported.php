<?php

namespace EzEcommerce\Payments\Exceptions;

use RuntimeException;

final class PaymentOperationNotSupported extends RuntimeException
{
    public static function for(string $gateway, string $operation): self
    {
        return new self("Payment gateway [{$gateway}] does not support [{$operation}].");
    }
}
