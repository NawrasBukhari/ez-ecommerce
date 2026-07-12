<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Exceptions\IdempotencyPayloadMismatchException;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Core\Support\CanonicalJson;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
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
        private readonly FinalizeAcceptedPayment $finalizeAcceptedPayment,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
        private readonly RecordInventoryFinalizationFailure $recordInventoryFinalizationFailure,
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

        if ($result->success) {
            try {
                $this->finalizeAcceptedPayment->execute(
                    $payment,
                    $attempt,
                    $result->amount->minorAmount,
                    $result->amount->currency,
                    $result->externalId,
                    $result->metadata,
                );
                $attempt->update(['status' => 'succeeded', 'external_id' => $result->externalId]);
            } catch (\Throwable $e) {
                $attempt->update([
                    'status' => 'unknown',
                    'error_code' => 'finalize_failed',
                    'error_message' => $e->getMessage(),
                ]);
                $this->recordInventoryFinalizationFailure->execute($payment, $attempt, $e->getMessage());

                throw $e;
            }
        } else {
            $attempt->update([
                'status' => 'failed',
                'error_code' => $result->failure?->code,
                'error_message' => $result->failure?->message,
            ]);
            $this->recalculateOrderPaymentStatus->execute($payment->order);
        }

        return $result;
    }
}
