<?php

use EzEcommerce\Payments\Actions\ReconcilePayment;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Drivers\PayPalPaymentGateway;
use EzEcommerce\Payments\Models\PaymentTransaction;
use EzEcommerce\Tests\Support\SetsUpCatalog;
use EzEcommerce\Tests\Support\UsesCommerceApi;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(SetsUpCatalog::class, UsesCommerceApi::class);

it('rejects protected routes when api token is not configured', function () {
    config()->set('ez-ecommerce.api.token', null);
    config()->set('ez-ecommerce.api.scoped_tokens', []);
    config()->set('ez-ecommerce.api.allow_unauthenticated', false);

    $this->getJson('/api/ez-commerce/v1/stores')
        ->assertStatus(503);
});

it('rejects write routes when token lacks scope', function () {
    config()->set('ez-ecommerce.api.scoped_tokens', [
        'read-only-token' => ['catalog.read'],
    ]);

    $this->withHeaders(['Authorization' => 'Bearer read-only-token'])
        ->postJson('/api/ez-commerce/v1/products', [
            'name' => 'Blocked',
            'variant' => ['sku' => 'BLK-1', 'name' => 'Default', 'price_minor' => 100],
        ])
        ->assertForbidden();
});

it('rejects checkout without guest cart token', function () {
    ['variant' => $variant] = $this->createProductWithVariant();
    $guest = $this->postJson('/api/ez-commerce/v1/cart/guest', ['currency' => 'AED']);
    $cartId = $guest->json('id') ?? $guest->json('data.id');
    $token = $guest->json('guest_token');

    $this->withHeader('X-Guest-Cart-Token', $token)
        ->postJson("/api/ez-commerce/v1/cart/{$cartId}/items", [
            'variant_id' => $variant->public_id,
            'quantity' => 1,
        ])->assertCreated();

    $this->withHeader('X-Guest-Cart-Token', $token)
        ->postJson("/api/ez-commerce/v1/cart/{$cartId}/calculate", ['shipping_method' => 'flat'])
        ->assertOk();

    $this->withHeaders([
        'X-Guest-Cart-Token' => '',
        'Idempotency-Key' => 'no-token-'.uniqid(),
    ])->postJson('/api/ez-commerce/v1/checkout', [
        'cart_id' => $cartId,
        'shipping_method' => 'flat',
        'payment_method' => 'manual',
    ])->assertForbidden();
});

it('rejects paypal webhook without shared secret when webhook id unset', function () {
    config()->set('ez-ecommerce.drivers.payment.paypal.webhook_id', null);
    config()->set('ez-ecommerce.inbound_webhooks.shared_secret', 'webhook-secret');
    config()->set('ez-ecommerce.inbound_webhooks.allow_unsigned', false);

    $this->postJson('/api/ez-commerce/v1/webhooks/paypal', [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'id' => 'evt_paypal_1',
        'resource' => ['id' => 'pay_123'],
    ])->assertUnauthorized();
});

it('accepts paypal webhook with shared secret header', function () {
    config()->set('ez-ecommerce.drivers.payment.paypal.webhook_id', null);
    config()->set('ez-ecommerce.inbound_webhooks.shared_secret', 'webhook-secret');
    config()->set('ez-ecommerce.inbound_webhooks.allow_unsigned', false);
    config()->set('ez-ecommerce.drivers.payment.paypal.client_id', 'test-client');
    config()->set('ez-ecommerce.drivers.payment.paypal.client_secret', 'test-secret');

    $this->withHeader('X-Commerce-Webhook-Secret', 'webhook-secret')
        ->postJson('/api/ez-commerce/v1/webhooks/paypal', [
            'event_type' => 'unknown.event',
            'id' => 'evt_paypal_2',
        ])
        ->assertOk();
});

it('verifies paypal webhook with native signature when webhook id is set', function () {
    config()->set('ez-ecommerce.drivers.payment.paypal.webhook_id', 'WH-TEST');
    config()->set('ez-ecommerce.drivers.payment.paypal.client_id', 'test-client');
    config()->set('ez-ecommerce.drivers.payment.paypal.client_secret', 'test-secret');

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'paypal-token']),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
    ]);

    $payload = json_encode([
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'id' => 'evt_paypal_native',
        'resource' => ['id' => 'CAP-1'],
    ]);

    $this->withHeaders([
        'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
        'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/cert',
        'PAYPAL-TRANSMISSION-ID' => 'tx-1',
        'PAYPAL-TRANSMISSION-SIG' => 'sig-1',
        'PAYPAL-TRANSMISSION-TIME' => '2026-01-01T00:00:00Z',
    ])->postJson('/api/ez-commerce/v1/webhooks/paypal', json_decode($payload, true))
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'verify-webhook-signature'));
});

it('rejects paypal webhook when native verification fails', function () {
    config()->set('ez-ecommerce.drivers.payment.paypal.webhook_id', 'WH-TEST');
    config()->set('ez-ecommerce.drivers.payment.paypal.client_id', 'test-client');
    config()->set('ez-ecommerce.drivers.payment.paypal.client_secret', 'test-secret');

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'paypal-token']),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
    ]);

    $this->withHeaders([
        'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
        'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/cert',
        'PAYPAL-TRANSMISSION-ID' => 'tx-2',
        'PAYPAL-TRANSMISSION-SIG' => 'sig-2',
        'PAYPAL-TRANSMISSION-TIME' => '2026-01-01T00:00:00Z',
    ])->postJson('/api/ez-commerce/v1/webhooks/paypal', [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'id' => 'evt_paypal_fail',
    ])->assertStatus(400);
});

it('does not capture payment on ambiguous unpaid event names', function () {
    $event = app(ReconcilePayment::class)->execute(
        new WebhookRequestData(
            gateway: 'fake',
            payload: '{"type":"order.unpaid","id":"evt_unpaid_1"}',
        ),
    );

    expect($event->eventType)->toBe('payment.captured');
    expect(PaymentTransaction::query()->count())->toBe(0);
});

it('rejects stripe webhook when secret is not configured', function () {
    config()->set('ez-ecommerce.drivers.payment.stripe.webhook_secret', null);

    $this->postJson('/api/ez-commerce/v1/webhooks/stripe', [
        'type' => 'payment_intent.succeeded',
        'id' => 'evt_stripe_1',
    ])->assertForbidden();
});

it('paypal gateway verify rejects missing transmission headers', function () {
    config()->set('ez-ecommerce.drivers.payment.paypal.webhook_id', 'WH-TEST');
    config()->set('ez-ecommerce.drivers.payment.paypal.client_id', 'test-client');
    config()->set('ez-ecommerce.drivers.payment.paypal.client_secret', 'test-secret');

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'paypal-token']),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
    ]);

    $gateway = app(PayPalPaymentGateway::class);

    expect(fn () => $gateway->verifyWebhookSignature('{"id":"1"}', request()))
        ->toThrow(HttpException::class);
});
