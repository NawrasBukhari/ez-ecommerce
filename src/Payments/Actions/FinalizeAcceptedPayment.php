<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Jobs\ProcessOutboxJob;
use EzEcommerce\Core\Models\OutboxMessage;
use EzEcommerce\Inventory\Actions\CommitReservation;
use EzEcommerce\Inventory\Exceptions\InventoryCommitException;
use EzEcommerce\Inventory\Exceptions\ReservationExpiredException;
use EzEcommerce\Orders\Actions\ConfirmOrderOnPaymentAccepted;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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

    /**
     * Atomically commit inventory, confirm the order, and insert the unique
     * order.paid outbox row for a captured payment. The outbox event is never
     * dispatched synchronously from here; after the transaction commits, the
     * worker job is scheduled via DB::afterCommit. If enqueueing fails, the
     * durable outbox polling command (commerce:process-outbox) remains the
     * safety net, so the event is not lost.
     *
     * @throws ReservationExpiredException|InventoryCommitException
     */
    public function completeOrderAfterCapture(Payment $payment): void
    {
        $payment->refresh();
        $order = $payment->order;

        if (! in_array($payment->status, [PaymentStatus::Captured, PaymentStatus::PartiallyCaptured], true)) {
            return;
        }

        if ($payment->status === PaymentStatus::PartiallyCaptured) {
            // Partial capture confirms the order but does not commit inventory or
            // enqueue order.paid — those wait for a full capture.
            $this->confirmOrderOnPaymentAccepted->execute($order);

            return;
        }

        $outboxId = DB::transaction(function () use ($payment, $order) {
            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            $this->commitReservation->executeForOrder($lockedOrder);
            $this->confirmOrderOnPaymentAccepted->execute($lockedOrder);

            // Insert the outbox row in its own savepoint so a unique-key violation
            // (concurrent finalizer) does not poison the surrounding transaction on
            // PostgreSQL. The order confirmation still commits; the concurrent
            // finalizer owns the outbox row and its delivery.
            try {
                return DB::transaction(fn () => OutboxMessage::query()->create([
                    'event' => 'order.paid',
                    'key' => "order.paid:{$lockedOrder->id}",
                    'status' => 'pending',
                    'payload' => [
                        'order_id' => $lockedOrder->id,
                        'order_public_id' => $lockedOrder->public_id,
                        'payment_id' => $lockedPayment->id,
                    ],
                ])->id);
            } catch (UniqueConstraintViolationException) {
                // Already enqueued by a concurrent finalizer or a prior recovery.
                return null;
            }
        });

        if ($outboxId !== null) {
            // Schedule delivery after the transaction commits. Failure to enqueue
            // is non-fatal: commerce:process-outbox drains pending rows.
            DB::afterCommit(fn () => ProcessOutboxJob::dispatch($outboxId));
        }
    }
}
