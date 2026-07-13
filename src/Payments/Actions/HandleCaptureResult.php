<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Data\PaymentResult;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;

/**
 * Centralizes capture-result interpretation so the gateway PaymentStatus
 * (not a bare success flag) drives finalization. A provider-accepted but
 * pending capture (e.g. PayPal PENDING) must not append a capture ledger,
 * commit inventory, confirm the order, or enqueue order.paid.
 */
final class HandleCaptureResult
{
    public function __construct(
        private readonly FinalizeAcceptedPayment $finalizeAcceptedPayment,
        private readonly ApplyPaymentCapture $applyPaymentCapture,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
        private readonly RecordInventoryFinalizationFailure $recordInventoryFinalizationFailure,
    ) {
    }

    public function execute(Payment $payment, PaymentAttempt $attempt, PaymentResult $result): Payment
    {
        return match ($result->status) {
            PaymentStatus::Captured => $this->handleCaptured($payment, $attempt, $result),
            PaymentStatus::PartiallyCaptured => $this->handlePartiallyCaptured($payment, $attempt, $result),
            PaymentStatus::Pending => $this->handlePending($payment, $attempt, $result),
            default => $this->handleFailed($payment, $attempt, $result),
        };
    }

    private function handleCaptured(Payment $payment, PaymentAttempt $attempt, PaymentResult $result): Payment
    {
        try {
            $payment = $this->finalizeAcceptedPayment->execute(
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

        return $payment;
    }

    private function handlePartiallyCaptured(Payment $payment, PaymentAttempt $attempt, PaymentResult $result): Payment
    {
        // Apply only the actually captured amount; preserve the remaining authorization.
        // No inventory commit, no order.paid — those wait for a full capture.
        $payment = $this->applyPaymentCapture->execute(
            $payment,
            $attempt,
            $result->amount->minorAmount,
            $result->amount->currency,
            $result->externalId,
            $result->metadata,
        );
        $this->recalculateOrderPaymentStatus->execute($payment->order);
        $attempt->update(['status' => 'succeeded', 'external_id' => $result->externalId]);

        return $payment;
    }

    private function handlePending(Payment $payment, PaymentAttempt $attempt, PaymentResult $result): Payment
    {
        $payment->refresh();

        // Keep the payment and attempt pending; store the provider capture reference
        // and response metadata. No capture ledger, no captured_minor change, no
        // inventory commit, no order confirmation, no order.paid. Wait for the
        // provider completion webhook to finalize.
        $attempt->update([
            'status' => 'pending',
            'external_id' => $result->externalId,
            'response_metadata' => $result->metadata,
        ]);

        // Pending is a pre-capture state; never regress a captured/terminal payment
        // from a stale pending result arriving out of order.
        $terminal = [
            PaymentStatus::Captured,
            PaymentStatus::PartiallyCaptured,
            PaymentStatus::Refunded,
            PaymentStatus::PartiallyRefunded,
            PaymentStatus::Cancelled,
            PaymentStatus::Failed,
        ];

        if (! in_array($payment->status, $terminal, true)) {
            $payment->update(['status' => PaymentStatus::Pending]);
        }

        $this->recalculateOrderPaymentStatus->execute($payment->order);

        return $payment->fresh();
    }

    private function handleFailed(Payment $payment, PaymentAttempt $attempt, PaymentResult $result): Payment
    {
        $payment->refresh();

        $attempt->update([
            'status' => 'failed',
            'error_code' => $result->failure?->code,
            'error_message' => $result->failure?->message,
        ]);

        // Derive the aggregate from the existing successful ledger only; a failed
        // follow-up capture must not overwrite a previously partially captured
        // payment as entirely failed. Payment status is left to be derived from the
        // ledger by recalculation / webhook reconciliation, never forced to Failed
        // here when prior captures exist.
        $this->recalculateOrderPaymentStatus->execute($payment->order);

        return $payment->fresh();
    }
}
