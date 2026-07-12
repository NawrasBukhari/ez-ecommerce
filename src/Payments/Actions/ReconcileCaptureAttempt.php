<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Payments\Data\PaymentResult;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Support\PaymentAttemptRequest;
use RuntimeException;

final class ReconcileCaptureAttempt
{
    public function __construct(
        private readonly CapturePayment $capturePayment,
        private readonly FinalizeAcceptedPayment $finalizeAcceptedPayment,
    ) {}

    public function confirmProviderSucceeded(
        PaymentAttempt $attempt,
        int $amountMinor,
        string $currency,
        string $externalId,
    ): Payment {
        if ($attempt->operation !== 'capture' || $attempt->status !== 'unknown') {
            throw new RuntimeException('Attempt is not an unknown capture.');
        }

        $payment = $attempt->payment;
        if ($payment === null) {
            throw new RuntimeException("Capture attempt [{$attempt->id}] is missing its payment.");
        }

        return $this->finalizeAcceptedPayment->execute(
            $payment,
            $attempt,
            $amountMinor,
            $currency,
            $externalId,
        );
    }

    public function retry(PaymentAttempt $attempt): PaymentResult
    {
        if ($attempt->operation !== 'capture' || $attempt->status !== 'unknown') {
            throw new RuntimeException('Attempt is not an unknown capture.');
        }

        $payment = $attempt->payment;
        if ($payment === null) {
            throw new RuntimeException("Capture attempt [{$attempt->id}] is missing its payment.");
        }

        $amount = PaymentAttemptRequest::captureAmount($attempt, $payment);
        if ($amount === null) {
            throw new RuntimeException("Capture attempt [{$attempt->id}] is missing a request amount snapshot.");
        }

        return $this->capturePayment->execute($payment, $attempt, $amount);
    }
}
