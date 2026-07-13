<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Records a provider capture reversal (e.g. PayPal PAYMENT.CAPTURE.REVERSED).
 *
 * A reversal is a first-class, append-only ledger event distinct from a refund:
 * the provider clawed back a captured payment (chargeback/Reversal), not a
 * merchant-initiated refund. The captured ledger row is preserved (append-only),
 * a single Reversal transaction is appended, the payment moves to Reversed, and
 * the order payment status becomes Disputed with manual-review metadata.
 *
 * No inventory restock, fulfillment cancel, or refund is generated. The
 * transition is monotonic and idempotent: a duplicate reversal webhook must not
 * append a second reversal transaction.
 */
final class ApplyPaymentReversal
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
    ) {
    }

    public function execute(Payment $payment, ?string $externalId, int $amountMinor, string $currency, array $metadata = []): Payment
    {
        return DB::transaction(function () use ($payment, $externalId, $amountMinor, $currency, $metadata) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            // Idempotent: a repeated reversal webhook for the same provider
            // transaction reference must not append a second reversal row.
            if ($externalId !== null && PaymentTransaction::query()
                ->where('payment_id', $locked->id)
                ->where('type', PaymentTransactionType::Reversal)
                ->where('external_id', $externalId)
                ->exists()) {
                return $locked->fresh();
            }

            // Monotonic: only Captured/PartiallyCaptured/Reversed payments can be
            // reversed. A reversal arriving for a non-captured payment is a no-op
            // (the capture completion webhook likely has not arrived yet; the
            // reversal is retained for replay via the inbox record).
            if (! in_array($locked->status, [
                PaymentStatus::Captured,
                PaymentStatus::PartiallyCaptured,
                PaymentStatus::Reversed,
            ], true)) {
                return $locked->fresh();
            }

            if ($externalId !== null) {
                PaymentTransaction::query()->create([
                    'payment_id' => $locked->id,
                    'type' => PaymentTransactionType::Reversal,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'external_id' => $externalId,
                    'status' => 'succeeded',
                    'processed_at' => $this->clock->now(),
                    'metadata' => $metadata,
                ]);
            }

            $locked->update(['status' => PaymentStatus::Reversed]);

            $order = $locked->order;
            if ($order !== null) {
                $orderMetadata = $order->metadata instanceof \ArrayObject
                    ? $order->metadata->getArrayCopy()
                    : (array) ($order->metadata ?? []);
                $orderMetadata['manual_review_required'] = 'payment_reversal';
                if ($externalId !== null) {
                    $orderMetadata['payment_reversal_reference'] = $externalId;
                }
                $order->update(['metadata' => $orderMetadata]);

                $this->recalculateOrderPaymentStatus->execute($order);
            }

            return $locked->fresh();
        });
    }
}
