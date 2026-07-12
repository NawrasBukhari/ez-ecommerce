<?php

namespace EzEcommerce\Inventory;

use EzEcommerce\Catalog\Contracts\Stockable;
use EzEcommerce\Inventory\Actions\ReceiveStock;
use EzEcommerce\Inventory\Actions\ReleaseExpiredReservations;
use EzEcommerce\Inventory\Models\InventoryBalance;
use EzEcommerce\Inventory\Models\Warehouse;

final class InventoryManager
{
    public function __construct(
        private readonly ReceiveStock $receiveStock,
        private readonly ReleaseExpiredReservations $releaseExpiredReservations,
    ) {
    }

    public function receiveStock(
        Warehouse $warehouse,
        Stockable $stockable,
        int $quantity,
        ?string $idempotencyKey = null,
    ): InventoryBalance {
        return $this->receiveStock->executeForStockable(
            $warehouse,
            $stockable,
            $quantity,
            $idempotencyKey ?? uniqid('receive_', true),
        );
    }

    public function releaseExpiredReservations(): int
    {
        return $this->releaseExpiredReservations->execute();
    }
}
