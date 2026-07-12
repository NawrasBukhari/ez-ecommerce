<?php

namespace EzEcommerce\Inventory\Data;

final readonly class WarehouseAllocation
{
    public function __construct(
        public int $warehouseId,
        public int $balanceId,
        public int $quantity,
    ) {
    }
}
