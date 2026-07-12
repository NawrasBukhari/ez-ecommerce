<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Enums\PaymentStatus;
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

            $pendingCapture = PaymentAttempt::query()
                ->where('payment_id', $locked->id)
                ->where('operation', 'capture')
                ->where('status', 'pending')
                ->exists();

            if ($pendingCapture) {
                throw new RuntimeException('A capture is already in progress for this payment.');
            }

            $attempt->update(['operation' => 'capture', 'status' => 'pending']);

            return ['payment' => $locked, 'amount' => $captureAmount];
        });

        $gateway = $this->gateways->for($payment->gateway);
        $result = $gateway->capture(new CapturePaymentData($payment, $attempt, $amount));

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
                    'status' => 'failed',
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
            $payment->update(['status' => PaymentStatus::Failed]);
            $this->recalculateOrderPaymentStatus->execute($payment->order);
        }

        return $result;
    }
}
