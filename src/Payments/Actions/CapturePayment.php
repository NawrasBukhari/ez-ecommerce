<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Core\Events\OrderPaid;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Inventory\Actions\CommitReservation;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Contracts\PaymentGateway;
use EzEcommerce\Payments\Data\CapturePaymentData;
use EzEcommerce\Payments\Data\PaymentResult;
use EzEcommerce\Payments\Drivers\FakePaymentGateway;
use EzEcommerce\Payments\Drivers\ManualPaymentGateway;
use EzEcommerce\Payments\Drivers\NullPaymentGateway;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

final class CapturePayment
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
        private readonly CommitReservation $commitReservation,
    ) {}

    public function execute(Payment $payment, PaymentAttempt $attempt, ?Money $amount = null): PaymentResult
    {
        $amount ??= Money::fromMinor(
            $payment->amount_minor - $payment->captured_minor,
            $payment->currency,
        );

        $gateway = $this->resolveGateway($payment->gateway);
        $result = $gateway->capture(new CapturePaymentData($payment, $attempt, $amount));

        if ($result->success) {
            PaymentTransaction::query()->create([
                'payment_id' => $payment->id,
                'attempt_id' => $attempt->id,
                'type' => PaymentTransactionType::Capture,
                'amount_minor' => $result->amount->minorAmount,
                'currency' => $result->amount->currency,
                'external_id' => $result->externalId,
                'status' => 'succeeded',
                'processed_at' => $this->clock->now(),
                'metadata' => $result->metadata,
            ]);

            $capturedMinor = $payment->captured_minor + $result->amount->minorAmount;
            $status = $capturedMinor >= $payment->amount_minor
                ? PaymentStatus::Captured
                : PaymentStatus::PartiallyCaptured;

            $payment->update([
                'status' => $status,
                'captured_minor' => $capturedMinor,
            ]);

            $attempt->update(['status' => $status->value, 'external_id' => $result->externalId]);

            $order = $payment->order;
            $this->recalculateOrderPaymentStatus->execute($order);
            $this->commitReservation->executeForOrder($order);

            if ($status === PaymentStatus::Captured) {
                Event::dispatch(new OrderPaid($order->id, $order->public_id, $payment->id));
            }
        } else {
            $attempt->update([
                'status' => 'failed',
                'error_code' => $result->failure?->code,
                'error_message' => $result->failure?->message,
            ]);
            $payment->update(['status' => PaymentStatus::Failed]);
            $this->recalculateOrderPaymentStatus->execute($payment->order);
        }

        return $result;
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
