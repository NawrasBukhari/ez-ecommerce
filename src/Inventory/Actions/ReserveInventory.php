<?php

namespace EzEcommerce\Inventory\Actions;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Catalog\Contracts\Stockable;
use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\ReservationStatus;
use EzEcommerce\Core\Events\InventoryReserved;
use EzEcommerce\Inventory\Contracts\InventoryAllocator;
use EzEcommerce\Inventory\Contracts\ReservationPolicy;
use EzEcommerce\Inventory\Data\InventoryContext;
use EzEcommerce\Inventory\Models\InventoryBalance;
use EzEcommerce\Inventory\Models\InventoryMovement;
use EzEcommerce\Inventory\Models\InventoryReservation;
use EzEcommerce\Orders\Models\Order;
use Illuminate\Support\Facades\Event;
use RuntimeException;

final class ReserveInventory
{
    public function __construct(
        private readonly InventoryAllocator $allocator,
        private readonly ReservationPolicy $reservationPolicy,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<InventoryReservation> */
    public function executeForCart(Cart $cart, Order $order, string $paymentMethod): array
    {
        $reservations = [];
        $context = new InventoryContext(cart: $cart, order: $order);
        $expiresAt = $this->reservationPolicy->expiresAt($order, $paymentMethod, $this->clock->now());

        foreach ($cart->items as $item) {
            $purchasable = $item->purchasable;
            if (! $purchasable instanceof Stockable) {
                continue;
            }

            $allocation = $this->allocator->allocate($purchasable, $item->quantity, $context);

            foreach ($allocation->allocations as $warehouseAllocation) {
                $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($warehouseAllocation->balanceId);
                $available = $balance->on_hand - $balance->reserved;

                if ($available < $warehouseAllocation->quantity) {
                    throw new RuntimeException('Insufficient inventory for '.$purchasable->stockIdentifier());
                }

                $balance->update(['reserved' => $balance->reserved + $warehouseAllocation->quantity]);

                InventoryMovement::query()->create([
                    'balance_id' => $balance->id,
                    'type' => 'reserve',
                    'on_hand_delta' => 0,
                    'reserved_delta' => $warehouseAllocation->quantity,
                    'reference_type' => 'commerce_order',
                    'reference_id' => $order->id,
                    'idempotency_scope' => 'inventory_reserve',
                    'idempotency_key' => "{$order->id}:{$balance->id}:{$item->id}",
                ]);

                $reservation = InventoryReservation::query()->create([
                    'cart_id' => $cart->id,
                    'order_id' => $order->id,
                    'balance_id' => $balance->id,
                    'quantity' => $warehouseAllocation->quantity,
                    'status' => ReservationStatus::Active,
                    'expires_at' => $expiresAt,
                ]);

                $reservations[] = $reservation;
                Event::dispatch(new InventoryReserved($reservation->id, $order->id, $balance->id));
            }
        }

        return $reservations;
    }
}
