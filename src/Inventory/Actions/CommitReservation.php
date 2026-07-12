<?php

namespace EzEcommerce\Inventory\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\ReservationStatus;
use EzEcommerce\Inventory\Exceptions\ReservationExpiredException;
use EzEcommerce\Inventory\Models\InventoryBalance;
use EzEcommerce\Inventory\Models\InventoryMovement;
use EzEcommerce\Inventory\Models\InventoryReservation;
use EzEcommerce\Orders\Models\Order;

final class CommitReservation
{
    public function __construct(
        private readonly Clock $clock,
    ) {}

    public function execute(InventoryReservation $reservation): InventoryReservation
    {
        if ($reservation->status !== ReservationStatus::Active) {
            return $reservation;
        }

        if ($reservation->expires_at !== null && $reservation->expires_at < $this->clock->now()) {
            throw ReservationExpiredException::forReservation($reservation->id);
        }

        $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($reservation->balance_id);

        $balance->update([
            'reserved' => max(0, $balance->reserved - $reservation->quantity),
            'on_hand' => max(0, $balance->on_hand - $reservation->quantity),
        ]);

        InventoryMovement::query()->create([
            'balance_id' => $balance->id,
            'type' => 'commit',
            'on_hand_delta' => -$reservation->quantity,
            'reserved_delta' => -$reservation->quantity,
            'reference_type' => $reservation->order_id ? 'commerce_order' : null,
            'reference_id' => $reservation->order_id,
            'idempotency_scope' => 'inventory_commit',
            'idempotency_key' => "reservation:{$reservation->id}",
        ]);

        $reservation->update(['status' => ReservationStatus::Committed]);

        return $reservation->fresh();
    }

    public function executeForOrder(Order $order): void
    {
        $order->reservations()
            ->where('status', ReservationStatus::Active)
            ->each(fn (InventoryReservation $r) => $this->execute($r));
    }
}
