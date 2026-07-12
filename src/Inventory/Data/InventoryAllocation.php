<?php

namespace EzEcommerce\Inventory\Data;

final readonly class InventoryAllocation
{
    /** @param  list<WarehouseAllocation>  $allocations */
    public function __construct(
        public array $allocations,
    ) {}

    public function totalQuantity(): int
    {
        return array_sum(array_map(fn (WarehouseAllocation $a) => $a->quantity, $this->allocations));
    }
}
