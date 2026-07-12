<?php

namespace EzEcommerce\Payments\Drivers;

use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\RefundStatus;
use EzEcommerce\Payments\Contracts\PaymentGateway;
use EzEcommerce\Payments\Data\CapturePaymentData;
use EzEcommerce\Payments\Data\CreatePaymentSessionData;
use EzEcommerce\Payments\Data\GatewayWebhookEvent;
use EzEcommerce\Payments\Data\PaymentGatewayCapabilities;
use EzEcommerce\Payments\Data\PaymentResult;
use EzEcommerce\Payments\Data\PaymentSessionResult;
use EzEcommerce\Payments\Data\RefundPaymentData;
use EzEcommerce\Payments\Data\RefundResult;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Exceptions\PaymentDriverNotInstalled;
use EzEcommerce\Payments\Exceptions\PaymentOperationNotSupported;
use Stripe\StripeClient;

final class StripePaymentGateway implements PaymentGateway
{
    private object $client;

    public function __construct()
    {
        if (! class_exists(StripeClient::class)) {
            throw PaymentDriverNotInstalled::for('stripe', 'stripe/stripe-php');
        }

        $secret = config('ez-ecommerce.drivers.payment.stripe.secret');
        if ($secret === null || $secret === '') {
            throw PaymentDriverNotInstalled::notConfigured('stripe');
        }

        $this->client = new StripeClient($secret);
    }

    public function capabilities(): PaymentGatewayCapabilities
    {
        return new PaymentGatewayCapabilities(
            sessions: true,
            authorization: true,
            capture: true,
            refund: true,
            webhooks: true,
        );
    }

    public function createSession(CreatePaymentSessionData $data): PaymentSessionResult
    {
        $intent = $this->client->paymentIntents->create([
            'amount' => $data->amount->minorAmount,
            'currency' => strtolower($data->amount->currency),
            'metadata' => array_merge($data->metadata, [
                'payment_public_id' => $data->payment->public_id,
                'attempt_public_id' => $data->attempt->public_id,
                'order_public_id' => $data->order->public_id,
            ]),
            'automatic_payment_methods' => ['enabled' => true],
        ]);

        return new PaymentSessionResult(
            status: PaymentStatus::RequiresAction,
            externalId: $intent->id,
            clientSecret: $intent->client_secret,
        );
    }

    public function capture(CapturePaymentData $data): PaymentResult
    {
        $intentId = $data->attempt->external_id ?? ($data->payment->metadata['stripe_payment_intent_id'] ?? null);
        if ($intentId === null) {
            throw PaymentOperationNotSupported::for('stripe', 'capture without payment_intent');
        }

        $intent = $this->client->paymentIntents->capture(
            $intentId,
            ['amount_to_capture' => $data->amount->minorAmount],
            $data->attempt->idempotency_key !== '' && $data->attempt->idempotency_key !== null
                ? ['idempotency_key' => $data->attempt->idempotency_key]
                : [],
        );

        return new PaymentResult(
            success: $intent->status === 'succeeded',
            status: PaymentStatus::Captured,
            amount: $data->amount,
            externalId: $intent->id,
        );
    }

    public function refund(RefundPaymentData $data): RefundResult
    {
        $intentId = $data->attempt->external_id ?? ($data->payment->metadata['stripe_payment_intent_id'] ?? null);
        if ($intentId === null) {
            throw PaymentOperationNotSupported::for('stripe', 'refund without payment_intent');
        }

        $refund = $this->client->refunds->create([
            'payment_intent' => $intentId,
            'amount' => $data->amount->minorAmount,
        ]);

        return new RefundResult(
            success: $refund->status === 'succeeded',
            status: RefundStatus::Succeeded,
            amount: $data->amount,
            externalId: $refund->id,
        );
    }

    public function parseWebhook(WebhookRequestData $data): GatewayWebhookEvent
    {
        $payload = json_decode($data->payload, true, 512, JSON_THROW_ON_ERROR);
        $type = $payload['type'] ?? 'unknown';
        $object = $payload['data']['object'] ?? [];

        return new GatewayWebhookEvent(
            eventType: $type,
            externalId: $payload['id'] ?? hash('sha256', $data->payload),
            paymentExternalId: $object['id'] ?? null,
            amountMinor: isset($object['amount']) ? (int) $object['amount'] : null,
            currency: isset($object['currency']) ? strtoupper((string) $object['currency']) : null,
        );
    }
}
