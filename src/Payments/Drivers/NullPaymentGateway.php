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
use EzEcommerce\Payments\Exceptions\PaymentOperationNotSupported;
use InvalidArgumentException;

final class NullPaymentGateway implements PaymentGateway
{
    public function capabilities(): PaymentGatewayCapabilities
    {
        return new PaymentGatewayCapabilities(
            sessions: true,
            capture: true,
        );
    }

    public function createSession(CreatePaymentSessionData $data): PaymentSessionResult
    {
        if (! $data->amount->isZero()) {
            throw new InvalidArgumentException('Null payment gateway only supports zero-total orders.');
        }

        return new PaymentSessionResult(
            status: PaymentStatus::Captured,
            externalId: 'null_'.$data->payment->public_id,
        );
    }

    public function capture(CapturePaymentData $data): PaymentResult
    {
        if (! $data->amount->isZero()) {
            throw new InvalidArgumentException('Null payment gateway only supports zero-total orders.');
        }

        return new PaymentResult(
            success: true,
            status: PaymentStatus::Captured,
            amount: $data->amount,
            externalId: 'null_capture_'.$data->payment->public_id,
        );
    }

    public function refund(RefundPaymentData $data): RefundResult
    {
        throw PaymentOperationNotSupported::for('null', 'refund');
    }

    public function parseWebhook(WebhookRequestData $data): GatewayWebhookEvent
    {
        throw PaymentOperationNotSupported::for('null', 'parseWebhook');
    }
}
