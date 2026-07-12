<?php

namespace EzEcommerce\Tests\Support;

use Illuminate\Testing\TestResponse;

trait ResolvesCartApiIds
{
    public function cartPublicIdFromResponse(TestResponse $response): string
    {
        return (string) ($response->json('id') ?? $response->json('data.id'));
    }
}
