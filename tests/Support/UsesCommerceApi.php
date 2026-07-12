<?php

namespace EzEcommerce\Tests\Support;

trait UsesCommerceApi
{
    protected function commerceApiHeaders(array $extra = []): array
    {
        return array_merge([
            'Authorization' => 'Bearer test-api-token',
            'Accept' => 'application/json',
        ], $extra);
    }
}
