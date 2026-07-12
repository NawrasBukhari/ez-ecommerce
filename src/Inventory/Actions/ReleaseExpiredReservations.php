<?php

namespace EzEcommerce\Inventory\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\ReservationStatus;
use EzEcommerce\Inventory\Models\InventoryBalance;
use EzEcommerce\Inventory\Models\InventoryReservation;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;

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
            ->orderBy('id')
            ->each(function (InventoryReservation $reservation) use (&$released): void {
                DB::transaction(function () use ($reservation, &$released): void {
                    $reservation = InventoryReservation::query()
                        ->lockForUpdate()
                        ->find($reservation->id);

                    if ($reservation === null || $reservation->status !== ReservationStatus::Active) {
                        return;
                    }

                    $order = $reservation->order_id !== null
                        ? Order::query()->lockForUpdate()->find($reservation->order_id)
                        : null;

                    if ($order === null || $order->status !== OrderStatus::PendingPayment) {
                        return;
                    }

                    $hasCapturedPayment = Payment::query()
                        ->where('order_id', $order->id)
                        ->lockForUpdate()
                        ->get()
                        ->contains(fn ($p) => in_array($p->status, [PaymentStatus::Authorized, PaymentStatus::Captured, PaymentStatus::PartiallyCaptured], true));

                    if ($hasCapturedPayment) {
                        return;
                    }

                    InventoryBalance::query()->lockForUpdate()->find($reservation->balance_id);

                    $this->releaseInventoryReservation->execute($reservation);
                    $reservation->update(['status' => ReservationStatus::Expired]);
                    $released++;
                });
            });

        return $released;
    }
}
