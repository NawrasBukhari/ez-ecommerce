<?php

namespace EzEcommerce\Inventory\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\ReservationStatus;
use EzEcommerce\Inventory\Exceptions\InventoryCommitException;
use EzEcommerce\Inventory\Exceptions\ReservationExpiredException;
use EzEcommerce\Inventory\Models\InventoryBalance;
use EzEcommerce\Inventory\Models\InventoryMovement;
use EzEcommerce\Inventory\Models\InventoryReservation;
use EzEcommerce\Orders\Models\Order;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CommitReservation
{
    public function __construct(
        private readonly Clock $clock,
    ) {}

    public function execute(InventoryReservation $reservation): InventoryReservation
    {
        return DB::transaction(function () use ($reservation) {
            $locked = InventoryReservation::query()
                ->lockForUpdate()
                ->findOrFail($reservation->id);

            $this->commitLocked($locked);

            return $locked->fresh();
        });
    }

    public function executeForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $reservations = InventoryReservation::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->get();

            $uncommitted = $reservations->filter(
                fn (InventoryReservation $reservation) => $reservation->status !== ReservationStatus::Committed,
            );

            if ($uncommitted->isEmpty()) {
                return;
            }

            foreach ($uncommitted as $reservation) {
                if ($reservation->status === ReservationStatus::Expired) {
                    throw ReservationExpiredException::forReservation($reservation->id);
                }

                if ($reservation->status === ReservationStatus::Released) {
                    throw InventoryCommitException::reservationsReleased($order->id);
                }

                if ($reservation->status !== ReservationStatus::Active) {
                    throw InventoryCommitException::uncommittedReservationsUnavailable($order->id);
                }

                if ($reservation->expires_at !== null && $reservation->expires_at < $this->clock->now()) {
                    throw ReservationExpiredException::forReservation($reservation->id);
                }
            }

            foreach ($uncommitted as $reservation) {
                $this->commitLocked($reservation);
            }
        });
    }

    private function commitLocked(InventoryReservation $locked): void
    {
        if ($locked->status !== ReservationStatus::Active) {
            return;
        }

        if ($locked->expires_at !== null && $locked->expires_at < $this->clock->now()) {
            throw ReservationExpiredException::forReservation($locked->id);
        }

        $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($locked->balance_id);

        if ($balance->reserved < $locked->quantity) {
            throw new InvalidArgumentException(
                "Balance [{$balance->id}] reserved [{$balance->reserved}] is less than reservation quantity [{$locked->quantity}].",
            );
        }

        if ($balance->on_hand < $locked->quantity) {
            throw new InvalidArgumentException(
                "Balance [{$balance->id}] on_hand [{$balance->on_hand}] is less than reservation quantity [{$locked->quantity}].",
            );
        }

        $balance->update([
            'reserved' => $balance->reserved - $locked->quantity,
            'on_hand' => $balance->on_hand - $locked->quantity,
        ]);

        InventoryMovement::query()->create([
            'balance_id' => $balance->id,
            'type' => 'commit',
            'on_hand_delta' => -$locked->quantity,
            'reserved_delta' => -$locked->quantity,
            'reference_type' => $locked->order_id ? 'commerce_order' : null,
            'reference_id' => $locked->order_id,
            'idempotency_scope' => 'inventory_commit',
            'idempotency_key' => "reservation:{$locked->id}",
        ]);

        $locked->update(['status' => ReservationStatus::Committed]);
    }
}
