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
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TelrPaymentGateway implements PaymentGateway
{
    private string $storeId;

    private string $authKey;

    private string $endpoint;

    public function __construct()
    {
        $config = config('ez-ecommerce.drivers.payment.telr', []);
        $this->storeId = (string) ($config['store_id'] ?? '');
        $this->authKey = (string) ($config['auth_key'] ?? '');
        $this->endpoint = (string) ($config['endpoint'] ?? 'https://secure.telr.com/gateway/order.json');

        if ($this->storeId === '' || $this->authKey === '') {
            throw PaymentDriverNotInstalled::notConfigured('telr');
        }
    }

    public function capabilities(): PaymentGatewayCapabilities
    {
        return new PaymentGatewayCapabilities(
            sessions: true,
            webhooks: true,
        );
    }

    public function createSession(CreatePaymentSessionData $data): PaymentSessionResult
    {
        $response = Http::post($this->endpoint, [
            'method' => 'create',
            'store' => $this->storeId,
            'authkey' => $this->authKey,
            'order' => [
                'cartid' => $data->order->public_id,
                'test' => (int) config('ez-ecommerce.drivers.payment.telr.test_mode', true),
                'amount' => number_format($data->amount->minorAmount / 100, 2, '.', ''),
                'currency' => $data->amount->currency,
                'description' => 'Order '.$data->order->public_id,
            ],
            'return' => [
                'authorised' => config('ez-ecommerce.drivers.payment.telr.return_url'),
                'declined' => config('ez-ecommerce.drivers.payment.telr.return_url'),
                'cancelled' => config('ez-ecommerce.drivers.payment.telr.return_url'),
            ],
        ])->throw();

        $body = $response->json();
        $ref = $body['order']['ref'] ?? null;
        if (! is_string($ref) || $ref === '') {
            throw new RuntimeException('Telr session creation did not return an order reference.');
        }

        return new PaymentSessionResult(
            status: PaymentStatus::RequiresAction,
            externalId: $ref,
            redirectUrl: $body['order']['url'] ?? config('ez-ecommerce.drivers.payment.telr.checkout_url').'?ref='.$ref,
            metadata: ['telr_reference' => $data->attempt->public_id],
        );
    }

    public function capture(CapturePaymentData $data): PaymentResult
    {
        throw PaymentOperationNotSupported::for('telr', 'capture');
    }

    public function refund(RefundPaymentData $data): RefundResult
    {
        $ref = $data->providerReference ?? $data->attempt->external_id ?? ($data->payment->metadata['telr_ref'] ?? null);
        if ($ref === null || $ref === '') {
            throw PaymentOperationNotSupported::for('telr', 'refund without order ref');
        }

        $response = Http::post($this->endpoint, [
            'method' => 'refund',
            'store' => $this->storeId,
            'authkey' => $this->authKey,
            'order' => [
                'ref' => $ref,
                'amount' => number_format($data->amount->minorAmount / 100, 2, '.', ''),
            ],
        ])->throw();

        $body = $response->json();
        $accepted = ($body['order']['status'] ?? '') === 'accepted'
            || ($body['order']['message'] ?? '') === 'Accepted';

        return new RefundResult(
            success: $accepted,
            status: $accepted ? RefundStatus::Succeeded : RefundStatus::Failed,
            amount: $data->amount,
            externalId: $body['order']['ref'] ?? ('telr_refund_'.$data->attempt->public_id),
        );
    }

    public function parseWebhook(WebhookRequestData $data): GatewayWebhookEvent
    {
        parse_str($data->payload, $fields);

        $transactionReference = $fields['tran_ref'] ?? null;

        return new GatewayWebhookEvent(
            eventType: $fields['tran_status'] ?? 'telr.webhook',
            eventId: is_string($transactionReference) ? $transactionReference : hash('sha256', $data->payload),
            paymentReference: $fields['cartid'] ?? null,
            transactionReference: is_string($transactionReference) ? $transactionReference : null,
            amountMinor: isset($fields['tran_amount']) ? (int) round((float) $fields['tran_amount'] * 100) : null,
            currency: isset($fields['tran_currency']) ? strtoupper((string) $fields['tran_currency']) : null,
        );
    }
}
