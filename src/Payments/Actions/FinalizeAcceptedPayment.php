<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Events\OrderPaid;
use EzEcommerce\Core\Models\OutboxMessage;
use EzEcommerce\Inventory\Actions\CommitReservation;
use EzEcommerce\Inventory\Exceptions\InventoryCommitException;
use EzEcommerce\Inventory\Exceptions\ReservationExpiredException;
use EzEcommerce\Orders\Actions\ConfirmOrderOnPaymentAccepted;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class FinalizeAcceptedPayment
{
    public function __construct(
        private readonly ApplyPaymentCapture $applyPaymentCapture,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
        private readonly ConfirmOrderOnPaymentAccepted $confirmOrderOnPaymentAccepted,
        private readonly CommitReservation $commitReservation,
        private readonly RecordInventoryFinalizationFailure $recordInventoryFinalizationFailure,
    ) {
    }

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

        $payment = $this->applyPaymentCapture->execute(
            $payment,
            $attempt,
            $amountMinor,
            $currency,
            $externalId,
            $metadata,
        );

        $this->recalculateOrderPaymentStatus->execute($payment->order);

        try {
            $this->completeOrderAfterCapture($payment);
        } catch (ReservationExpiredException|InventoryCommitException $e) {
            $this->recordInventoryFinalizationFailure->execute($payment, $attempt, $e->getMessage());

            throw $e;
        }

        return $payment->fresh();
    }

    /** @throws ReservationExpiredException|InventoryCommitException */
    public function completeOrderAfterCapture(Payment $payment): void
    {
        $payment->refresh();
        $order = $payment->order;

        if ($payment->status === PaymentStatus::Captured) {
            $this->commitReservation->executeForOrder($order);
            $this->confirmOrderOnPaymentAccepted->execute($order);

            // Exactly-once OrderPaid via a unique outbox row keyed on order.paid:{order_id}.
            // Two concurrent finalizers race on the unique constraint; the loser skips dispatch.
            // A crash after insert but before commit rolls the row back, so recovery re-dispatches.
            // The insert runs in its own savepoint so a unique violation on PostgreSQL doesn't
            // poison the surrounding transaction (PostgreSQL aborts the whole txn on any error).
            try {
                DB::transaction(fn () => OutboxMessage::query()->create([
                    'event' => 'order.paid',
                    'key' => "order.paid:{$order->id}",
                    'payload' => [
                        'order_id' => $order->id,
                        'order_public_id' => $order->public_id,
                        'payment_id' => $payment->id,
                    ],
                ]));

                Event::dispatch(new OrderPaid($order->id, $order->public_id, $payment->id));
            } catch (UniqueConstraintViolationException) {
                // Already dispatched by a concurrent finalizer or a prior recovery.
            }

            return;
        }

        if ($payment->status === PaymentStatus::PartiallyCaptured) {
            $this->confirmOrderOnPaymentAccepted->execute($order);
        }
    }
}
