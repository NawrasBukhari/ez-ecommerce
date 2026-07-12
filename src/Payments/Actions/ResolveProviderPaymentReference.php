<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;

final class ResolveProviderPaymentReference
{
    public function forCapture(Payment $payment): ?string
    {
        $sessionAttempt = PaymentAttempt::query()
            ->where('payment_id', $payment->id)
            ->where('operation', 'create_session')
            ->whereNotNull('external_id')
            ->orderByDesc('id')
            ->first();

        return $sessionAttempt?->external_id;
    }

    public function forRefund(Payment $payment): ?string
    {
        $captureTransaction = PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Capture)
            ->where('status', 'succeeded')
            ->whereNotNull('external_id')
            ->orderByDesc('id')
            ->first();

        if ($captureTransaction !== null) {
            return $captureTransaction->external_id;
        }

        return $this->forCapture($payment);
    }
}
