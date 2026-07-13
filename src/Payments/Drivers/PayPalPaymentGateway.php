<?php

namespace EzEcommerce\Payments\Drivers;

use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\RefundStatus;
use EzEcommerce\Payments\Contracts\PaymentGateway;
use EzEcommerce\Payments\Data\CapturePaymentData;
use EzEcommerce\Payments\Data\CreatePaymentSessionData;
use EzEcommerce\Payments\Data\GatewayWebhookEvent;
use EzEcommerce\Payments\Data\PaymentFailure;
use EzEcommerce\Payments\Data\PaymentGatewayCapabilities;
use EzEcommerce\Payments\Data\PaymentResult;
use EzEcommerce\Payments\Data\PaymentSessionResult;
use EzEcommerce\Payments\Data\RefundPaymentData;
use EzEcommerce\Payments\Data\RefundResult;
use EzEcommerce\Payments\Data\VoidPaymentData;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Exceptions\PaymentDriverNotInstalled;
use EzEcommerce\Payments\Exceptions\PaymentOperationNotSupported;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class PayPalPaymentGateway implements PaymentGateway
{
    private string $baseUrl;

    private string $clientId;

    private string $clientSecret;

    public function __construct()
    {
        $config = config('ez-ecommerce.drivers.payment.paypal', []);
        $this->clientId = (string) ($config['client_id'] ?? '');
        $this->clientSecret = (string) ($config['client_secret'] ?? '');
        $mode = (string) ($config['mode'] ?? 'sandbox');

        if ($this->clientId === '' || $this->clientSecret === '') {
            throw PaymentDriverNotInstalled::notConfigured('paypal');
        }

        $this->baseUrl = $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    public function capabilities(): PaymentGatewayCapabilities
    {
        return new PaymentGatewayCapabilities(
            sessions: true,
            capture: true,
            refund: true,
            webhooks: true,
        );
    }

    public function createSession(CreatePaymentSessionData $data): PaymentSessionResult
    {
        $token = $this->accessToken();
        $response = Http::withToken($token)
            ->withHeaders($this->requestIdHeaders($data->attempt->idempotency_key, "session:{$data->attempt->id}"))
            ->post("{$this->baseUrl}/v2/checkout/orders", [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $data->payment->public_id,
                    'amount' => [
                        'currency_code' => $data->amount->currency,
                        'value' => number_format($data->amount->minorAmount / 100, 2, '.', ''),
                    ],
                ]],
            ])
            ->throw();

        $body = $response->json();
        $approveLink = collect($body['links'] ?? [])
            ->firstWhere('rel', 'approve')['href'] ?? null;

        return new PaymentSessionResult(
            status: PaymentStatus::RequiresAction,
            externalId: $body['id'] ?? null,
            redirectUrl: $approveLink,
        );
    }

    public function capture(CapturePaymentData $data): PaymentResult
    {
        $orderId = $data->providerReference ?? $data->attempt->external_id;
        if ($orderId === null) {
            throw PaymentOperationNotSupported::for('paypal', 'capture without order id');
        }

        $token = $this->accessToken();
        $response = Http::withToken($token)
            ->withHeaders($this->requestIdHeaders($data->attempt->idempotency_key, "capture:{$data->attempt->id}"))
            ->post("{$this->baseUrl}/v2/checkout/orders/{$orderId}/capture")
            ->throw();

        $body = $response->json();
        $captureId = $body['purchase_units'][0]['payments']['captures'][0]['id'] ?? $orderId;
        $bodyStatus = (string) ($body['status'] ?? '');

        // PayPal v2 capture responses: COMPLETED (settled), PENDING (accepted but
        // settling), or a failure status. PENDING is a legitimate accepted state —
        // the money will settle asynchronously, so treat it as success with Pending.
        $success = in_array($bodyStatus, ['COMPLETED', 'PENDING'], true);
        $paymentStatus = match ($bodyStatus) {
            'COMPLETED' => PaymentStatus::Captured,
            'PENDING' => PaymentStatus::Pending,
            default => PaymentStatus::Failed,
        };

        $failure = $success ? null : new PaymentFailure(
            code: 'paypal_capture_'.$this->snakeStatus($bodyStatus),
            message: "PayPal capture returned status [{$bodyStatus}].",
            retryable: false,
        );

        return new PaymentResult(
            success: $success,
            status: $paymentStatus,
            amount: $data->amount,
            externalId: $captureId,
            failure: $failure,
        );
    }

    public function void(VoidPaymentData $data): PaymentResult
    {
        throw PaymentOperationNotSupported::for('paypal', 'void');
    }

    public function refund(RefundPaymentData $data): RefundResult
    {
        $captureId = $data->providerReference ?? $data->attempt->external_id ?? ($data->payment->metadata['paypal_capture_id'] ?? null);
        if ($captureId === null) {
            throw PaymentOperationNotSupported::for('paypal', 'refund without capture id');
        }

        $token = $this->accessToken();
        $response = Http::withToken($token)
            ->withHeaders($this->requestIdHeaders($data->attempt->idempotency_key, "refund:{$data->attempt->id}"))
            ->post("{$this->baseUrl}/v2/payments/captures/{$captureId}/refund", [
                'amount' => [
                    'currency_code' => $data->amount->currency,
                    'value' => number_format($data->amount->minorAmount / 100, 2, '.', ''),
                ],
            ])
            ->throw();

        $body = $response->json();

        // ponytail: PayPal amounts assume two-decimal currencies via /100 and number_format(...,2).
        // Ceiling: zero-decimal (JPY) and three-decimal (KWD) currencies break here.
        // Upgrade: add currency exponent metadata to the gateway layer and use bcmath for string-safe conversion.
        $status = (string) ($body['status'] ?? '');

        return new RefundResult(
            success: in_array($status, ['COMPLETED', 'PENDING'], true),
            status: match ($status) {
                'COMPLETED' => RefundStatus::Succeeded,
                'PENDING' => RefundStatus::Pending,
                default => RefundStatus::Failed,
            },
            amount: $data->amount,
            externalId: $body['id'] ?? null,
        );
    }

    public function parseWebhook(WebhookRequestData $data): GatewayWebhookEvent
    {
        $payload = json_decode($data->payload, true, 512, JSON_THROW_ON_ERROR);
        $resource = $payload['resource'] ?? [];
        $eventType = $payload['event_type'] ?? 'unknown';

        $paymentReference = $resource['supplementary_data']['related_ids']['order_id']
            ?? $resource['purchase_units'][0]['reference_id']
            ?? $payload['resource']['purchase_units'][0]['reference_id']
            ?? null;

        $transactionReference = null;
        $providerStatus = null;
        if (in_array($eventType, ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.SALE.COMPLETED'], true)) {
            $transactionReference = $resource['id'] ?? null;
        } elseif ($eventType === 'PAYMENT.CAPTURE.REFUNDED' || str_starts_with($eventType, 'PAYMENT.REFUND')) {
            // PAYMENT.CAPTURE.REFUNDED is PayPal's documented refund-success event; its resource
            // is the refund object, so resource.id is the refund id we correlated against.
            $transactionReference = $resource['id'] ?? null;
            $providerStatus = is_string($resource['status'] ?? null) ? (string) $resource['status'] : null;
        } elseif (in_array($eventType, ['PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DECLINED', 'PAYMENT.CAPTURE.REVERSED'], true)) {
            // PayPal v2 capture lifecycle: pending (async settlement), declined, reversed.
            $transactionReference = $resource['id'] ?? null;
            $providerStatus = is_string($resource['status'] ?? null) ? (string) $resource['status'] : null;
        }

        $amountMinor = null;
        $currency = null;
        if (isset($resource['amount']['value'], $resource['amount']['currency_code'])) {
            // ponytail: webhook amounts parsed as float * 100; breaks zero/three-decimal currencies.
            // Ceiling: same as refund() — currency exponent metadata needed.
            // Upgrade: add exponent-aware string decimal parsing in the gateway layer.
            $amountMinor = (int) round((float) $resource['amount']['value'] * 100);
            $currency = strtoupper((string) $resource['amount']['currency_code']);
        }

        return new GatewayWebhookEvent(
            eventType: $eventType,
            eventId: $payload['id'] ?? hash('sha256', $data->payload),
            paymentReference: is_string($paymentReference) ? $paymentReference : null,
            transactionReference: is_string($transactionReference) ? $transactionReference : null,
            amountMinor: $amountMinor,
            currency: $currency,
            providerStatus: $providerStatus,
        );
    }

    public function verifyWebhookSignature(string $payload, Request $request): void
    {
        $webhookId = config('ez-ecommerce.drivers.payment.paypal.webhook_id');
        if ($webhookId === null || $webhookId === '') {
            abort(403, 'PayPal webhook ID is not configured.');
        }

        $response = Http::withToken($this->accessToken())
            ->post("{$this->baseUrl}/v1/notifications/verify-webhook-signature", [
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url' => $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id' => $webhookId,
                'webhook_event' => json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
            ]);

        if (($response->json('verification_status') ?? '') !== 'SUCCESS') {
            abort(400, 'Invalid PayPal webhook signature.');
        }
    }

    private function accessToken(): string
    {
        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->asForm()
            ->post("{$this->baseUrl}/v1/oauth2/token", ['grant_type' => 'client_credentials'])
            ->throw();

        return (string) $response->json('access_token');
    }

    /** @return array<string, string> */
    private function requestIdHeaders(?string $key, string $fallback): array
    {
        return ['PayPal-Request-Id' => $key !== null && $key !== '' ? $key : $fallback];
    }

    private function snakeStatus(string $status): string
    {
        return strtolower(preg_replace('/([A-Z])/', '_$1', $status) ?? $status);
    }
}
