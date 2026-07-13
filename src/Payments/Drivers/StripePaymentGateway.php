<?php

namespace EzEcommerce\Payments\Drivers;

use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
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
use EzEcommerce\Payments\Data\VoidPaymentData;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Exceptions\PaymentDriverNotInstalled;
use EzEcommerce\Payments\Exceptions\PaymentOperationNotSupported;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
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
            void: true,
            refund: true,
            webhooks: true,
        );
    }

    public function createSession(CreatePaymentSessionData $data): PaymentSessionResult
    {
        $intent = $this->client->paymentIntents->create([
            'amount' => $data->amount->minorAmount,
            'currency' => strtolower($data->amount->currency),
            'capture_method' => 'manual',
            'metadata' => array_merge($data->metadata, [
                'payment_public_id' => $data->payment->public_id,
                'attempt_public_id' => $data->attempt->public_id,
                'order_public_id' => $data->order->public_id,
            ]),
            'automatic_payment_methods' => ['enabled' => true],
        ], $this->idempotencyOptions($data->attempt->idempotency_key, "session:{$data->attempt->id}"));

        return new PaymentSessionResult(
            status: PaymentStatus::RequiresAction,
            externalId: $intent->id,
            clientSecret: $intent->client_secret,
        );
    }

    public function capture(CapturePaymentData $data): PaymentResult
    {
        $intentId = $data->providerReference ?? $data->attempt->external_id ?? ($data->payment->metadata['stripe_payment_intent_id'] ?? null);
        if ($intentId === null) {
            throw PaymentOperationNotSupported::for('stripe', 'capture without payment_intent');
        }

        $intent = $this->client->paymentIntents->capture(
            $intentId,
            $this->captureParams($data),
            $this->idempotencyOptions($data->attempt->idempotency_key, "capture:{$data->attempt->id}"),
        );

        $chargeId = is_string($intent->latest_charge)
            ? $intent->latest_charge
            : (is_object($intent->latest_charge) ? ($intent->latest_charge->id ?? null) : null);

        return new PaymentResult(
            success: $intent->status === 'succeeded',
            status: PaymentStatus::Captured,
            amount: $data->amount,
            externalId: is_string($chargeId) ? $chargeId : $intent->id,
            metadata: ['stripe_payment_intent_id' => $intent->id],
        );
    }

    public function void(VoidPaymentData $data): PaymentResult
    {
        $intentId = $data->providerReference
            ?? $data->attempt->external_id
            ?? $this->sessionPaymentIntentId($data->payment)
            ?? ($data->payment->metadata['stripe_payment_intent_id'] ?? null);

        if ($intentId === null) {
            throw PaymentOperationNotSupported::for('stripe', 'void without payment_intent');
        }

        $intent = $this->client->paymentIntents->retrieve($intentId);

        if ($intent->status !== 'requires_capture') {
            throw PaymentOperationNotSupported::for('stripe', 'void on non-capturable intent');
        }

        $cancelled = $this->client->paymentIntents->cancel(
            $intentId,
            [],
            $this->idempotencyOptions($data->attempt->idempotency_key, "void:{$data->attempt->id}"),
        );

        return new PaymentResult(
            success: $cancelled->status === 'canceled',
            status: PaymentStatus::Cancelled,
            amount: $data->amount,
            externalId: $cancelled->id,
            metadata: ['stripe_payment_intent_id' => $cancelled->id],
        );
    }

    public function refund(RefundPaymentData $data): RefundResult
    {
        $intentId = $data->providerReference
            ?? ($data->payment->metadata['stripe_payment_intent_id'] ?? null)
            ?? $this->sessionPaymentIntentId($data->payment)
            ?? $data->attempt->external_id
            ?? ($data->payment->metadata['stripe_payment_intent_id'] ?? null);

        if ($intentId === null || str_starts_with($intentId, 'ch_')) {
            $intentId = $this->sessionPaymentIntentId($data->payment);
        }

        if ($intentId === null) {
            throw PaymentOperationNotSupported::for('stripe', 'refund without payment_intent');
        }

        $captureTransaction = $this->latestCaptureChargeId($data->payment);

        $refundParams = $captureTransaction !== null
            ? ['charge' => $captureTransaction, 'amount' => $data->amount->minorAmount]
            : ['payment_intent' => $intentId, 'amount' => $data->amount->minorAmount];

        $refund = $this->client->refunds->create(
            $refundParams,
            $this->idempotencyOptions($data->attempt->idempotency_key, "refund:{$data->attempt->id}"),
        );

        return new RefundResult(
            success: in_array($refund->status, ['succeeded', 'pending'], true),
            status: match ($refund->status) {
                'succeeded' => RefundStatus::Succeeded,
                'pending', 'requires_action' => RefundStatus::Pending,
                default => RefundStatus::Failed,
            },
            amount: $data->amount,
            externalId: $refund->id,
        );
    }

    public function parseWebhook(WebhookRequestData $data): GatewayWebhookEvent
    {
        $payload = json_decode($data->payload, true, 512, JSON_THROW_ON_ERROR);
        $type = $payload['type'] ?? 'unknown';
        $object = $payload['data']['object'] ?? [];

        $paymentReference = match ($type) {
            'charge.captured' => $object['payment_intent'] ?? null,
            'charge.refunded', 'refund.updated' => $object['payment_intent'] ?? $object['id'] ?? null,
            default => $object['id'] ?? null,
        };

        $transactionReference = match ($type) {
            'charge.captured' => $object['id'] ?? null,
            'charge.refunded', 'refund.updated' => $object['id'] ?? null,
            default => $object['latest_charge'] ?? $object['id'] ?? null,
        };

        $amountMinor = match ($type) {
            'payment_intent.amount_capturable_updated' => isset($object['amount_capturable']) ? (int) $object['amount_capturable'] : null,
            'charge.captured' => isset($object['amount_captured'])
                ? (int) $object['amount_captured']
                : (isset($object['amount']) ? (int) $object['amount'] : null),
            'charge.refunded' => isset($object['amount'])
                ? (int) $object['amount']
                : (isset($object['amount_refunded']) ? (int) $object['amount_refunded'] : null),
            'refund.updated' => isset($object['amount']) ? (int) $object['amount'] : null,
            default => isset($object['amount']) ? (int) $object['amount'] : null,
        };

        $providerStatus = is_string($object['status'] ?? null) ? (string) $object['status'] : null;

        return new GatewayWebhookEvent(
            eventType: $type,
            eventId: $payload['id'] ?? hash('sha256', $data->payload),
            paymentReference: is_string($paymentReference) ? $paymentReference : null,
            transactionReference: is_string($transactionReference) ? $transactionReference : null,
            amountMinor: $amountMinor,
            currency: isset($object['currency']) ? strtoupper((string) $object['currency']) : null,
            providerStatus: $providerStatus,
        );
    }

    /** @return array<string, string> */
    private function idempotencyOptions(?string $key, string $fallback): array
    {
        $idempotencyKey = $key !== null && $key !== '' ? $key : $fallback;

        return ['idempotency_key' => $idempotencyKey];
    }

    /** @return array<string, int|bool> */
    private function captureParams(CapturePaymentData $data): array
    {
        $params = ['amount_to_capture' => $data->amount->minorAmount];
        $remaining = $data->payment->amount_minor - $data->payment->captured_minor;

        if ($data->amount->minorAmount < $remaining
            && config('ez-ecommerce.drivers.payment.stripe.allow_partial_capture', false)) {
            $params['final_capture'] = false;
        }

        return $params;
    }

    private function sessionPaymentIntentId(Payment $payment): ?string
    {
        $attempt = PaymentAttempt::query()
            ->where('payment_id', $payment->id)
            ->where('operation', 'create_session')
            ->whereNotNull('external_id')
            ->orderByDesc('id')
            ->first();

        $id = $attempt?->external_id;

        return is_string($id) && str_starts_with($id, 'pi_') ? $id : null;
    }

    private function latestCaptureChargeId(Payment $payment): ?string
    {
        $transaction = PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Capture)
            ->where('status', 'succeeded')
            ->whereNotNull('external_id')
            ->orderByDesc('id')
            ->first();

        $id = $transaction?->external_id;

        return is_string($id) && str_starts_with($id, 'ch_') ? $id : null;
    }
}
