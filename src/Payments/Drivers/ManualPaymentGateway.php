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
use EzEcommerce\Payments\Exceptions\PaymentOperationNotSupported;

final class ManualPaymentGateway implements PaymentGateway
{
    public function capabilities(): PaymentGatewayCapabilities
    {
        return new PaymentGatewayCapabilities(
            sessions: true,
            capture: true,
            refund: true,
        );
    }

    public function createSession(CreatePaymentSessionData $data): PaymentSessionResult
    {
        return new PaymentSessionResult(
            status: PaymentStatus::Pending,
            externalId: 'manual_'.$data->attempt->public_id,
        );
    }

    public function capture(CapturePaymentData $data): PaymentResult
    {
        return new PaymentResult(
            success: true,
            status: PaymentStatus::Captured,
            amount: $data->amount,
            externalId: 'manual_capture_'.$data->attempt->public_id,
        );
    }

    public function refund(RefundPaymentData $data): RefundResult
    {
        return new RefundResult(
            success: true,
            status: RefundStatus::Succeeded,
            amount: $data->amount,
            externalId: 'manual_refund_'.$data->attempt->public_id,
        );
    }

    public function parseWebhook(WebhookRequestData $data): GatewayWebhookEvent
    {
        throw PaymentOperationNotSupported::for('manual', 'parseWebhook');
    }
}
