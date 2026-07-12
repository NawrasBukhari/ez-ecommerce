<?php

use EzEcommerce\Tests\Support\SetsUpCatalog;

uses(SetsUpCatalog::class);

it('lists products via api', function () {
    $this->createProductWithVariant();

    $response = $this->getJson('/api/ez-commerce/v1/products');

    $response->assertOk()->assertJsonStructure(['data']);
});

it('creates guest cart via api', function () {
    $response = $this->postJson('/api/ez-commerce/v1/cart/guest', ['currency' => 'AED']);

    $response->assertCreated()
        ->assertJsonStructure(['guest_token']);
    expect($response->json('id') ?? $response->json('data.id'))->not->toBeNull();
});

it('rejects checkout without idempotency key', function () {
    ['variant' => $variant] = $this->createProductWithVariant();
    $guest = $this->postJson('/api/ez-commerce/v1/cart/guest', ['currency' => 'AED']);
    $token = $guest->json('guest_token');
    $cartId = $guest->json('id') ?? $guest->json('data.id');

    $this->withHeader('X-Guest-Cart-Token', $token)
        ->postJson("/api/ez-commerce/v1/cart/{$cartId}/items", [
            'variant_id' => $variant->public_id,
            'quantity' => 1,
        ])->assertCreated();

    $this->withHeader('X-Guest-Cart-Token', $token)
        ->postJson("/api/ez-commerce/v1/cart/{$cartId}/calculate", ['shipping_method' => 'flat'])
        ->assertOk();

    $this->withHeader('X-Guest-Cart-Token', $token)
        ->postJson('/api/ez-commerce/v1/checkout', [
            'cart_id' => $cartId,
            'shipping_method' => 'flat',
            'payment_method' => 'manual',
        ])
        ->assertStatus(422);
});
