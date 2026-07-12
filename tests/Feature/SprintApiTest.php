<?php

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Core\Enums\CartStatus;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Facades\EzEcommerce;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Tests\Support\ResolvesCartApiIds;
use EzEcommerce\Tests\Support\SetsUpCatalog;
use EzEcommerce\Tests\Support\UsesCommerceApi;

uses(SetsUpCatalog::class, UsesCommerceApi::class, ResolvesCartApiIds::class);

it('creates customer and address via api', function () {
    $customer = $this->withHeaders($this->commerceApiHeaders())
        ->postJson('/api/ez-commerce/v1/customers', [
            'email' => 'buyer@example.com',
            'first_name' => 'Buyer',
            'last_name' => 'Test',
        ])
        ->assertCreated()
        ->json();

    expect($customer['id'])->not->toBeNull();

    $address = $this->withHeaders($this->commerceApiHeaders())
        ->postJson("/api/ez-commerce/v1/customers/{$customer['id']}/addresses", [
            'type' => 'shipping',
            'line1' => '1 Test St',
            'city' => 'Dubai',
            'country' => 'AE',
        ])
        ->assertCreated()
        ->json();

    expect($address['line1'])->toBe('1 Test St');
});

it('merges guest cart into customer cart via api', function () {
    ['variant' => $variant] = $this->createProductWithVariant();

    $guest = $this->postJson('/api/ez-commerce/v1/cart/guest', ['currency' => 'AED']);
    $guestToken = $guest->json('guest_token');
    $guestCartId = $this->cartPublicIdFromResponse($guest);

    $this->withHeader('X-Guest-Cart-Token', $guestToken)
        ->postJson("/api/ez-commerce/v1/cart/{$guestCartId}/items", [
            'variant_id' => $variant->public_id,
            'quantity' => 2,
        ])->assertCreated();

    $customer = Customer::query()->create(['email' => 'merge@example.com']);
    $customerCart = Cart::query()->create([
        'customer_id' => $customer->id,
        'status' => CartStatus::Active,
        'currency' => 'AED',
        'version' => 0,
    ]);

    $merged = $this->withHeaders($this->commerceApiHeaders())
        ->postJson('/api/ez-commerce/v1/cart/merge', [
            'guest_cart_id' => $guestCartId,
            'customer_cart_id' => $customerCart->public_id,
            'guest_token' => $guestToken,
        ])
        ->assertOk()
        ->json();

    expect($merged['id'])->toBe($customerCart->public_id)
        ->and($merged['items'])->toHaveCount(1)
        ->and($merged['items'][0]['quantity'])->toBe(2);

    expect(Cart::query()->where('public_id', $guestCartId)->exists())->toBeFalse();
});

it('retries payment session via api', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 1000, stock: 5);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');

    $result = placeCheckoutOrder($cart, 'retry-'.uniqid());

    PaymentAttempt::query()
        ->where('payment_id', $result->payment->id)
        ->where('operation', 'create_session')
        ->update(['status' => 'failed_retryable']);

    $response = $this->withHeaders(array_merge($this->commerceApiHeaders(), [
        'Idempotency-Key' => 'retry-'.uniqid(),
    ]))
        ->postJson("/api/ez-commerce/v1/orders/{$result->order->public_id}/retry-payment")
        ->assertOk()
        ->json();

    expect($response['session']['status'])->toBe('pending');
});

it('creates and receives return via api', function () {
    ['variant' => $variant] = $this->createProductWithVariant(priceMinor: 5000, stock: 10);

    ['cart' => $cart] = EzEcommerce::cart()->createGuest('AED');
    EzEcommerce::cart()->addItem($cart, $variant, 1);
    $cart = EzEcommerce::cart()->calculateTotals($cart, 'flat');

    $result = placeCheckoutOrder($cart, 'return-api-'.uniqid());

    $orderItemId = $result->order->items->first()->id;

    $created = $this->withHeaders($this->commerceApiHeaders())
        ->postJson("/api/ez-commerce/v1/orders/{$result->order->public_id}/returns", [
            'reason' => 'Wrong size',
            'lines' => [['order_item_id' => $orderItemId, 'quantity' => 1, 'restock' => true]],
        ])
        ->assertCreated()
        ->json();

    $received = $this->withHeaders($this->commerceApiHeaders())
        ->postJson("/api/ez-commerce/v1/returns/{$created['id']}/receive")
        ->assertOk()
        ->json();

    expect($received['status'])->toBe('received');
});
