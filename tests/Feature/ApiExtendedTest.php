<?php

use EzEcommerce\Core\Enums\SubscriptionInterval;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Discounts\Models\Discount;
use EzEcommerce\Subscriptions\Models\SubscriptionPlan;
use EzEcommerce\Tests\Support\ResolvesCartApiIds;
use EzEcommerce\Tests\Support\SetsUpCatalog;
use EzEcommerce\Tests\Support\UsesCommerceApi;

uses(SetsUpCatalog::class, UsesCommerceApi::class, ResolvesCartApiIds::class);

function checkoutViaApi($test, $variant, string $idempotencyKey = 'api-checkout'): array
{
    $guest = $test->postJson('/api/ez-commerce/v1/cart/guest', ['currency' => 'AED']);
    $token = $guest->json('guest_token');
    $cartId = $test->cartPublicIdFromResponse($guest);

    $test->withHeaders(['X-Guest-Cart-Token' => $token])
        ->postJson("/api/ez-commerce/v1/cart/{$cartId}/items", [
            'variant_id' => $variant->public_id,
            'quantity' => 1,
        ])->assertCreated();

    $calculate = $test->withHeaders(['X-Guest-Cart-Token' => $token])
        ->postJson("/api/ez-commerce/v1/cart/{$cartId}/calculate", ['shipping_method' => 'flat'])
        ->assertOk();

    $checkout = $test->withHeaders([
        'X-Guest-Cart-Token' => $token,
        'Idempotency-Key' => $idempotencyKey,
    ])->postJson('/api/ez-commerce/v1/checkout', [
        'cart_id' => $cartId,
        'shipping_method' => 'flat',
        'payment_method' => 'manual',
        'expected_totals_hash' => $calculate->json('totals_hash'),
    ]);

    return compact('guest', 'token', 'cartId', 'checkout');
}

it('rejects order endpoints without api token when configured', function () {
    ['variant' => $variant] = $this->createProductWithVariant();
    ['checkout' => $checkout] = checkoutViaApi($this, $variant);
    $checkout->assertOk();
    $orderId = $checkout->json('order.id');

    $this->getJson("/api/ez-commerce/v1/orders/{$orderId}")
        ->assertUnauthorized();
});

it('shows order via api with token', function () {
    ['variant' => $variant] = $this->createProductWithVariant();
    ['checkout' => $checkout] = checkoutViaApi($this, $variant, 'show-order-'.uniqid());
    $orderId = $checkout->json('order.id');

    $this->withHeaders($this->commerceApiHeaders())
        ->getJson("/api/ez-commerce/v1/orders/{$orderId}")
        ->assertOk()
        ->assertJsonPath('id', $orderId);
});

it('captures and fulfills order via api', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 5);
    ['checkout' => $checkout] = checkoutViaApi($this, $variant, 'capture-'.uniqid());
    $orderId = $checkout->json('order.id');
    $headers = $this->commerceApiHeaders();

    $this->withHeaders($headers)
        ->postJson("/api/ez-commerce/v1/orders/{$orderId}/capture")
        ->assertOk();

    $itemId = $this->withHeaders($headers)
        ->getJson("/api/ez-commerce/v1/orders/{$orderId}")
        ->json('items.0.id');

    $this->withHeaders($headers)
        ->postJson("/api/ez-commerce/v1/orders/{$orderId}/fulfill", [
            'order_item_id' => $itemId,
            'quantity' => 1,
        ])
        ->assertCreated();
});

it('applies and removes discount via api', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 10000);
    Discount::query()->create([
        'code' => 'API10',
        'type' => 'percent',
        'value' => 10,
        'is_active' => true,
    ]);

    $guest = $this->postJson('/api/ez-commerce/v1/cart/guest', ['currency' => 'AED']);
    $token = $guest->json('guest_token');
    $cartId = $this->cartPublicIdFromResponse($guest);

    $this->withHeader('X-Guest-Cart-Token', $token)
        ->postJson("/api/ez-commerce/v1/cart/{$cartId}/items", [
            'variant_id' => $variant->public_id,
            'quantity' => 1,
        ])->assertCreated();

    $this->withHeader('X-Guest-Cart-Token', $token)
        ->postJson("/api/ez-commerce/v1/cart/{$cartId}/discount", ['code' => 'API10'])
        ->assertOk();

    $this->withHeader('X-Guest-Cart-Token', $token)
        ->deleteJson("/api/ez-commerce/v1/cart/{$cartId}/discount")
        ->assertOk();
});

it('creates store company and vendor via api', function () {
    $headers = $this->commerceApiHeaders();

    $store = $this->withHeaders($headers)
        ->postJson('/api/ez-commerce/v1/stores', ['name' => 'Dubai Store', 'currency' => 'AED'])
        ->assertCreated()
        ->json('id');

    $this->withHeaders($headers)
        ->getJson("/api/ez-commerce/v1/stores/{$store}")
        ->assertOk();

    $company = $this->withHeaders($headers)
        ->postJson('/api/ez-commerce/v1/companies', [
            'name' => 'Acme B2B',
            'payment_terms_days' => 30,
        ])
        ->assertCreated()
        ->json('id');

    $this->withHeaders($headers)
        ->getJson("/api/ez-commerce/v1/companies/{$company}")
        ->assertOk();

    $vendor = $this->withHeaders($headers)
        ->postJson('/api/ez-commerce/v1/vendors', ['name' => 'Seller One'])
        ->assertCreated()
        ->json('id');

    $this->withHeaders($headers)
        ->getJson("/api/ez-commerce/v1/vendors/{$vendor}")
        ->assertOk();
});

it('creates subscription via api', function () {
    $customer = Customer::query()->create(['email' => 'api-sub@example.com']);
    $plan = SubscriptionPlan::query()->create([
        'name' => 'API Plan',
        'interval' => SubscriptionInterval::Month,
        'interval_count' => 1,
        'amount_minor' => 4900,
        'currency' => 'AED',
    ]);

    $response = $this->withHeaders($this->commerceApiHeaders())
        ->postJson('/api/ez-commerce/v1/subscriptions', [
            'customer_id' => $customer->public_id,
            'plan_id' => $plan->public_id,
        ])
        ->assertCreated();

    $id = $response->json('id');

    $this->withHeaders($this->commerceApiHeaders())
        ->getJson("/api/ez-commerce/v1/subscriptions/{$id}")
        ->assertOk();
});
