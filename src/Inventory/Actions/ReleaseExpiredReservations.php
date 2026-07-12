<?php

namespace EzEcommerce\Inventory\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\ReservationStatus;
use EzEcommerce\Inventory\Models\InventoryReservation;

final class ReleaseExpiredReservations
{
    public function __construct(
        private readonly Clock $clock,
        private readonly ReleaseInventoryReservation $releaseInventoryReservation,
    ) {}

    public function execute(): int
    {
        $released = 0;
        $now = $this->clock->now();

        InventoryReservation::query()
            ->where('status', ReservationStatus::Active)
            ->where('expires_at', '<', $now)
            ->with(['order.payments'])
            ->each(function (InventoryReservation $reservation) use (&$released): void {
                $order = $reservation->order;
                if ($order === null) {
                    return;
                }

                if ($order->status !== OrderStatus::PendingPayment) {
                    return;
                }

                $hasCapturedPayment = $order->payments
                    ->contains(fn ($p) => in_array($p->status, [PaymentStatus::Authorized, PaymentStatus::Captured, PaymentStatus::PartiallyCaptured], true));

                if ($hasCapturedPayment) {
                    return;
                }

                $this->releaseInventoryReservation->execute($reservation);
                $reservation->update(['status' => ReservationStatus::Expired]);
                $released++;
            });

        return $released;
    }
}
