<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Data\CreatePaymentSessionData;
use EzEcommerce\Payments\Data\PaymentSessionResult;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use EzEcommerce\Payments\PaymentGatewayRegistry;

final class RetryPaymentSession
{
    public function __construct(
        private readonly Clock $clock,
        private readonly PaymentGatewayRegistry $gateways,
    ) {}

    public function execute(Payment $payment, PaymentAttempt $attempt, Order $order): PaymentSessionResult
    {
        $gateway = $this->gateways->for($payment->gateway);

        $data = new CreatePaymentSessionData(
            payment: $payment,
            attempt: $attempt,
            order: $order,
            amount: Money::fromMinor($payment->amount_minor, $payment->currency),
            metadata: $attempt->request_metadata?->toArray() ?? [],
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
            $payment->update(['status' => $result->status]);

            if ($result->status === PaymentStatus::Captured) {
                PaymentTransaction::query()->create([
                    'payment_id' => $payment->id,
                    'attempt_id' => $attempt->id,
                    'type' => PaymentTransactionType::Capture,
                    'amount_minor' => $payment->amount_minor,
                    'currency' => $payment->currency,
                    'external_id' => $result->externalId,
                    'status' => 'succeeded',
                    'processed_at' => $this->clock->now(),
                ]);
                $payment->update(['captured_minor' => $payment->amount_minor]);
            }
        } elseif ($result->failure?->retryable) {
            $attempt->update(['status' => 'failed_retryable']);
        } else {
            $payment->update(['status' => PaymentStatus::Failed]);
        }

        return $result;
    }
}
