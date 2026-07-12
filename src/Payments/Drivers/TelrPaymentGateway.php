<?php

namespace EzEcommerce\Payments\Drivers;

use EzEcommerce\Core\Enums\PaymentStatus;
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
use Illuminate\Support\Str;

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
            capture: true,
            webhooks: true,
        );
    }

    public function createSession(CreatePaymentSessionData $data): PaymentSessionResult
    {
        $reference = $data->attempt->public_id;
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
        ]);

        $body = $response->json();
        $ref = $body['order']['ref'] ?? ('telr_'.Str::lower(Str::random(12)));

        return new PaymentSessionResult(
            status: PaymentStatus::RequiresAction,
            externalId: $ref,
            redirectUrl: $body['order']['url'] ?? config('ez-ecommerce.drivers.payment.telr.checkout_url').'?ref='.$ref,
            metadata: ['telr_reference' => $reference],
        );
    }

    public function capture(CapturePaymentData $data): PaymentResult
    {
        return new PaymentResult(
            success: true,
            status: PaymentStatus::Captured,
            amount: $data->amount,
            externalId: $data->attempt->external_id ?? 'telr_capture_'.$data->attempt->public_id,
        );
    }

    public function refund(RefundPaymentData $data): RefundResult
    {
        throw PaymentOperationNotSupported::for('telr', 'refund');
    }

    public function parseWebhook(WebhookRequestData $data): GatewayWebhookEvent
    {
        parse_str($data->payload, $fields);

        return new GatewayWebhookEvent(
            eventType: $fields['tran_status'] ?? 'telr.webhook',
            externalId: $fields['tran_ref'] ?? hash('sha256', $data->payload),
            paymentExternalId: $fields['cartid'] ?? null,
            amountMinor: isset($fields['tran_amount']) ? (int) round((float) $fields['tran_amount'] * 100) : null,
            currency: isset($fields['tran_currency']) ? strtoupper((string) $fields['tran_currency']) : null,
        );
    }
}
