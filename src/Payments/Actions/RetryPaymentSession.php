<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Data\CreatePaymentSessionData;
use EzEcommerce\Payments\Data\PaymentSessionResult;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\PaymentGatewayRegistry;

final class RetryPaymentSession
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly FinalizeAcceptedPayment $finalizeAcceptedPayment,
    ) {}

    public function execute(Payment $payment, PaymentAttempt $attempt, Order $order): PaymentSessionResult
    {
        $gateway = $this->gateways->for($payment->gateway);

        $data = new CreatePaymentSessionData(
            payment: $payment,
            attempt: $attempt,
            order: $order,
            amount: Money::fromMinor($payment->amount_minor, $payment->currency),
            metadata: $attempt->request_metadata instanceof \ArrayObject
                ? $attempt->request_metadata->getArrayCopy()
                : (array) ($attempt->request_metadata ?? []),
        );

        $result = $gateway->createSession($data);

        $attempt->update([
            'status' => $result->status->value,
            'external_id' => $result->externalId,
            'redirect_url' => $result->redirectUrl,
            'client_secret' => $result->clientSecret,
            'error_code' => $result->failure?->code,
            'error_message' => $result->failure?->message,
            'response_metadata' => $result->metadata,
        ]);

        if ($result->succeeded()) {
            if ($result->status === PaymentStatus::Captured) {
                $this->finalizeAcceptedPayment->execute(
                    $payment,
                    $attempt,
                    $payment->amount_minor,
                    $payment->currency,
                    $result->externalId,
                    $result->metadata,
                );
            } else {
                $payment->update(['status' => $result->status]);
            }
        } elseif ($result->failure?->retryable) {
            $attempt->update(['status' => 'failed_retryable']);
        } else {
            $payment->update(['status' => PaymentStatus::Failed]);
        }

        return $result;
    }
}
