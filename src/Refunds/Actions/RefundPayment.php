<?php

namespace EzEcommerce\Refunds\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Core\Enums\RefundStatus;
use EzEcommerce\Core\Events\Concerns\DispatchesCommerceWebhooks;
use EzEcommerce\Core\Exceptions\IdempotencyPayloadMismatchException;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Core\Support\CanonicalJson;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Actions\ResolveProviderPaymentReference;
use EzEcommerce\Payments\Data\RefundPaymentData;
use EzEcommerce\Payments\Data\RefundResult;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use EzEcommerce\Payments\PaymentGatewayRegistry;
use EzEcommerce\Refunds\Models\Refund;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class RefundPayment
{
    use DispatchesCommerceWebhooks;

    public function __construct(
        private readonly Clock $clock,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly ResolveProviderPaymentReference $providerReference,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
    ) {
    }

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
            $payloadHash = $this->refundPayloadHash($payment, $amount, $reason);

            $existingAttempt = PaymentAttempt::query()
                ->where('payment_id', $payment->id)
                ->where('idempotency_key', $idempotencyKey)
                ->where('operation', 'refund')
                ->first();

            if ($existingAttempt !== null) {
                $metadata = $this->requestMetadata($existingAttempt);
                $storedHash = $metadata['payload_hash'] ?? null;

                if ($storedHash !== null && $storedHash !== $payloadHash) {
                    throw IdempotencyPayloadMismatchException::for('refund', $idempotencyKey);
                }

                $refundId = $metadata['refund_id'] ?? null;

                if ($refundId !== null) {
                    $refund = Refund::query()->find((int) $refundId);
                    if ($refund !== null) {
                        return match ($existingAttempt->status) {
                            'succeeded', 'failed' => $refund,
                            'pending' => throw new RuntimeException('Refund with this idempotency key is already in progress.'),
                            'unknown' => throw new RuntimeException('Refund with this idempotency key requires reconciliation.'),
                            default => $refund,
                        };
                    }
                }
            }
        }

        ['refund' => $refund, 'attempt' => $attempt] = DB::transaction(function () use ($payment, $amount, $reason, $idempotencyKey) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $payloadHash = $this->refundPayloadHash($locked, $amount, $reason);

            $pendingAttempt = PaymentAttempt::query()
                ->where('payment_id', $locked->id)
                ->where('operation', 'refund')
                ->whereIn('status', ['pending', 'unknown'])
                ->exists();

            if ($pendingAttempt) {
                throw new RuntimeException('A refund is in progress or requires reconciliation for this payment.');
            }

            $pendingRefundMinor = (int) Refund::query()
                ->where('payment_id', $locked->id)
                ->where('status', RefundStatus::Pending)
                ->sum('amount_minor');

            $refundable = $locked->captured_minor - $locked->refunded_minor - $pendingRefundMinor;

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
                'request_metadata' => [
                    'refund_id' => $refund->id,
                    'payload_hash' => $payloadHash,
                    'requested_amount_minor' => $amount->minorAmount,
                    'currency' => $amount->currency,
                    'provider_operation' => 'refund',
                ],
            ]);

            return ['refund' => $refund, 'attempt' => $attempt, 'payment' => $locked];
        });

        $payment = $payment->fresh();

        try {
            $result = $this->gateways->for($payment->gateway)->refund(
                new RefundPaymentData(
                    payment: $payment,
                    refund: $refund,
                    attempt: $attempt,
                    amount: $amount,
                    providerReference: $this->providerReference->forRefund($payment),
                ),
            );
        } catch (\Throwable $e) {
            $attempt->update([
                'status' => 'unknown',
                'error_code' => 'refund_exception',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        return DB::transaction(function () use ($payment, $refund, $attempt, $result) {
            return $this->finalizeRefundAttempt($payment, $refund, $attempt, $result);
        });
    }

    public function finalizeProviderRefund(
        Payment $payment,
        Refund $refund,
        PaymentAttempt $attempt,
        RefundResult $result,
    ): Refund {
        return DB::transaction(function () use ($payment, $refund, $attempt, $result) {
            return $this->finalizeRefundAttempt($payment, $refund, $attempt, $result);
        });
    }

    private function finalizeRefundAttempt(Payment $payment, Refund $refund, PaymentAttempt $attempt, RefundResult $result): Refund
    {
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

        $refundable = $locked->captured_minor - $locked->refunded_minor - (int) Refund::query()
            ->where('payment_id', $locked->id)
            ->where('status', RefundStatus::Pending)
            ->where('id', '!=', $refund->id)
            ->sum('amount_minor');
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
    }

    public function retryUnknown(PaymentAttempt $attempt): Refund
    {
        if ($attempt->operation !== 'refund' || $attempt->status !== 'unknown') {
            throw new RuntimeException('Refund attempt is not in unknown state.');
        }

        $refundId = $this->requestMetadata($attempt)['refund_id'] ?? null;

        if ($refundId === null) {
            throw new RuntimeException('Unknown refund attempt is missing refund_id metadata.');
        }

        $refund = Refund::query()->findOrFail((int) $refundId);
        $attempt->loadMissing('payment');
        $payment = $attempt->payment;
        if ($payment === null) {
            throw new RuntimeException("Refund attempt [{$attempt->id}] is missing its payment.");
        }
        $amount = Money::fromMinor($refund->amount_minor, $refund->currency);

        $attempt->update(['status' => 'pending']);

        try {
            $result = $this->gateways->for($payment->gateway)->refund(
                new RefundPaymentData(
                    payment: $payment,
                    refund: $refund,
                    attempt: $attempt,
                    amount: $amount,
                    providerReference: $this->providerReference->forRefund($payment),
                ),
            );
        } catch (\Throwable $e) {
            $attempt->update([
                'status' => 'unknown',
                'error_code' => 'refund_exception',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        return DB::transaction(function () use ($payment, $refund, $attempt, $result) {
            return $this->finalizeRefundAttempt($payment, $refund, $attempt, $result);
        });
    }

    private function refundPayloadHash(Payment $payment, Money $amount, ?string $reason): string
    {
        return hash('sha256', CanonicalJson::encode([
            'payment_id' => $payment->id,
            'amount_minor' => $amount->minorAmount,
            'currency' => $amount->currency,
            'reason' => $reason,
        ]));
    }

    /** @return array<string, mixed> */
    private function requestMetadata(PaymentAttempt $attempt): array
    {
        return $attempt->request_metadata instanceof \ArrayObject
            ? $attempt->request_metadata->getArrayCopy()
            : (array) ($attempt->request_metadata ?? []);
    }
}
