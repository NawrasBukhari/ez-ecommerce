<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Events\OrderPaid;
use EzEcommerce\Inventory\Actions\CommitReservation;
use EzEcommerce\Inventory\Exceptions\InventoryCommitException;
use EzEcommerce\Inventory\Exceptions\ReservationExpiredException;
use EzEcommerce\Orders\Actions\ConfirmOrderOnPaymentAccepted;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use Illuminate\Support\Facades\Event;

final class FinalizeAcceptedPayment
{
    public function __construct(
        private readonly ApplyPaymentCapture $applyPaymentCapture,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
        private readonly ConfirmOrderOnPaymentAccepted $confirmOrderOnPaymentAccepted,
        private readonly CommitReservation $commitReservation,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     *
     * @throws ReservationExpiredException
     */
    public function execute(
        Payment $payment,
        ?PaymentAttempt $attempt,
        int $amountMinor,
        string $currency,
        ?string $externalId = null,
        array $metadata = [],
    ): Payment {
        $payment->refresh();
        $wasFullyCaptured = $payment->status === PaymentStatus::Captured;

        $payment = $this->applyPaymentCapture->execute(
            $payment,
            $attempt,
            $amountMinor,
            $currency,
            $externalId,
            $metadata,
        );

        $this->recalculateOrderPaymentStatus->execute($payment->order);
        $this->completeOrderAfterCapture($payment, $wasFullyCaptured);

        return $payment->fresh();
    }

    /** @throws ReservationExpiredException|InventoryCommitException */
    public function completeOrderAfterCapture(Payment $payment, bool $wasFullyCaptured = false): void
    {
        $payment->refresh();
        $order = $payment->order;

        if ($payment->status === PaymentStatus::Captured) {
            $this->commitReservation->executeForOrder($order);
            $this->confirmOrderOnPaymentAccepted->execute($order);

            if (! $wasFullyCaptured) {
                Event::dispatch(new OrderPaid($order->id, $order->public_id, $payment->id));
            }

            return;
        }

        if ($payment->status === PaymentStatus::PartiallyCaptured) {
            $this->confirmOrderOnPaymentAccepted->execute($order);
        }
    }
}
