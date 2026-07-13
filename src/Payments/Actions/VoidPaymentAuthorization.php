<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Core\Exceptions\IdempotencyPayloadMismatchException;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Core\Support\CanonicalJson;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Contracts\PaymentOperationPolicy;
use EzEcommerce\Payments\Data\VoidPaymentData;
use EzEcommerce\Payments\Exceptions\PaymentOperationNotSupported;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use EzEcommerce\Payments\PaymentGatewayRegistry;
use EzEcommerce\Payments\Support\PaymentAttemptRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class VoidPaymentAuthorization
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly ResolveProviderPaymentReference $providerReference,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
        private readonly Clock $clock,
        private readonly PaymentOperationPolicy $paymentOperationPolicy,
        private readonly AssertNoConflictingPaymentOperation $assertNoConflictingPaymentOperation,
    ) {
    }

    public function execute(Payment $payment, string $idempotencyKey = ''): Payment
    {
        $payment->refresh();

        $providerReference = $this->providerReference->forCapture($payment);
        $payloadHash = $this->voidPayloadHash($payment, $providerReference);

        if ($idempotencyKey !== '') {
            $existing = PaymentAttempt::query()
                ->where('payment_id', $payment->id)
                ->where('idempotency_key', $idempotencyKey)
                ->where('operation', 'void')
                ->first();

            if ($existing !== null) {
                $metadata = PaymentAttemptRequest::metadata($existing);
                $storedHash = $metadata['payload_hash'] ?? null;

                if ($storedHash !== null && $storedHash !== $payloadHash) {
                    throw IdempotencyPayloadMismatchException::for('void', $idempotencyKey);
                }

                return match ($existing->status) {
                    'succeeded' => $payment->fresh(),
                    'pending' => throw new RuntimeException('Void with this idempotency key is already in progress.'),
                    'unknown' => throw new RuntimeException('Void with this idempotency key requires reconciliation.'),
                    // failed is terminal: cache and rethrow the original failure
                    // without creating a second row (avoids the UNIQUE(payment_id,
                    // idempotency_key) duplicate-key error on retries).
                    'failed' => throw new RuntimeException(
                        'Void with this idempotency key previously failed terminally: '
                        .($existing->error_message ?? 'void_failed')
                        .'. Use a new idempotency key to attempt a fresh void.',
                    ),
                    // failed_retryable reuses the existing attempt + provider key.
                    'failed_retryable' => $this->reuseAttempt($payment, $existing, $providerReference),
                    default => throw new RuntimeException('Void attempt in unexpected state: '.$existing->status),
                };
            }
        }

        $attempt = DB::transaction(function () use ($payment, $idempotencyKey, $payloadHash) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! $this->paymentOperationPolicy->canVoid($locked)) {
                throw new RuntimeException('Only authorized, pending, or requires-action payments can be voided.');
            }

            $this->assertNoConflictingPaymentOperation->execute($locked, 'void');

            $gateway = $this->gateways->for($locked->gateway);
            if (! $gateway->capabilities()->void) {
                throw PaymentOperationNotSupported::for($locked->gateway, 'void');
            }

            return PaymentAttempt::query()->create([
                'payment_id' => $locked->id,
                'operation' => 'void',
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : 'void:'.$locked->public_id.':'.Str::uuid(),
                'status' => 'pending',
                'request_metadata' => [
                    'provider_operation' => 'void',
                    'requested_amount_minor' => $locked->amount_minor,
                    'currency' => $locked->currency,
                    'payload_hash' => $payloadHash,
                ],
            ]);
        });

        return $this->executeVoid($payment, $attempt, $providerReference);
    }

    private function reuseAttempt(Payment $payment, PaymentAttempt $attempt, ?string $providerReference): Payment
    {
        DB::transaction(function () use ($payment, $attempt) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! $this->paymentOperationPolicy->canVoid($locked)) {
                throw new RuntimeException('Only authorized, pending, or requires-action payments can be voided.');
            }

            $this->assertNoConflictingPaymentOperation->execute($locked, 'void');

            $attempt->update([
                'status' => 'pending',
                'error_code' => null,
                'error_message' => null,
            ]);
        });

        return $this->executeVoid($payment, $attempt, $providerReference);
    }

    private function executeVoid(Payment $payment, PaymentAttempt $attempt, ?string $providerReference): Payment
    {
        try {
            $result = $this->gateways->for($payment->gateway)->void(new VoidPaymentData(
                payment: $payment,
                attempt: $attempt,
                amount: Money::fromMinor($payment->amount_minor, $payment->currency),
                providerReference: $providerReference,
            ));
        } catch (\Throwable $e) {
            $attempt->update([
                'status' => 'unknown',
                'error_code' => 'void_exception',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        if (! $result->success) {
            $failure = $result->failure;
            $errorCode = $failure !== null ? $failure->code : 'void_failed';
            $errorMessage = $failure !== null ? $failure->message : 'The payment authorization could not be voided.';
            $attempt->update([
                'status' => 'failed',
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);
            $this->recalculateOrderPaymentStatus->execute($payment->order);

            throw new RuntimeException('Void failed: '.$errorMessage);
        }

        $payment = DB::transaction(function () use ($payment, $attempt, $result) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $existingTransaction = $result->externalId !== null
                ? PaymentTransaction::query()
                    ->where('payment_id', $locked->id)
                    ->where('type', PaymentTransactionType::Void)
                    ->where('external_id', $result->externalId)
                    ->exists()
                : false;

            if (! $existingTransaction) {
                // insertOrIgnore avoids PostgreSQL's transaction-poisoning on a
                // unique violation; a concurrent void with the same external_id
                // is silently skipped, which is the intended idempotent behavior.
                DB::table('commerce_payment_transactions')->insertOrIgnore([
                    'payment_id' => $locked->id,
                    'attempt_id' => $attempt->id,
                    'type' => PaymentTransactionType::Void->value,
                    'amount_minor' => $result->amount->minorAmount,
                    'currency' => $result->amount->currency,
                    'external_id' => $result->externalId,
                    'status' => 'succeeded',
                    'processed_at' => $this->clock->now(),
                    'metadata' => json_encode($result->metadata, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $locked->update(['status' => PaymentStatus::Cancelled]);
            $attempt->update(['status' => 'succeeded', 'external_id' => $result->externalId]);

            $this->recalculateOrderPaymentStatus->execute($locked->order);

            return $locked->fresh();
        });

        return $payment;
    }

    private function voidPayloadHash(Payment $payment, ?string $providerReference): string
    {
        return hash('sha256', CanonicalJson::encode([
            'payment_id' => $payment->id,
            'provider_reference' => $providerReference,
            'requested_amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'operation' => 'void',
        ]));
    }
}
