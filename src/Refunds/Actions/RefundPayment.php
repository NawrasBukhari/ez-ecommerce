<?php

namespace EzEcommerce\Refunds\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Core\Enums\RefundStatus;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Contracts\PaymentGateway;
use EzEcommerce\Payments\Data\RefundPaymentData;
use EzEcommerce\Payments\Drivers\FakePaymentGateway;
use EzEcommerce\Payments\Drivers\ManualPaymentGateway;
use EzEcommerce\Payments\Drivers\NullPaymentGateway;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use EzEcommerce\Refunds\Models\Refund;
use InvalidArgumentException;

final class RefundPayment
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
    ) {}

    public function execute(
        Payment $payment,
        Money $amount,
        ?string $reason = null,
        string $idempotencyKey = '',
    ): Refund {
        $refund = Refund::query()->create([
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'amount_minor' => $amount->minorAmount,
            'currency' => $amount->currency,
            'status' => RefundStatus::Pending,
            'reason' => $reason,
        ]);

        $attempt = PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'operation' => 'refund',
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : $refund->public_id,
            'status' => 'pending',
        ]);

        $gateway = $this->resolveGateway($payment->gateway);
        $result = $gateway->refund(new RefundPaymentData($payment, $refund, $attempt, $amount));

        if ($result->success) {
            PaymentTransaction::query()->create([
                'payment_id' => $payment->id,
                'attempt_id' => $attempt->id,
                'type' => PaymentTransactionType::Refund,
                'amount_minor' => $result->amount->minorAmount,
                'currency' => $result->amount->currency,
                'external_id' => $result->externalId,
                'status' => 'succeeded',
                'processed_at' => $this->clock->now(),
                'metadata' => $result->metadata,
            ]);

            $refundedMinor = $payment->refunded_minor + $result->amount->minorAmount;
            $paymentStatus = $refundedMinor >= $payment->captured_minor
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded;

            $payment->update([
                'refunded_minor' => $refundedMinor,
                'status' => $paymentStatus,
            ]);

            $order = $payment->order;
            $order->update(['refunded_total_minor' => $order->refunded_total_minor + $result->amount->minorAmount]);

            $refund->update([
                'status' => RefundStatus::Succeeded,
                'external_id' => $result->externalId,
            ]);
            $attempt->update(['status' => 'succeeded', 'external_id' => $result->externalId]);
        } else {
            $refund->update(['status' => RefundStatus::Failed]);
            $attempt->update([
                'status' => 'failed',
                'error_code' => $result->failure?->code,
                'error_message' => $result->failure?->message,
            ]);
        }

        $this->recalculateOrderPaymentStatus->execute($payment->order);

        return $refund->fresh();
    }

    private function resolveGateway(string $name): PaymentGateway
    {
        return match ($name) {
            'null' => app(NullPaymentGateway::class),
            'manual' => app(ManualPaymentGateway::class),
            'fake' => app(FakePaymentGateway::class),
            default => throw new InvalidArgumentException("Unknown payment gateway [{$name}]."),
        };
    }
}
