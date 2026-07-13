<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Contracts\PaymentOperationPolicy;
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
        private readonly PaymentOperationPolicy $paymentOperationPolicy,
    ) {
    }

    public function execute(Payment $payment, Order $order, string $idempotencyKey = ''): PaymentSessionResult
    {
        $payment->refresh();

        if (in_array($payment->status, [PaymentStatus::Captured, PaymentStatus::PartiallyCaptured], true)) {
            throw new RuntimeException('Payment is already captured.');
        }

        if (! $this->paymentOperationPolicy->canCreateSession($payment)) {
            throw new RuntimeException('Order is cancelled or completed; cannot retry payment session.');
        }

        if ($idempotencyKey !== '') {
            $existing = PaymentAttempt::query()
                ->where('payment_id', $payment->id)
                ->where('idempotency_key', $idempotencyKey)
                ->where('operation', 'create_session')
                ->first();

            if ($existing !== null) {
                if ($existing->status === 'requires_action') {
                    return $this->existingSessionResult($existing);
                }

                if (in_array($existing->status, ['pending', 'unknown'], true)) {
                    throw new RuntimeException('Payment session with this idempotency key is in progress or requires reconciliation.');
                }

                if ($existing->status === 'failed_retryable') {
                    return $this->createSessionForAttempt($payment, $order, $existing);
                }
            }
        }

        $sourceAttempt = PaymentAttempt::query()
            ->where('payment_id', $payment->id)
            ->where('operation', 'create_session')
            ->latest()
            ->first();

        if ($sourceAttempt === null) {
            throw new RuntimeException('No payment session attempt exists for this payment.');
        }

        if ($sourceAttempt->status === 'requires_action') {
            return $this->existingSessionResult($sourceAttempt);
        }

        if ($sourceAttempt->status === 'pending') {
            throw new RuntimeException('Payment session is in progress.');
        }

        if (! $this->isRetryableSessionAttempt($sourceAttempt)) {
            throw new RuntimeException('Payment session is not in a retryable state.');
        }

        $attempt = $this->shouldReuseAttempt($sourceAttempt)
            ? $sourceAttempt
            : $this->createRetryAttempt($payment, $sourceAttempt, $idempotencyKey);

        return $this->createSessionForAttempt($payment, $order, $attempt);
    }

    private function shouldReuseAttempt(PaymentAttempt $attempt): bool
    {
        return in_array($attempt->status, ['unknown', 'failed_retryable'], true);
    }

    private function createRetryAttempt(Payment $payment, PaymentAttempt $sourceAttempt, string $idempotencyKey = ''): PaymentAttempt
    {
        return PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'operation' => 'create_session',
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : 'session_retry:'.$payment->public_id.':'.Str::uuid(),
            'status' => 'pending',
            'request_metadata' => $sourceAttempt->request_metadata instanceof \ArrayObject
                ? $sourceAttempt->request_metadata->getArrayCopy()
                : (array) ($sourceAttempt->request_metadata ?? []),
        ]);
    }

    private function createSessionForAttempt(Payment $payment, Order $order, PaymentAttempt $attempt): PaymentSessionResult
    {
        $attempt->update(['status' => 'pending']);

        $gateway = $this->gateways->for($payment->gateway);

        $metadata = $attempt->request_metadata instanceof \ArrayObject
            ? $attempt->request_metadata->getArrayCopy()
            : (array) ($attempt->request_metadata ?? []);

        $data = new CreatePaymentSessionData(
            payment: $payment,
            attempt: $attempt,
            order: $order,
            amount: Money::fromMinor($payment->amount_minor, $payment->currency),
            metadata: $metadata,
        );

        try {
            $result = $gateway->createSession($data);
        } catch (\Throwable $e) {
            $attempt->update([
                'status' => 'failed_retryable',
                'error_code' => 'session_exception',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

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

    private function existingSessionResult(PaymentAttempt $attempt): PaymentSessionResult
    {
        return new PaymentSessionResult(
            status: PaymentStatus::RequiresAction,
            externalId: $attempt->external_id,
            redirectUrl: $attempt->redirect_url,
            clientSecret: $attempt->client_secret,
            metadata: $attempt->response_metadata instanceof \ArrayObject
                ? $attempt->response_metadata->getArrayCopy()
                : (array) ($attempt->response_metadata ?? []),
        );
    }

    private function isRetryableSessionAttempt(PaymentAttempt $attempt): bool
    {
        return in_array($attempt->status, [
            'failed_retryable',
            'unknown',
            'failed',
        ], true);
    }
}
