<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ApplyPaymentCapture
{
    public function __construct(
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        Payment $payment,
        ?PaymentAttempt $attempt,
        int $amountMinor,
        string $currency,
        ?string $externalId,
        array $metadata = [],
    ): Payment {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Capture amount must be positive.');
        }

        return DB::transaction(function () use ($payment, $attempt, $amountMinor, $currency, $externalId, $metadata) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($currency !== $locked->currency) {
                throw new InvalidArgumentException('Capture currency does not match payment currency.');
            }

            $remaining = $locked->amount_minor - $locked->captured_minor;
            if ($amountMinor > $remaining) {
                throw new InvalidArgumentException("Capture amount [{$amountMinor}] exceeds remaining [{$remaining}].");
            }

            if ($externalId !== null && PaymentTransaction::query()
                ->where('payment_id', $locked->id)
                ->where('type', PaymentTransactionType::Capture)
                ->where('external_id', $externalId)
                ->exists()) {
                return $locked->fresh();
            }

            PaymentTransaction::query()->create([
                'payment_id' => $locked->id,
                'attempt_id' => $attempt?->id,
                'type' => PaymentTransactionType::Capture,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'external_id' => $externalId,
                'status' => 'succeeded',
                'processed_at' => $this->clock->now(),
                'metadata' => $metadata,
            ]);

            $capturedMinor = (int) PaymentTransaction::query()
                ->where('payment_id', $locked->id)
                ->where('type', PaymentTransactionType::Capture)
                ->where('status', 'succeeded')
                ->sum('amount_minor');

            $status = $capturedMinor >= $locked->amount_minor
                ? PaymentStatus::Captured
                : PaymentStatus::PartiallyCaptured;

            $locked->update([
                'status' => $status,
                'captured_minor' => $capturedMinor,
            ]);

            if ($attempt !== null) {
                $attempt->update(['status' => $status->value, 'external_id' => $externalId]);
            }

            return $locked->fresh();
        });
    }
}
