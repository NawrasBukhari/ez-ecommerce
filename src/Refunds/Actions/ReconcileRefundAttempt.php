<?php

namespace EzEcommerce\Refunds\Actions;

use EzEcommerce\Core\Enums\RefundStatus;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Payments\Data\RefundResult;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Refunds\Models\Refund;
use RuntimeException;

final class ReconcileRefundAttempt
{
    public function __construct(
        private readonly RefundPayment $refundPayment,
    ) {
    }

    public function confirmProviderSucceeded(PaymentAttempt $attempt, string $externalId): Refund
    {
        if ($attempt->operation !== 'refund' || $attempt->status !== 'unknown') {
            throw new RuntimeException('Attempt is not an unknown refund.');
        }

        $refund = $this->refundFromAttempt($attempt);
        $payment = $attempt->payment;
        if ($payment === null) {
            throw new RuntimeException("Refund attempt [{$attempt->id}] is missing its payment.");
        }

        return $this->refundPayment->finalizeProviderRefund(
            $payment,
            $refund,
            $attempt,
            new RefundResult(
                success: true,
                status: RefundStatus::Succeeded,
                amount: Money::fromMinor($refund->amount_minor, $refund->currency),
                externalId: $externalId,
            ),
        );
    }

    public function retry(PaymentAttempt $attempt): Refund
    {
        return $this->refundPayment->retryUnknown($attempt);
    }

    private function refundFromAttempt(PaymentAttempt $attempt): Refund
    {
        $metadata = $attempt->request_metadata instanceof \ArrayObject
            ? $attempt->request_metadata->getArrayCopy()
            : (array) ($attempt->request_metadata ?? []);
        $refundId = $metadata['refund_id'] ?? null;

        if ($refundId === null) {
            throw new RuntimeException("Refund attempt [{$attempt->id}] is missing refund_id metadata.");
        }

        return Refund::query()->findOrFail((int) $refundId);
    }
}
