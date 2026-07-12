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
use EzEcommerce\Payments\Data\WebhookRequestData;

final class FakePaymentGateway implements PaymentGateway
{
    public function __construct(
        private ?PaymentSessionResult $sessionResult = null,
        private ?PaymentResult $captureResult = null,
        private ?RefundResult $refundResult = null,
        private ?GatewayWebhookEvent $webhookEvent = null,
        private bool $sessionThrows = false,
        private bool $captureThrows = false,
    ) {}

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
        if ($this->sessionThrows) {
            throw new \RuntimeException('Fake gateway session timeout.');
        }

        return $this->sessionResult ?? new PaymentSessionResult(
            status: PaymentStatus::RequiresAction,
            externalId: 'fake_'.$data->attempt->public_id,
            clientSecret: 'fake_secret',
        );
    }

    public function capture(CapturePaymentData $data): PaymentResult
    {
        if ($this->captureThrows) {
            throw new \RuntimeException('Fake gateway capture failure.');
        }

        return $this->captureResult ?? new PaymentResult(
            success: true,
            status: PaymentStatus::Captured,
            amount: $data->amount,
            externalId: 'fake_capture_'.$data->attempt->public_id,
        );
    }

    public function refund(RefundPaymentData $data): RefundResult
    {
        return $this->refundResult ?? new RefundResult(
            success: true,
            status: RefundStatus::Succeeded,
            amount: $data->amount,
            externalId: 'fake_refund_'.$data->attempt->public_id,
        );
    }

    public function parseWebhook(WebhookRequestData $data): GatewayWebhookEvent
    {
        return $this->webhookEvent ?? new GatewayWebhookEvent(
            eventType: 'payment.captured',
            externalId: 'fake_event_'.hash('sha256', $data->payload),
            paymentExternalId: 'fake_payment',
        );
    }

    public static function failingSession(string $code = 'session_failed', bool $retryable = true): self
    {
        return new self(sessionResult: new PaymentSessionResult(
            status: PaymentStatus::Failed,
            failure: new PaymentFailure($code, 'Session creation failed.', $retryable),
        ));
    }

    public static function requiresAction(): self
    {
        return new self(sessionResult: new PaymentSessionResult(
            status: PaymentStatus::RequiresAction,
            externalId: 'fake_requires_action',
            redirectUrl: 'https://fake.test/pay',
        ));
    }
}
