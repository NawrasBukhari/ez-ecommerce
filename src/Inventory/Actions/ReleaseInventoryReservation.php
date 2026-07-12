<?php

namespace EzEcommerce\Inventory\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\ReservationStatus;
use EzEcommerce\Inventory\Models\InventoryBalance;
use EzEcommerce\Inventory\Models\InventoryMovement;
use EzEcommerce\Inventory\Models\InventoryReservation;

final class ReleaseInventoryReservation
{
    public function __construct(
        private readonly Clock $clock,
    ) {}

    public function execute(InventoryReservation $reservation): InventoryReservation
    {
        if ($reservation->status !== ReservationStatus::Active) {
            return $reservation;
        }

        $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($reservation->balance_id);
        $balance->update(['reserved' => max(0, $balance->reserved - $reservation->quantity)]);

        InventoryMovement::query()->create([
            'balance_id' => $balance->id,
            'type' => 'release',
            'on_hand_delta' => 0,
            'reserved_delta' => -$reservation->quantity,
            'reference_type' => $reservation->order_id ? 'commerce_order' : null,
            'reference_id' => $reservation->order_id,
            'idempotency_scope' => 'inventory_release',
            'idempotency_key' => "reservation:{$reservation->id}",
        ]);

        $reservation->update([
            'status' => ReservationStatus::Released,
            'released_at' => $this->clock->now(),
        ]);

        return $reservation->fresh();
    }
}
