<?php

namespace EzEcommerce\Refunds\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Core\Enums\RefundStatus;
use EzEcommerce\Core\Events\Concerns\DispatchesCommerceWebhooks;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Data\RefundPaymentData;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use EzEcommerce\Payments\PaymentGatewayRegistry;
use EzEcommerce\Refunds\Models\Refund;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RefundPayment
{
    use DispatchesCommerceWebhooks;

    public function __construct(
        private readonly Clock $clock,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
    ) {}

    public function execute(
        Payment $payment,
        Money $amount,
        ?string $reason = null,
        string $idempotencyKey = '',
    ): Refund {
        if ($amount->minorAmount <= 0) {
            throw new InvalidArgumentException('Refund amount must be positive.');
        }

        if ($amount->currency !== $payment->currency) {
            throw new InvalidArgumentException('Refund currency does not match payment currency.');
        }

        if ($idempotencyKey !== '') {
            $existingAttempt = PaymentAttempt::query()
                ->where('payment_id', $payment->id)
                ->where('idempotency_key', $idempotencyKey)
                ->where('operation', 'refund')
                ->first();

            if ($existingAttempt !== null) {
                if ($existingAttempt->status === 'succeeded') {
                    $refundId = $existingAttempt->request_metadata instanceof \ArrayObject
                        ? $existingAttempt->request_metadata['refund_id'] ?? null
                        : ($existingAttempt->request_metadata['refund_id'] ?? null);

                    if ($refundId !== null) {
                        return Refund::query()->findOrFail((int) $refundId);
                    }
                }
            }
        }

        ['refund' => $refund, 'attempt' => $attempt] = DB::transaction(function () use ($payment, $amount, $reason, $idempotencyKey) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $refundable = $locked->captured_minor - $locked->refunded_minor;

            if ($amount->minorAmount > $refundable) {
                throw new InvalidArgumentException("Refund amount [{$amount->minorAmount}] exceeds refundable [{$refundable}].");
            }

            $refund = Refund::query()->create([
                'payment_id' => $locked->id,
                'order_id' => $locked->order_id,
                'amount_minor' => $amount->minorAmount,
                'currency' => $amount->currency,
                'status' => RefundStatus::Pending,
                'reason' => $reason,
            ]);

            $attempt = PaymentAttempt::query()->create([
                'payment_id' => $locked->id,
                'operation' => 'refund',
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : $refund->public_id,
                'status' => 'pending',
                'request_metadata' => ['refund_id' => $refund->id],
            ]);

            return ['refund' => $refund, 'attempt' => $attempt, 'payment' => $locked];
        });

        $payment = $payment->fresh();
        $result = $this->gateways->for($payment->gateway)->refund(
            new RefundPaymentData($payment, $refund, $attempt, $amount),
        );

        return DB::transaction(function () use ($payment, $refund, $attempt, $result) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! $result->success) {
                $refund->update(['status' => RefundStatus::Failed]);
                $attempt->update([
                    'status' => 'failed',
                    'error_code' => $result->failure?->code,
                    'error_message' => $result->failure?->message,
                ]);
                $this->recalculateOrderPaymentStatus->execute($locked->order);

                return $refund->fresh();
            }

            $refundable = $locked->captured_minor - $locked->refunded_minor;
            if ($result->amount->minorAmount > $refundable) {
                $refund->update(['status' => RefundStatus::Failed]);
                $attempt->update([
                    'status' => 'failed',
                    'error_code' => 'refund_exceeds_balance',
                    'error_message' => "Refund amount [{$result->amount->minorAmount}] exceeds refundable [{$refundable}].",
                ]);
                $this->recalculateOrderPaymentStatus->execute($locked->order);

                return $refund->fresh();
            }

            PaymentTransaction::query()->create([
                'payment_id' => $locked->id,
                'attempt_id' => $attempt->id,
                'type' => PaymentTransactionType::Refund,
                'amount_minor' => $result->amount->minorAmount,
                'currency' => $result->amount->currency,
                'external_id' => $result->externalId,
                'status' => 'succeeded',
                'processed_at' => $this->clock->now(),
                'metadata' => $result->metadata,
            ]);

            $refundedMinor = (int) PaymentTransaction::query()
                ->where('payment_id', $locked->id)
                ->where('type', PaymentTransactionType::Refund)
                ->where('status', 'succeeded')
                ->sum('amount_minor');

            $paymentStatus = $refundedMinor >= $locked->captured_minor
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded;

            $locked->update([
                'refunded_minor' => $refundedMinor,
                'status' => $paymentStatus,
            ]);

            $order = $locked->order;
            $order->update(['refunded_total_minor' => $refundedMinor]);

            $refund->update([
                'status' => RefundStatus::Succeeded,
                'external_id' => $result->externalId,
            ]);
            $attempt->update(['status' => 'succeeded', 'external_id' => $result->externalId]);

            $this->dispatchCommerceWebhook('refund.created', [
                'refund_id' => $refund->public_id,
                'order_id' => $order->public_id,
                'amount_minor' => $refund->amount_minor,
            ]);

            $this->recalculateOrderPaymentStatus->execute($order);

            return $refund->fresh();
        });
    }
}
