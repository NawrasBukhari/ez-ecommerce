<?php

namespace EzEcommerce\Payments\Contracts;

use EzEcommerce\Payments\Data\CapturePaymentData;
use EzEcommerce\Payments\Data\CreatePaymentSessionData;
use EzEcommerce\Payments\Data\GatewayWebhookEvent;
use EzEcommerce\Payments\Data\PaymentGatewayCapabilities;
use EzEcommerce\Payments\Data\PaymentResult;
use EzEcommerce\Payments\Data\PaymentSessionResult;
use EzEcommerce\Payments\Data\RefundPaymentData;
use EzEcommerce\Payments\Data\RefundResult;
use EzEcommerce\Payments\Data\WebhookRequestData;

interface PaymentGateway
{
    public function capabilities(): PaymentGatewayCapabilities;

    public function createSession(CreatePaymentSessionData $data): PaymentSessionResult;

    public function capture(CapturePaymentData $data): PaymentResult;

    public function refund(RefundPaymentData $data): RefundResult;

    public function parseWebhook(WebhookRequestData $data): GatewayWebhookEvent;
}
