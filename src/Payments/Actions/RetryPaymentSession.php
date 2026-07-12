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
use Illuminate\Support\Str;
use RuntimeException;

final class RetryPaymentSession
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly FinalizeAcceptedPayment $finalizeAcceptedPayment,
    ) {
    }

    public function execute(Payment $payment, Order $order): PaymentSessionResult
    {
        $payment->refresh();

        if (in_array($payment->status, [PaymentStatus::Captured, PaymentStatus::PartiallyCaptured], true)) {
            throw new RuntimeException('Payment is already captured.');
        }

        $sourceAttempt = PaymentAttempt::query()
            ->where('payment_id', $payment->id)
            ->where('operation', 'create_session')
            ->latest()
            ->first();

        if ($sourceAttempt === null) {
            throw new RuntimeException('No payment session attempt exists for this payment.');
        }

        if (! $this->isRetryableSessionAttempt($sourceAttempt)) {
            throw new RuntimeException('Payment session is not in a retryable state.');
        }

        $attempt = PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'operation' => 'create_session',
            'idempotency_key' => 'session_retry:'.$payment->public_id.':'.Str::uuid(),
            'status' => 'pending',
            'request_metadata' => $sourceAttempt->request_metadata instanceof \ArrayObject
                ? $sourceAttempt->request_metadata->getArrayCopy()
                : (array) ($sourceAttempt->request_metadata ?? []),
        ]);

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

    private function isRetryableSessionAttempt(PaymentAttempt $attempt): bool
    {
        return in_array($attempt->status, [
            'pending',
            'requires_action',
            'failed_retryable',
            'unknown',
            'failed',
        ], true);
    }
}
