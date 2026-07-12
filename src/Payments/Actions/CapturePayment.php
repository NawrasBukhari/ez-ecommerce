<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Money\Money;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Data\CapturePaymentData;
use EzEcommerce\Payments\Data\PaymentResult;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\PaymentGatewayRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class CapturePayment
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly FinalizeAcceptedPayment $finalizeAcceptedPayment,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
    ) {}

    public function execute(Payment $payment, PaymentAttempt $attempt, ?Money $amount = null): PaymentResult
    {
        ['payment' => $payment, 'amount' => $amount] = DB::transaction(function () use ($payment, $amount, $attempt) {
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
                ->where('id', '!=', $attempt->id)
                ->whereIn('status', ['pending', 'unknown'])
                ->exists();

            if ($inFlightCapture) {
                throw new RuntimeException('A capture is in progress or requires reconciliation for this payment.');
            }

            $captureIdempotencyKey = $attempt->idempotency_key !== '' && $attempt->idempotency_key !== null
                ? $attempt->idempotency_key
                : "capture:{$attempt->id}";

            $attempt->update([
                'operation' => 'capture',
                'status' => 'pending',
                'idempotency_key' => $captureIdempotencyKey,
            ]);

            return ['payment' => $locked, 'amount' => $captureAmount];
        });

        $gateway = $this->gateways->for($payment->gateway);

        try {
            $result = $gateway->capture(new CapturePaymentData($payment, $attempt, $amount));
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
            } catch (\Throwable $e) {
                $attempt->update([
                    'status' => 'unknown',
                    'error_code' => 'finalize_failed',
                    'error_message' => $e->getMessage(),
                ]);

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
