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
        $orderId = $data->attempt->external_id;
        if ($orderId === null) {
            throw PaymentOperationNotSupported::for('paypal', 'capture without order id');
        }

        $token = $this->accessToken();
        $response = Http::withToken($token)
            ->post("{$this->baseUrl}/v2/checkout/orders/{$orderId}/capture")
            ->throw();

        $body = $response->json();
        $captureId = $body['purchase_units'][0]['payments']['captures'][0]['id'] ?? $orderId;

        return new PaymentResult(
            success: ($body['status'] ?? '') === 'COMPLETED',
            status: PaymentStatus::Captured,
            amount: $data->amount,
            externalId: $captureId,
        );
    }

    public function refund(RefundPaymentData $data): RefundResult
    {
        $captureId = $data->attempt->external_id ?? $data->payment->metadata?->get('paypal_capture_id');
        if ($captureId === null) {
            throw PaymentOperationNotSupported::for('paypal', 'refund without capture id');
        }

        $token = $this->accessToken();
        $response = Http::withToken($token)
            ->post("{$this->baseUrl}/v2/payments/captures/{$captureId}/refund", [
                'amount' => [
                    'currency_code' => $data->amount->currency,
                    'value' => number_format($data->amount->minorAmount / 100, 2, '.', ''),
                ],
            ])
            ->throw();

        $body = $response->json();

        return new RefundResult(
            success: ($body['status'] ?? '') === 'COMPLETED',
            status: RefundStatus::Succeeded,
            amount: $data->amount,
            externalId: $body['id'] ?? null,
        );
    }

    public function parseWebhook(WebhookRequestData $data): GatewayWebhookEvent
    {
        $payload = json_decode($data->payload, true, 512, JSON_THROW_ON_ERROR);
        $resource = $payload['resource'] ?? [];

        $paymentExternalId = $resource['id']
            ?? $resource['supplementary_data']['related_ids']['order_id']
            ?? $payload['resource']['purchase_units'][0]['reference_id']
            ?? null;

        $amountMinor = null;
        $currency = null;
        if (isset($resource['amount']['value'], $resource['amount']['currency_code'])) {
            $amountMinor = (int) round((float) $resource['amount']['value'] * 100);
            $currency = strtoupper((string) $resource['amount']['currency_code']);
        }

        return new GatewayWebhookEvent(
            eventType: $payload['event_type'] ?? 'unknown',
            externalId: $payload['id'] ?? hash('sha256', $data->payload),
            paymentExternalId: $paymentExternalId,
            amountMinor: $amountMinor,
            currency: $currency,
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
}
