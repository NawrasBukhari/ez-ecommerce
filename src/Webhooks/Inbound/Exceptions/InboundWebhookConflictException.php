<?php

namespace EzEcommerce\Webhooks\Inbound\Exceptions;

use RuntimeException;

final class InboundWebhookConflictException extends RuntimeException
{
    public function __construct(public readonly ?string $durableStatus)
    {
        $message = $durableStatus === null
            ? 'Webhook event is being processed by another request.'
            : "Webhook event is in state [{$durableStatus}].";

        parent::__construct($message, 409);
    }
}
