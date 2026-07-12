<?php

namespace EzEcommerce\Webhooks\Outbound\Actions;

final class SignWebhookPayload
{
    public function execute(string $payload, ?string $secret = null): string
    {
        $secret ??= (string) config('ez-ecommerce.outbound_webhooks.secret', '');

        return hash_hmac('sha256', $payload, $secret);
    }
}
