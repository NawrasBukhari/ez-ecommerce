<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReconcileVoidAttempt
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
    ) {
    }

    public function confirmProviderSucceeded(PaymentAttempt $attempt, string $externalId): Payment
    {
        if ($attempt->operation !== 'void' || $attempt->status !== 'unknown') {
            throw new RuntimeException('Attempt is not an unknown void.');
        }

        $attempt->loadMissing('payment');
        $payment = $attempt->payment;
        if ($payment === null) {
            throw new RuntimeException("Void attempt [{$attempt->id}] is missing its payment.");
        }

        return DB::transaction(function () use ($payment, $attempt, $externalId) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $existingTransaction = PaymentTransaction::query()
                ->where('payment_id', $locked->id)
                ->where('type', PaymentTransactionType::Void)
                ->where('external_id', $externalId)
                ->exists();

            if (! $existingTransaction) {
                // insertOrIgnore avoids PostgreSQL's transaction-poisoning on a
                // unique violation; a concurrent reconcile is silently skipped.
                DB::table('commerce_payment_transactions')->insertOrIgnore([
                    'payment_id' => $locked->id,
                    'attempt_id' => $attempt->id,
                    'type' => PaymentTransactionType::Void->value,
                    'amount_minor' => $locked->amount_minor,
                    'currency' => $locked->currency,
                    'external_id' => $externalId,
                    'status' => 'succeeded',
                    'processed_at' => $this->clock->now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $locked->update(['status' => PaymentStatus::Cancelled]);
            $attempt->update(['status' => 'succeeded', 'external_id' => $externalId]);

            $this->recalculateOrderPaymentStatus->execute($locked->order);

            return $locked->fresh();
        });
    }

    public function markFailed(PaymentAttempt $attempt): Payment
    {
        if ($attempt->operation !== 'void' || $attempt->status !== 'unknown') {
            throw new RuntimeException('Attempt is not an unknown void.');
        }

        $attempt->loadMissing('payment');
        $payment = $attempt->payment;
        if ($payment === null) {
            throw new RuntimeException("Void attempt [{$attempt->id}] is missing its payment.");
        }

        $attempt->update([
            'status' => 'failed',
            'error_code' => 'void_reconciled_failed',
            'error_message' => 'Operator confirmed the void did not succeed at the provider.',
        ]);

        return $payment->fresh();
    }
}
