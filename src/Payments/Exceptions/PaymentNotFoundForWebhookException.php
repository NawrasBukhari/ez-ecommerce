<?php

namespace EzEcommerce\Payments\Exceptions;

use RuntimeException;

final class PaymentNotFoundForWebhookException extends RuntimeException
{
    public static function forEvent(string $gateway, string $externalEventId): self
    {
        return new self("No payment found for gateway [{$gateway}] event [{$externalEventId}].");
    }
}
