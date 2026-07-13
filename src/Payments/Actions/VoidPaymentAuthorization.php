<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Data\VoidPaymentData;
use EzEcommerce\Payments\Exceptions\PaymentOperationNotSupported;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use EzEcommerce\Payments\PaymentGatewayRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
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
    ) {
    }

    public function execute(Payment $payment, string $idempotencyKey = ''): Payment
    {
        $voidableStates = [
            PaymentStatus::Authorized,
            PaymentStatus::RequiresAction,
            PaymentStatus::Pending,
        ];

        $attempt = DB::transaction(function () use ($payment, $idempotencyKey, $voidableStates) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! in_array($locked->status, $voidableStates, true)) {
                throw new RuntimeException('Only authorized, pending, or requires-action payments can be voided.');
            }

            $gateway = $this->gateways->for($locked->gateway);
            if (! $gateway->capabilities()->void) {
                throw PaymentOperationNotSupported::for($locked->gateway, 'void');
            }

            $inFlightVoid = PaymentAttempt::query()
                ->where('payment_id', $locked->id)
                ->where('operation', 'void')
                ->whereIn('status', ['pending', 'unknown'])
                ->exists();

            if ($inFlightVoid) {
                throw new RuntimeException('A void is in progress or requires reconciliation for this payment.');
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
                ],
            ]);
        });

        try {
            $result = $this->gateways->for($payment->gateway)->void(new VoidPaymentData(
                payment: $payment,
                attempt: $attempt,
                amount: Money::fromMinor($payment->amount_minor, $payment->currency),
                providerReference: $this->providerReference->forCapture($payment),
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
                try {
                    PaymentTransaction::query()->create([
                        'payment_id' => $locked->id,
                        'attempt_id' => $attempt->id,
                        'type' => PaymentTransactionType::Void,
                        'amount_minor' => $result->amount->minorAmount,
                        'currency' => $result->amount->currency,
                        'external_id' => $result->externalId,
                        'status' => 'succeeded',
                        'processed_at' => $this->clock->now(),
                        'metadata' => $result->metadata,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Already voided by a concurrent request; continue idempotently.
                }
            }

            $locked->update(['status' => PaymentStatus::Cancelled]);
            $attempt->update(['status' => 'succeeded', 'external_id' => $result->externalId]);

            $this->recalculateOrderPaymentStatus->execute($locked->order);

            return $locked->fresh();
        });

        return $payment;
    }
}
