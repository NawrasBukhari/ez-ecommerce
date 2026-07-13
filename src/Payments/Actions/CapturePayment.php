<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Exceptions\IdempotencyPayloadMismatchException;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Core\Support\CanonicalJson;
use EzEcommerce\Payments\Contracts\PaymentOperationPolicy;
use EzEcommerce\Payments\Data\CapturePaymentData;
use EzEcommerce\Payments\Data\PaymentResult;
use EzEcommerce\Payments\Exceptions\PaymentOperationNotSupported;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\PaymentGatewayRegistry;
use EzEcommerce\Payments\Support\PaymentAttemptRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class CapturePayment
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly ResolveProviderPaymentReference $providerReference,
        private readonly HandleCaptureResult $handleCaptureResult,
        private readonly PaymentOperationPolicy $paymentOperationPolicy,
        private readonly AssertNoConflictingPaymentOperation $assertNoConflictingPaymentOperation,
    ) {
    }

    public function executeForPayment(Payment $payment, ?Money $amount = null, string $idempotencyKey = ''): PaymentResult
    {
        $payment->refresh();

        $captureAmount = $amount ?? Money::fromMinor(
            $payment->amount_minor - $payment->captured_minor,
            $payment->currency,
        );

        if ($idempotencyKey !== '') {
            $payloadHash = $this->capturePayloadHash($payment, $captureAmount);

            $existingAttempt = PaymentAttempt::query()
                ->where('payment_id', $payment->id)
                ->where('idempotency_key', $idempotencyKey)
                ->where('operation', 'capture')
                ->first();

            if ($existingAttempt !== null) {
                $metadata = PaymentAttemptRequest::metadata($existingAttempt);
                $storedHash = $metadata['payload_hash'] ?? null;

                if ($storedHash !== null && $storedHash !== $payloadHash) {
                    throw IdempotencyPayloadMismatchException::for('capture', $idempotencyKey);
                }

                return match ($existingAttempt->status) {
                    'succeeded', 'failed' => $this->resultFromAttempt($payment, $existingAttempt),
                    'pending' => throw new RuntimeException('Capture with this idempotency key is already in progress.'),
                    'unknown' => throw new RuntimeException('Capture with this idempotency key requires reconciliation.'),
                    default => $this->execute($payment, $existingAttempt, $amount),
                };
            }
        }

        $attempt = DB::transaction(function () use ($payment, $amount, $captureAmount, $idempotencyKey) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! $this->paymentOperationPolicy->canCapture($locked)) {
                throw new RuntimeException('Order is cancelled or completed, or payment is not in a capturable state.');
            }

            $this->assertNoConflictingPaymentOperation->execute($locked, 'capture');

            $captureAmount = $amount ?? Money::fromMinor(
                $locked->amount_minor - $locked->captured_minor,
                $locked->currency,
            );

            if ($captureAmount->minorAmount <= 0) {
                throw new InvalidArgumentException('Nothing left to capture.');
            }

            $inFlightCapture = PaymentAttempt::query()
                ->where('payment_id', $locked->id)
                ->where('operation', 'capture')
                ->whereIn('status', ['pending', 'unknown'])
                ->exists();

            if ($inFlightCapture) {
                throw new RuntimeException('A capture is in progress or requires reconciliation for this payment.');
            }

            $gateway = $this->gateways->for($locked->gateway);
            if (! $gateway->capabilities()->capture) {
                throw PaymentOperationNotSupported::for($locked->gateway, 'capture');
            }

            // Reject an unsupported partial capture before inserting any attempt
            // row, so a disabled-config rejection leaves no pending/unknown orphan.
            $this->assertPartialCaptureAllowed($locked, $captureAmount);

            return PaymentAttempt::query()->create([
                'payment_id' => $locked->id,
                'operation' => 'capture',
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : 'capture:'.$locked->public_id.':'.Str::uuid(),
                'status' => 'pending',
                'request_metadata' => [
                    'requested_amount_minor' => $captureAmount->minorAmount,
                    'currency' => $captureAmount->currency,
                    'provider_operation' => 'capture',
                    'payload_hash' => $this->capturePayloadHash($locked, $captureAmount),
                ],
            ]);
        });

        return $this->execute($payment->fresh(), $attempt, $amount);
    }

    private function capturePayloadHash(Payment $payment, Money $amount): string
    {
        return hash('sha256', CanonicalJson::encode([
            'payment_id' => $payment->id,
            'amount_minor' => $amount->minorAmount,
            'currency' => $amount->currency,
        ]));
    }

    private function assertPartialCaptureAllowed(Payment $payment, Money $captureAmount): void
    {
        if ($payment->gateway === 'stripe'
            && $captureAmount->minorAmount < ($payment->amount_minor - $payment->captured_minor)
            && ! config('ez-ecommerce.drivers.payment.stripe.allow_partial_capture', false)) {
            throw new InvalidArgumentException('Partial Stripe capture is not enabled.');
        }
    }

    private function resultFromAttempt(Payment $payment, PaymentAttempt $attempt): PaymentResult
    {
        $payment->refresh();

        return new PaymentResult(
            success: $attempt->status === 'succeeded',
            status: $payment->status,
            amount: Money::fromMinor(
                PaymentAttemptRequest::metadata($attempt)['requested_amount_minor'] ?? $payment->amount_minor,
                $payment->currency,
            ),
            externalId: $attempt->external_id,
        );
    }

    public function execute(Payment $payment, PaymentAttempt $attempt, ?Money $amount = null): PaymentResult
    {
        if ($attempt->operation !== 'capture') {
            return $this->executeForPayment($payment, $amount);
        }

        ['payment' => $payment, 'amount' => $amount] = DB::transaction(function () use ($payment, $amount, $attempt) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! $this->paymentOperationPolicy->canCapture($locked)) {
                throw new RuntimeException('Order is cancelled or completed, or payment is not in a capturable state.');
            }

            $this->assertNoConflictingPaymentOperation->execute($locked, 'capture');

            $captureAmount = $amount
                ?? PaymentAttemptRequest::captureAmount($attempt, $locked)
                ?? Money::fromMinor(
                    $locked->amount_minor - $locked->captured_minor,
                    $locked->currency,
                );

            if ($captureAmount->minorAmount <= 0) {
                throw new InvalidArgumentException('Nothing left to capture.');
            }

            $inFlightCapture = PaymentAttempt::query()
                ->where('payment_id', $locked->id)
                ->where('operation', 'capture')
                ->where('id', '!=', $attempt->id)
                ->whereIn('status', ['pending', 'unknown'])
                ->exists();

            if ($inFlightCapture) {
                throw new RuntimeException('A capture is in progress or requires reconciliation for this payment.');
            }

            $captureIdempotencyKey = $attempt->idempotency_key !== '' && $attempt->idempotency_key !== null
                ? $attempt->idempotency_key
                : "capture:{$attempt->id}";

            PaymentAttemptRequest::merge($attempt, [
                'requested_amount_minor' => $captureAmount->minorAmount,
                'currency' => $captureAmount->currency,
                'provider_operation' => 'capture',
            ]);

            $attempt->update([
                'operation' => 'capture',
                'status' => 'pending',
                'idempotency_key' => $captureIdempotencyKey,
            ]);
            $attempt = $attempt->fresh();

            return ['payment' => $locked, 'amount' => $captureAmount];
        });

        $gateway = $this->gateways->for($payment->gateway);

        if (! $gateway->capabilities()->capture) {
            $attempt->update([
                'status' => 'failed',
                'error_code' => 'capture_not_supported',
                'error_message' => "Capture is not supported for gateway [{$payment->gateway}].",
            ]);

            throw PaymentOperationNotSupported::for($payment->gateway, 'capture');
        }

        if ($payment->gateway === 'stripe'
            && $amount->minorAmount < ($payment->amount_minor - $payment->captured_minor)
            && ! config('ez-ecommerce.drivers.payment.stripe.allow_partial_capture', false)) {
            // A replay reaching here means the attempt already exists (e.g. a
            // failed_retryable partial capture retried after partial capture was
            // disabled). Mark it terminally failed so no pending/unknown orphan
            // remains, then surface the validation error.
            $attempt->update([
                'status' => 'failed',
                'error_code' => 'partial_capture_disabled',
                'error_message' => 'Partial Stripe capture is not enabled.',
            ]);

            throw new InvalidArgumentException('Partial Stripe capture is not enabled.');
        }

        try {
            $result = $gateway->capture(new CapturePaymentData(
                payment: $payment,
                attempt: $attempt,
                amount: $amount,
                providerReference: $this->providerReference->forCapture($payment),
            ));
        } catch (\Throwable $e) {
            $attempt->update([
                'status' => 'unknown',
                'error_code' => 'capture_exception',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        // The gateway PaymentStatus (not a bare success flag) drives finalization.
        // A provider-accepted but pending capture finalizes nothing until the
        // completion webhook arrives.
        $this->handleCaptureResult->execute($payment, $attempt, $result);

        return $result;
    }
}
